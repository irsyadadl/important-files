<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\User;
use App\Models\Article;
use App\Models\Category;
use App\Models\Threshold;
use Illuminate\Http\Request;
use App\Concerns\HandlingImageOnEditor;
use App\Http\Resources\ArticleResource;
use App\Http\Resources\CommentResource;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\ArticleTableResource;
use App\Http\Resources\ArticleSingleResource;
use App\Http\Resources\LatestArticleResource;
use App\Http\Resources\RelatedArticleResource;
use App\Http\Resources\FavoriteArticleResource;

class ArticleController extends Controller
{
    use HandlingImageOnEditor;
    protected $categories;
    protected $tags;
    public function __construct()
    {
        $this->middleware(['role:super admin'])->only(['approve', 'paid', 'undoPaid']);
        $this->middleware(['auth', 'has.verified'])->except(['show', 'ogImage', 'index']);
        $this->categories = Category::get()->map(fn ($q) => [
            'value' => $q->id,
            'label' => $q->name,
        ]);
        $this->tags = Tag::get()->map(fn ($q) => [
            'value' => $q->id,
            'label' => $q->name,
        ]);
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $latestArticle = Article::query()
            ->withCount(['comments', 'likes', 'views'])
            ->whereNotNull('published_at')
            ->when($request->category, fn ($q, $value) => $q->whereBelongsTo(Category::where('slug', $value)->first()))
            ->when(
                $request->filter,
                function ($q, $value) {
                    match ($value) {
                        'popular' => $q->withCount('views')->orderBy('views_count', 'desc'),
                        'oldest' => $q->oldest(),
                        'popular-of-week' => $q->withCount([
                            'views' => fn ($fq) => $fq->whereBetween('published_at', [now()->subDays(7), now()])
                        ])->orderByDesc('views_count'),
                        'popular-of-month' => $q->withCount([
                            'views' => fn ($fq) => $fq->whereBetween('published_at', [now()->subDays(30), now()])
                        ])->orderByDesc('views_count'),
                        default => $q,
                    };
                }
            )
            ->latest()
            ->first();
        $articles = Article::query()
            ->when($latestArticle, fn ($q) => $q->where('id', '!=', $latestArticle->id))
            ->withCount(['likes', 'comments', 'views'])
            ->whereNotNull('published_at')
            ->with('category')
            ->when($request->category, fn ($q, $value) => $q->whereBelongsTo(Category::where('slug', $value)->first()))
            ->when($request->filter, function ($q, $value) {
                match ($value) {
                    'popular' => $q->withCount('views')->orderBy('views_count', 'desc'),
                    'oldest' => $q->oldest(),
                    'popular-of-week' => $q->withCount([
                        'views' => fn ($fq) => $fq->whereBetween('published_at', [now()->subDays(7), now()])
                    ])->orderByDesc('views_count'),
                    'popular-of-month' => $q->withCount([
                        'views' => fn ($fq) => $fq->whereBetween('published_at', [now()->subDays(30), now()])
                    ])->orderByDesc('views_count'),
                    default => $q,
                };
            });
        return inertia('Articles/Index', [
            'articles' => ArticleResource::collection($articles->latest()->paginate(9)),
            'filter' => $request->filter,
            'latest_article' => $latestArticle ? new LatestArticleResource($latestArticle) : null,
        ]);
    }

    public function table(Request $request)
    {
        $articles = Article::query()
            ->with('category')
            ->withCount('views', 'likes')
            ->when(!auth()->user()->hasRole('super admin'), fn ($q) => $q->whereBelongsTo(auth()->user(), 'author'))
            ->when(
                $request->search,
                function ($q, $value) {
                    if ($value === 'unpublished') {
                        return $q->whereNull('published_at');
                    }
                    return $q->search('title', $value)->orWhereRelation('category', 'name', 'like', "%{$value}%");
                }
            )
            ->when($request->users, function ($q, $value) use ($request) {
                if ($request->user()->hasRole('super admin')) {
                    if ($value > 0) {
                        return $q->whereBelongsTo(User::find($value), 'author');
                    }
                    return $q;
                }
                return $q;
            })
            ->latest()
            ->paginate(10)
            ->onEachSide(1)
            ->withQueryString();

        $users = User::whereHas('articles')->get()->map(fn ($q) => [
            'value' => $q->id,
            'label' => $q->name,
        ]);

        $paidRevenue = Article::query()
            ->whereNotNull('paid_at')
            ->when($request->users, function ($q, $value) use ($request) {
                if ($request->user()->hasRole('super admin')) {
                    $user = User::find($value);
                    if ($user) {
                        return $q->whereBelongsTo($user, 'author');
                    }
                    return $q;
                }
                return $q;
            })
            ->when(!$request->user()->hasRole('super admin'), fn ($q) => $q->whereBelongsTo(auth()->user(), 'author'))
            ->get()
            ->sum('price');
        $unpaidRevenue = Article::query()
            ->whereNull('paid_at')
            ->when($request->users, function ($q, $value) use ($request) {
                if ($request->user()->hasRole('super admin')) {
                    $user = User::find($value);
                    if ($user) {
                        return $q->whereBelongsTo($user, 'author');
                    }
                    return $q;
                }
                return $q;
            })
            ->when(!$request->user()->hasRole('super admin'), fn ($q) => $q->whereBelongsTo(auth()->user(), 'author'))
            ->get()
            ->sum('price');

        return inertia('Articles/Table', [
            'articles' => ArticleTableResource::collection($articles),
            'filters' => $request->only(['search', 'page', 'users']),
            'users' => $users,
            'price' => [
                'current_page' => $articles->whereNull('paid_at')->sum('price'),
                'paid' => $paidRevenue,
                'unpaid' => $unpaidRevenue,
            ],
            'threshold' => cache()->rememberForever('threshold', fn () => Threshold::value('value')),
        ]);
    }

    public function paid(Article $article)
    {
        $article->paid_at = now();
        $article->save();
        return back();
    }

    public function undoPaid(Article $article)
    {
        $article->paid_at = null;
        $article->save();
        return back();
    }

    public function create()
    {
        return inertia('Articles/Create', [
            'categories' => $this->categories,
            'tags' => $this->tags,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => ['required'],
            'category' => ['required', 'exists:categories,id'],
            'body' => ['required'],
            'picture' => ['sometimes', 'image', 'mimes:png,jpg,jpeg'],
        ]);

        $article = auth()->user()->articles()->create([
            'category_id' => $request->category,
            'title' => $request->title,
            'picture' => $request->file('picture') ? $request->file('picture')->store($this->path()) : null,
            'body' => 'temporary',
            'published_at' => $request->published_at ? now() : null,
        ]);

        $article->body = $this->domDocumentForImage("body", $this->path($article->id));
        $article->save();

        $article->tags()->sync($request->collect('tags')->pluck('value'));

        // if (!$request->file('picture')) {
        //     dispatch(new CreateArticleOgImageJob($article));
        // }

        cache()->flush();
        return redirect('/articles/table');
    }

    public function favorites(Request $request)
    {
        $articles = $request->user()
            ->likes()
            ->with('likeable')
            ->where('likeable_type', Article::class);
        return inertia('Articles/Favorites', [
            'articles' => FavoriteArticleResource::collection($articles->latest()->paginate(10)->onEachSide(1)),
        ]);
    }

    public function like(Request $request, Article $article)
    {
        $article->toggleLike($request);
        return back();
    }

    public function approve(Request $request, Article $article)
    {
        $article->price = $request->price ?? 0;
        $article->published_at = now();
        $article->save();

        cache()->forget('user_reach');
        return back();
    }

    public function setMultiplePaidStatus(Request $request)
    {
        $articles = Article::where('price', '!=', 0)->find($request->checkedArticles);
        $articles->each(fn ($q) => $q->update(['paid_at' => now()]));
        return back();
    }


    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Article  $article
     * @return \Illuminate\Http\Response
     */
    public function show(Article $article)
    {
        $this->authorize('view', $article);
        $article->views()->create([]);
        return inertia('Articles/Show', [
            'related_articles' => RelatedArticleResource::collection(
                Article::query()
                    ->whereNotNull('published_at')
                    ->with('category')
                    ->whereBelongsTo($article->category)
                    ->where('id', '!=', $article->id)
                    ->latest()
                    ->take(10)
                    ->get(),
            ),
            'article' => ArticleSingleResource::make($article->load('category')->loadCount(['likes', 'comments', 'views'])),
            'comments' => CommentResource::collection($article->comments()->with('author', 'children.author')->get()),
        ]);
    }

    public function ogImage(Article $article)
    {
        return inertia('Articles/OgImage', [
            'article' => $article,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Article  $article
     * @return \Illuminate\Http\Response
     */
    public function edit(Article $article)
    {
        return inertia('Articles/Edit', [
            'article' => $article->load('category', 'tags'),
            'categories' => $this->categories,
            'tags' => $this->tags,
        ]);
    }

    public function update(Request $request, Article $article)
    {
        $request->validate([
            'title' => ['required'],
            'category' => ['required', 'exists:categories,id'],
            'body' => ['required'],
            'picture' => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
        ]);

        if ($request->file('picture')) {
            if ($article->picture) {
                Storage::delete($article->picture);
            }
            $picture = $request->file('picture')->store($this->path());
        } else {
            $picture = $article->picture;
        }

        $this->removeImageFromDomImage($this->path($article->id));

        $article->update([
            'category_id' => $request->category,
            'title' => $request->title,
            'picture' => $picture,
            'body' => $this->domDocumentForImage("body", $this->path($article->id), $article),
            'published_at' => $request->published_at ? now() : null,
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'updated_at' => now(),
        ]);
        $article->tags()->sync($request->collect('tags')->pluck('value'));
        cache()->flush();
        return redirect("/articles/{$article->slug}");
    }

    public function path($identifier = null)
    {
        if ($identifier) {
            return "images/articles/" . $identifier;
        }
        return "images/articles";
    }

    public function destroy(Request $request, Article $article)
    {
        $this->removeImageFromDomImage($this->path($article->id));
        if ($request->query('permanently')) {
            if ($article->picture) {
                Storage::delete($article->picture);
            }
            $article->forceDelete();
        } else {
            $article->delete();
        }
        return back();
    }
}

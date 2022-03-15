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
    public function store(Request $request)
    {
        $article = auth()->user()->articles()->create([
            'title' => $request->title,
            'picture' => $request->file('picture') ? $request->file('picture')->store($this->path()) : null,
            'body' => 'temporary',
            'published_at' => $request->published_at ? now() : null,
        ]);

        $article->body = $this->domDocumentForImage("body", $this->path($article->id));
        $article->save();

        // if (!$request->file('picture')) {
        //     dispatch(new CreateArticleOgImageJob($article));
        // }

    }

    public function update(Request $request, Article $article)
    {
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
            'body' => $this->domDocumentForImage("body", $this->path($article->id), $article),
        ]);
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

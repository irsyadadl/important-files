<?php

namespace App\Concerns;

use DOMDocument;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\File;

trait HandlingImageOnEditor
{
    public function domDocumentForImage($field, $path, $data = null)
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(request($field), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $images = $dom->getElementsByTagName('img');
        foreach ($images as $i) {
            $src = $i->getAttribute('src');
            if (strpos($src, 'data:image') > -1 && strpos($src, 'base64') > -1) {
                $hellBase64 = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $src));
                $temporaryImagePath = sys_get_temp_dir() . '/' . Str::uuid()->toString();
                file_put_contents($temporaryImagePath, $hellBase64);
                $temporaryImage = new File($temporaryImagePath);
                $makeTheImageToBeRequest = new UploadedFile($temporaryImage->getPathname(), $temporaryImage->getFilename(), $temporaryImage->getMimeType(), 0, true);
                $fileName = $makeTheImageToBeRequest->store($path);
                $i->setAttribute('src', "/storage/$fileName");
                Storage::delete($temporaryImage);
            }
        }

        if ($data) {
            $oldContent = new DOMDocument();
            $oldContent->loadHTML($data->$field);
            foreach ($oldContent->getElementsByTagName('img') as $oldImg) {
                $oldSrc = $oldImg->getAttribute('src');
                if (strpos(request($field), $oldSrc) > -1) {
                    1;
                } elseif (Storage::disk('public')->exists($oldSrc)) {
                    Storage::disk('public')->delete($oldSrc);
                }
            }
        }
        return $dom->saveHTML();
    }

    public function removeImageFromDomImage($path)
    {
        Storage::deleteDirectory($path);
        // $dom = new \DOMDocument;
        // libxml_use_internal_errors(true);
        // $dom->loadHTML($fieldBody, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        // $images = $dom->getElementsByTagName('img');
        // foreach ($images as $key => $img) {
        //     Storage::delete($path);
        // }
    }
}

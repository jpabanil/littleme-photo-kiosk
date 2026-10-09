<?php

namespace App\Http\Controllers;

use App\Services\Storage\ObjectStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class MediaController extends Controller
{
    public function __invoke(Request $request, ObjectStore $store): Response
    {
        $object = (string) $request->query('object', '');

        try {
            $bytes = $store->read($object);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        if ($bytes === null) {
            abort(404);
        }

        $mime = match (true) {
            str_ends_with($object, '.png') => 'image/png',
            str_ends_with($object, '.webp') => 'image/webp',
            default => 'image/jpeg',
        };
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $disposition = ($request->query('download') === '1' ? 'attachment' : 'inline')
            .'; filename="little-me.'.$extension.'"';

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

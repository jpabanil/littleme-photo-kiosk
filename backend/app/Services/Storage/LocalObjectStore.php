<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\URL;

final class LocalObjectStore implements ObjectStore
{
    public function put(string $path, string $bytes, string $mime): void
    {
        $absolute = $this->absolute($path);
        $directory = dirname($absolute);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create storage directory');
        }

        if (file_put_contents($absolute, $bytes) === false) {
            throw new \RuntimeException('Could not store output image');
        }
    }

    public function signedUrl(string $path, int $ttlMinutes, bool $download = false): string
    {
        $this->absolute($path);

        return URL::temporarySignedRoute('media.local', now()->addMinutes($ttlMinutes), [
            'object' => $path,
            'download' => $download ? '1' : '0',
        ]);
    }

    public function read(string $path): ?string
    {
        $absolute = $this->absolute($path);
        if (! is_file($absolute)) {
            return null;
        }

        $bytes = file_get_contents($absolute);

        return is_string($bytes) ? $bytes : null;
    }

    private function absolute(string $path): string
    {
        if (! preg_match('#^outputs/[A-Za-z0-9][A-Za-z0-9._-]{0,120}$#', $path)) {
            throw new \InvalidArgumentException('Invalid object path');
        }

        return storage_path('app/private/'.$path);
    }
}

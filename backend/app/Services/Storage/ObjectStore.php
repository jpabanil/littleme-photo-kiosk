<?php

namespace App\Services\Storage;

interface ObjectStore
{
    public function put(string $path, string $bytes, string $mime): void;

    public function signedUrl(string $path, int $ttlMinutes, bool $download = false): string;

    public function read(string $path): ?string;
}

<?php

namespace App\Services\Images;

final class GeneratedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
    ) {}
}

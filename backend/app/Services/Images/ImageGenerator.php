<?php

namespace App\Services\Images;

interface ImageGenerator
{
    /**
     * @param  string  $photo  raw image bytes, kept in memory only for this call
     */
    public function generate(string $photo, string $mime, string $prompt): GeneratedImage;
}

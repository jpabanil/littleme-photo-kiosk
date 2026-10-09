<?php

namespace App\Services\Images;

final class FakeGenerator implements ImageGenerator
{
    public function generate(string $photo, string $mime, string $prompt): GeneratedImage
    {
        $delay = (int) config('littleme.fake_delay_ms');
        if ($delay > 0) {
            usleep($delay * 1000);
        }

        return new GeneratedImage($this->placeholder(), 'image/jpeg');
    }

    private function placeholder(): string
    {
        $image = imagecreatetruecolor(768, 1024);
        if ($image === false) {
            throw new GenerationFailed('empty_image');
        }

        $top = imagecolorallocate($image, 244, 214, 196);
        $bottom = imagecolorallocate($image, 196, 214, 204);
        for ($y = 0; $y < 1024; $y++) {
            $color = $y < 512 ? $top : $bottom;
            imageline($image, 0, $y, 768, $y, $color);
        }

        $skin = imagecolorallocate($image, 232, 186, 160);
        $ink = imagecolorallocate($image, 56, 42, 36);
        imagefilledellipse($image, 384, 430, 280, 340, $skin);
        imagefilledellipse($image, 330, 410, 28, 28, $ink);
        imagefilledellipse($image, 438, 410, 28, 28, $ink);
        imagearc($image, 384, 490, 90, 50, 20, 160, $ink);

        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);
        $bytes = ob_get_clean();

        if (! is_string($bytes) || $bytes === '') {
            throw new GenerationFailed('empty_image');
        }

        return $bytes;
    }
}

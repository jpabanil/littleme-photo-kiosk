<?php

namespace App\Services\Images;

final class PromptBuilder
{
    public function randomTemplateKey(): string
    {
        $keys = array_keys(config('templates.templates'));
        if ($keys === []) {
            throw new GenerationFailed('config');
        }

        return $keys[random_int(0, count($keys) - 1)];
    }

    public function randomAge(): int
    {
        return random_int(3, 10);
    }

    public function make(string $templateKey, int $age, string $gender): string
    {
        $templates = config('templates.templates');
        if (! isset($templates[$templateKey]['prompt'])) {
            throw new GenerationFailed('config');
        }

        return strtr(config('templates.base').' '.$templates[$templateKey]['prompt'], [
            '{age}' => (string) $age,
            '{gender_word}' => $gender,
        ]);
    }
}

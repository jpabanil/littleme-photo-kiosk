<?php

namespace Tests\Unit;

use App\Services\Images\PromptBuilder;
use Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    public function test_prompt_includes_age_and_gender(): void
    {
        $prompt = (new PromptBuilder)->make('studio', 7, 'girl');

        $this->assertStringContainsString('7-year-old girl', $prompt);
        $this->assertStringContainsString('classic school-portrait studio', $prompt);
        $this->assertStringNotContainsString('{age}', $prompt);
        $this->assertStringNotContainsString('{gender_word}', $prompt);
    }
}

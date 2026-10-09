<?php

namespace Tests\Unit;

use App\Services\Images\GenerationFailed;
use App\Services\Images\VertexGeminiGenerator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VertexGeminiGeneratorTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function generator(): VertexGeminiGenerator
    {
        config([
            'littleme.gcp_project' => 'demo-project',
            'littleme.gemini_location' => 'global',
            'littleme.gemini_model' => 'gemini-nano-banana-2.1',
            'littleme.gen_timeout_seconds' => 5,
            'littleme.gen_max_retries' => 1,
        ]);

        return new VertexGeminiGenerator(fn () => 'test-token');
    }

    public function test_retries_once_then_returns_the_image(): void
    {
        Http::fake([
            'aiplatform.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['status' => 'UNAVAILABLE']], 503)
                ->push([
                    'candidates' => [[
                        'finishReason' => 'STOP',
                        'content' => ['parts' => [[
                            'inlineData' => ['mimeType' => 'image/png', 'data' => self::PNG],
                        ]]],
                    ]],
                ], 200),
        ]);

        $image = $this->generator()->generate('selfie-bytes', 'image/jpeg', 'prompt');

        $this->assertSame('image/png', $image->mime);
        $this->assertNotSame('', $image->bytes);
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/locations/global/')
                && str_contains($request->url(), 'gemini-nano-banana-2.1:generateContent');
        });
    }

    public function test_safety_block_is_not_retried(): void
    {
        Http::fake([
            'aiplatform.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'SAFETY',
                    'content' => ['parts' => []],
                ]],
            ], 200),
        ]);

        try {
            $this->generator()->generate('selfie-bytes', 'image/jpeg', 'prompt');
            $this->fail('Expected a safety block');
        } catch (GenerationFailed $e) {
            $this->assertSame('safety_block', $e->errorCode);
            $this->assertFalse($e->transient);
        }

        Http::assertSentCount(1);
    }

    public function test_missing_image_fails(): void
    {
        Http::fake([
            'aiplatform.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => 'no image']]],
                ]],
            ], 200),
        ]);

        $this->expectException(GenerationFailed::class);
        $this->generator()->generate('selfie-bytes', 'image/jpeg', 'prompt');
    }
}

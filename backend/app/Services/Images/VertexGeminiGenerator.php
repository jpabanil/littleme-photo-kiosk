<?php

namespace App\Services\Images;

use Closure;
use Google\Auth\ApplicationDefaultCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class VertexGeminiGenerator implements ImageGenerator
{
    /**
     * @param  (Closure(): string)|null  $tokenResolver
     */
    public function __construct(private ?Closure $tokenResolver = null) {}

    public function generate(string $photo, string $mime, string $prompt): GeneratedImage
    {
        $tries = 1 + max(0, (int) config('littleme.gen_max_retries'));
        $last = null;

        for ($attempt = 1; $attempt <= $tries; $attempt++) {
            try {
                return $this->once($photo, $mime, $prompt);
            } catch (GenerationFailed $e) {
                $last = $e;
                if (! $e->transient || $attempt === $tries) {
                    throw $e;
                }
            }
        }

        throw $last ?? new GenerationFailed('upstream');
    }

    private function once(string $photo, string $mime, string $prompt): GeneratedImage
    {
        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->timeout((int) config('littleme.gen_timeout_seconds'))
                ->connectTimeout(10)
                ->post($this->endpoint(), $this->payload($photo, $mime, $prompt));
        } catch (ConnectionException) {
            throw new GenerationFailed('timeout', transient: true);
        } catch (GenerationFailed $e) {
            throw $e;
        } catch (Throwable) {
            throw new GenerationFailed('upstream', transient: true);
        }

        $status = $response->status();
        if (in_array($status, [408, 429, 500, 502, 503, 504], true)) {
            throw new GenerationFailed('upstream', transient: true);
        }

        if ($status === 401 || $status === 403) {
            throw new GenerationFailed('config');
        }

        if (! $response->successful()) {
            throw new GenerationFailed('upstream');
        }

        return $this->imageFrom($response->json());
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function imageFrom(?array $json): GeneratedImage
    {
        $block = $json['promptFeedback']['blockReason'] ?? null;
        if (is_string($block) && $block !== '') {
            throw new GenerationFailed('safety_block');
        }

        $candidate = $json['candidates'][0] ?? null;
        if (! is_array($candidate)) {
            throw new GenerationFailed('empty_image');
        }

        $finish = $candidate['finishReason'] ?? '';
        if (in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'IMAGE_SAFETY', 'SPII', 'RECITATION'], true)) {
            throw new GenerationFailed('safety_block');
        }

        $parts = $candidate['content']['parts'] ?? [];
        if (! is_array($parts)) {
            throw new GenerationFailed('empty_image');
        }

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (! is_array($inline) || ! isset($inline['data']) || ! is_string($inline['data'])) {
                continue;
            }
            $bytes = base64_decode($inline['data'], true);
            $mime = $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png';
            if (! is_string($bytes) || $bytes === '' || ! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                continue;
            }

            return new GeneratedImage($bytes, $mime);
        }

        throw new GenerationFailed('empty_image');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $photo, string $mime, string $prompt): array
    {
        return [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inlineData' => [
                        'mimeType' => $mime,
                        'data' => base64_encode($photo),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => '3:4',
                    'imageSize' => '1K',
                ],
            ],
        ];
    }

    private function endpoint(): string
    {
        $project = (string) config('littleme.gcp_project');
        $location = (string) config('littleme.gemini_location');
        $model = (string) config('littleme.gemini_model');
        if ($project === '' || $location === '' || $model === '') {
            throw new GenerationFailed('config');
        }

        // `global` is not a regional host. Every other location uses the regional host.
        $host = $location === 'global'
            ? 'https://aiplatform.googleapis.com'
            : 'https://'.$location.'-aiplatform.googleapis.com';

        return $host.'/v1/projects/'.rawurlencode($project)
            .'/locations/'.rawurlencode($location)
            .'/publishers/google/models/'.rawurlencode($model)
            .':generateContent';
    }

    private function accessToken(): string
    {
        if ($this->tokenResolver instanceof Closure) {
            return ($this->tokenResolver)();
        }

        try {
            $credentials = ApplicationDefaultCredentials::getCredentials([
                'https://www.googleapis.com/auth/cloud-platform',
            ]);
            $token = $credentials->fetchAuthToken();
        } catch (Throwable) {
            throw new GenerationFailed('config');
        }

        $access = $token['access_token'] ?? null;
        if (! is_string($access) || $access === '') {
            throw new GenerationFailed('config');
        }

        return $access;
    }
}

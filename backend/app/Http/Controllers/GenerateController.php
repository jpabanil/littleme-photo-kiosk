<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\Generation;
use App\Services\Images\GenerationFailed;
use App\Services\Images\ImageGenerator;
use App\Services\Images\PromptBuilder;
use App\Services\Storage\ObjectStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class GenerateController extends Controller
{
    public function __invoke(Request $request, ImageGenerator $generator, ObjectStore $store, PromptBuilder $prompts): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'consent' => ['required', 'accepted'],
            'gender' => ['required', 'in:boy,girl'],
            'photo' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'validation_failed',
                'message' => $this->validationMessage($validator->errors()->keys()),
            ], 422);
        }

        $file = $request->file('photo');
        $uploadPath = $file?->getRealPath();
        $photo = is_string($uploadPath) ? file_get_contents($uploadPath) : false;
        $mime = $file?->getMimeType() ?: 'image/jpeg';
        if (is_string($uploadPath) && is_file($uploadPath)) {
            unlink($uploadPath);
        }

        if (! is_string($photo) || $photo === '') {
            return response()->json([
                'error' => 'validation_failed',
                'message' => 'Use a JPEG, PNG, or WebP photo under 6 MB.',
            ], 422);
        }

        $started = hrtime(true);
        $gender = (string) $request->input('gender');
        $templateKey = $prompts->randomTemplateKey();
        $age = $prompts->randomAge();
        $expiresAt = now()->addHours((int) config('littleme.retention_hours'));
        $generation = null;

        try {
            $generation = Generation::query()->create([
                'gender' => $gender,
                'template_key' => $templateKey,
                'age' => $age,
                'status' => 'processing',
                'share_token' => Str::random(40),
                'expires_at' => $expiresAt,
            ]);

            Consent::query()->create([
                'generation_id' => (string) $generation->getKey(),
                'consent_version' => (string) config('littleme.consent_version'),
                'accepted_at' => now(),
                'locale' => 'en',
                'expires_at' => $expiresAt,
            ]);

            $image = $generator->generate($photo, $mime, $prompts->make($templateKey, $age, $gender));
            $extension = $this->extension($image->mime);
            $path = 'outputs/'.$generation->getKey().'.'.$extension;
            $store->put($path, $image->bytes, $image->mime);
            $duration = $this->elapsed($started);

            $generation->update([
                'status' => 'done',
                'output_path' => $path,
                'duration_ms' => $duration,
                'error_code' => null,
            ]);

            Log::info('generation.finished', [
                'id' => (string) $generation->getKey(),
                'status' => 'done',
                'duration_ms' => $duration,
                'template_key' => $templateKey,
            ]);

            $ttl = (int) config('littleme.signed_url_ttl');

            return response()->json([
                'id' => (string) $generation->getKey(),
                'image_url' => $store->signedUrl($path, $ttl, false),
                'share_url' => url('/s/'.$generation->share_token),
                'duration_ms' => $duration,
            ]);
        } catch (GenerationFailed $e) {
            return $this->fail($generation ?? null, $e->errorCode, $started);
        } catch (Throwable) {
            return $this->fail($generation ?? null, 'upstream', $started);
        } finally {
            $photo = null;
        }
    }

    private function fail(?Generation $generation, string $errorCode, int $started): JsonResponse
    {
        $duration = $this->elapsed($started);
        if ($generation instanceof Generation) {
            $generation->update([
                'status' => 'failed',
                'error_code' => $errorCode,
                'duration_ms' => $duration,
            ]);
            Log::warning('generation.failed', [
                'id' => (string) $generation->getKey(),
                'error_code' => $errorCode,
                'duration_ms' => $duration,
            ]);
        } else {
            Log::warning('generation.failed', [
                'error_code' => $errorCode,
                'duration_ms' => $duration,
            ]);
        }

        return response()->json([
            'error' => 'generation_failed',
            'message' => 'We could not create your image. Please try again.',
        ], 502);
    }

    /**
     * @param  list<string>  $fields
     */
    private function validationMessage(array $fields): string
    {
        if (in_array('consent', $fields, true)) {
            return 'Please agree to continue.';
        }
        if (in_array('gender', $fields, true)) {
            return 'Choose boy or girl.';
        }

        return 'Use a JPEG, PNG, or WebP photo under 6 MB.';
    }

    private function extension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}

<?php

namespace App\Providers;

use App\Services\Images\FakeGenerator;
use App\Services\Images\ImageGenerator;
use App\Services\Images\VertexGeminiGenerator;
use App\Services\Storage\GcsObjectStore;
use App\Services\Storage\LocalObjectStore;
use App\Services\Storage\ObjectStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ImageGenerator::class, function ($app) {
            return config('littleme.generator') === 'vertex'
                ? $app->make(VertexGeminiGenerator::class)
                : $app->make(FakeGenerator::class);
        });

        $this->app->bind(ObjectStore::class, function ($app) {
            return config('littleme.storage_driver') === 'gcs'
                ? $app->make(GcsObjectStore::class)
                : $app->make(LocalObjectStore::class);
        });
    }

    public function boot(): void
    {
        RateLimiter::for('generate', function (Request $request) {
            return Limit::perMinute(max(1, (int) config('littleme.rate_limit_per_minute')))
                ->by((string) ($request->ip() ?: 'unknown'))
                ->response(function () {
                    return response()->json([
                        'error' => 'too_many_requests',
                        'message' => 'Please wait a moment and try again.',
                    ], 429);
                });
        });
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\Storage\ObjectStore;
use Illuminate\Contracts\View\View;

class ShareController extends Controller
{
    public function __invoke(string $shareToken, ObjectStore $store): View
    {
        $generation = Generation::query()->where('share_token', $shareToken)->first();
        if (! $generation instanceof Generation || $generation->status !== 'done' || ! is_string($generation->output_path) || $generation->output_path === '') {
            abort(404);
        }

        if ($generation->expires_at !== null && $generation->expires_at->isPast()) {
            abort(404);
        }

        $ttl = (int) config('littleme.signed_url_ttl');

        return view('share', [
            'imageUrl' => $store->signedUrl($generation->output_path, $ttl, false),
            'downloadUrl' => $store->signedUrl($generation->output_path, $ttl, true),
            'shareUrl' => url('/s/'.$shareToken),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\Generation;
use App\Services\MongoIndexes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.connections.mongodb.database') !== 'littleme_testing') {
            $this->fail('Refusing to wipe a non-test MongoDB database');
        }

        MongoIndexes::ensure();
        Generation::query()->delete();
        Consent::query()->delete();

        $directory = storage_path('app/private/outputs');
        if (is_dir($directory)) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_health(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_consent_is_required(): void
    {
        $this->post('/api/generate', [
            'gender' => 'girl',
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ])->assertStatus(422)->assertJsonPath('error', 'validation_failed');

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(0, Consent::query()->count());
    }

    public function test_validation_failures(): void
    {
        $this->post('/api/generate', [
            'consent' => 'true',
            'gender' => 'other',
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ])->assertStatus(422);

        $this->post('/api/generate', [
            'consent' => 'true',
            'gender' => 'boy',
            'photo' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ])->assertStatus(422);

        $this->post('/api/generate', [
            'consent' => 'true',
            'gender' => 'boy',
            'photo' => UploadedFile::fake()->image('huge.jpg')->size(7000),
        ])->assertStatus(422);

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_generate_stores_output_and_not_the_selfie(): void
    {
        $upload = UploadedFile::fake()->image('unique-selfie-marker.jpg', 48, 48);
        $bytes = file_get_contents($upload->getPathname());
        $this->assertIsString($bytes);

        $response = $this->post('/api/generate', [
            'consent' => 'true',
            'gender' => 'girl',
            'photo' => $upload,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['id', 'image_url', 'share_url', 'duration_ms']);

        $generation = Generation::query()->first();
        $this->assertNotNull($generation);
        $this->assertSame('done', $generation->status);
        $this->assertContains($generation->template_key, ['playground', 'studio', 'outdoor']);
        $this->assertGreaterThanOrEqual(3, $generation->age);
        $this->assertLessThanOrEqual(10, $generation->age);
        $this->assertSame(40, strlen((string) $generation->share_token));

        $stored = json_encode($generation->toArray());
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('unique-selfie-marker', $stored);
        $this->assertStringNotContainsString(base64_encode($bytes), $stored);

        $output = file_get_contents(storage_path('app/private/'.$generation->output_path));
        $this->assertIsString($output);
        $this->assertNotSame(hash('sha256', $bytes), hash('sha256', $output));
        $this->assertStringNotContainsString($bytes, $output);

        $consent = Consent::query()->first();
        $this->assertNotNull($consent);
        $this->assertSame((string) $generation->getKey(), (string) $consent->generation_id);
        $this->assertSame('1', (string) $consent->consent_version);
        $this->assertNotNull($consent->accepted_at);
        $this->assertSame('en', $consent->locale);

        $sharePath = parse_url((string) $response->json('share_url'), PHP_URL_PATH);
        $this->get($sharePath)->assertOk()->assertSee('Download')->assertSee('Share');
    }

    public function test_every_template_appears(): void
    {
        $seen = [];
        for ($i = 0; $i < 36; $i++) {
            $this->post('/api/generate', [
                'consent' => 'true',
                'gender' => $i % 2 === 0 ? 'boy' : 'girl',
                'photo' => UploadedFile::fake()->image('selfie.jpg', 24, 24),
            ])->assertOk();
        }

        foreach (Generation::query()->get() as $generation) {
            $seen[$generation->template_key] = true;
            $this->assertGreaterThanOrEqual(3, $generation->age);
            $this->assertLessThanOrEqual(10, $generation->age);
        }

        $this->assertSame(['outdoor', 'playground', 'studio'], collect(array_keys($seen))->sort()->values()->all());
    }

    public function test_share_link_expires(): void
    {
        $this->post('/api/generate', [
            'consent' => 'true',
            'gender' => 'boy',
            'photo' => UploadedFile::fake()->image('selfie.jpg'),
        ])->assertOk();

        $generation = Generation::query()->first();
        $generation->update(['expires_at' => now()->subMinute()]);

        $this->get('/s/'.$generation->share_token)->assertNotFound();
    }

    public function test_rate_limit(): void
    {
        config(['littleme.rate_limit_per_minute' => 2]);

        $server = ['REMOTE_ADDR' => '203.0.113.10'];
        for ($i = 0; $i < 2; $i++) {
            $this->withServerVariables($server)->post('/api/generate', [
                'consent' => 'true',
                'gender' => 'girl',
                'photo' => UploadedFile::fake()->image('selfie.jpg', 24, 24),
            ])->assertOk();
        }

        $this->withServerVariables($server)->post('/api/generate', [
            'consent' => 'true',
            'gender' => 'girl',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 24, 24),
        ])->assertStatus(429)->assertJsonPath('error', 'too_many_requests');
    }

    public function test_ttl_indexes(): void
    {
        $names = [];
        foreach (DB::connection('mongodb')->getMongoDB()->selectCollection('generations')->listIndexes() as $index) {
            $names[] = $index->getName();
        }

        $this->assertContains('expires_at_ttl', $names);
        $this->assertContains('share_token_unique', $names);
    }
}

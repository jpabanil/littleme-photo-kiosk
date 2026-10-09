<?php

namespace App\Services\Storage;

use Google\Cloud\Storage\StorageClient;
use Throwable;

final class GcsObjectStore implements ObjectStore
{
    public function put(string $path, string $bytes, string $mime): void
    {
        $this->guardPath($path);

        try {
            $this->bucket()->upload($bytes, [
                'name' => $path,
                'metadata' => ['contentType' => $mime],
            ]);
        } catch (Throwable) {
            throw new \RuntimeException('Could not store output image');
        }
    }

    public function signedUrl(string $path, int $ttlMinutes, bool $download = false): string
    {
        $this->guardPath($path);
        $filename = 'little-me.jpg';
        $options = [
            'version' => 'v4',
            'method' => 'GET',
            'responseDisposition' => ($download ? 'attachment' : 'inline').'; filename="'.$filename.'"',
        ];

        $key = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        if (is_string($key) && is_file($key)) {
            $options['keyFilePath'] = $key;
        }

        try {
            return $this->bucket()->object($path)->signedUrl(
                new \DateTimeImmutable('+'.$ttlMinutes.' minutes'),
                $options,
            );
        } catch (Throwable) {
            throw new \RuntimeException('Could not sign output image');
        }
    }

    public function read(string $path): ?string
    {
        $this->guardPath($path);

        try {
            $object = $this->bucket()->object($path);
            if (! $object->exists()) {
                return null;
            }

            $bytes = $object->downloadAsString();

            return is_string($bytes) ? $bytes : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function bucket(): \Google\Cloud\Storage\Bucket
    {
        $name = (string) config('littleme.gcs_bucket');
        $project = (string) config('littleme.gcp_project');
        if ($name === '' || $project === '') {
            throw new \RuntimeException('GCS is not configured');
        }

        $client = new StorageClient([
            'projectId' => $project,
        ]);

        return $client->bucket($name);
    }

    private function guardPath(string $path): void
    {
        if (! preg_match('#^outputs/[A-Za-z0-9][A-Za-z0-9._-]{0,120}$#', $path)) {
            throw new \InvalidArgumentException('Invalid object path');
        }
    }
}

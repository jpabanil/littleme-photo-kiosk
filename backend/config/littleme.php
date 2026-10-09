<?php

return [

    'generator' => env('IMAGE_GENERATOR', 'fake'),
    'storage_driver' => env('STORAGE_DRIVER', 'local'),

    'gcp_project' => env('GCP_PROJECT_ID'),
    // Cloud Run, GCS, and MongoDB stay in this region.
    'gcp_location' => env('GCP_LOCATION', 'me-central2'),
    // Nano Banana 2.1 is served from the global endpoint, not me-central2.
    'gemini_location' => env('GEMINI_LOCATION', 'global'),
    'gemini_model' => env('GEMINI_IMAGE_MODEL', 'gemini-nano-banana-2.1'),

    'gcs_bucket' => env('GCS_BUCKET'),
    'signed_url_ttl' => (int) env('GCS_SIGNED_URL_TTL_MINUTES', 60),
    'share_ttl' => (int) env('SHARE_LINK_TTL_MINUTES', 60),
    'retention_hours' => (int) env('RETENTION_HOURS', 24),

    'gen_timeout_seconds' => (int) env('GEN_TIMEOUT_SECONDS', 45),
    'gen_max_retries' => (int) env('GEN_MAX_RETRIES', 1),
    'rate_limit_per_minute' => (int) env('RATE_LIMIT_PER_MINUTE_PER_IP', 30),

    'fake_delay_ms' => (int) env('FAKE_DELAY_MS', 2000),
    'consent_version' => env('CONSENT_VERSION', '1'),

];

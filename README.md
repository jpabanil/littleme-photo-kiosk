# littleme-photo-kiosk

Browser photo kiosk for a live activation. A guest agrees, chooses Boy or Girl, takes a selfie, and gets a childhood portrait with a QR code that opens a phone page to download or share it.

The guest screen is a landscape kiosk: a dark side rail for the steps, and a large stage for the one action on that step.

## Repo layout

```
backend/     Laravel 13 JSON API, share page, and local MongoDB data
frontend/    React + Vite kiosk (builds into backend/public/app)
Dockerfile   Cloud Run image (FrankenPHP)
docker-compose.yml   optional MongoDB 7, same data directory
```

## Local MongoDB

Data stays in this app, not in the shared Homebrew database:

```
backend/mongodb/data
backend/mongodb/mongod.conf
```

The server listens on `127.0.0.1:27020`. School data is on 27018 and clinic data is on 27019.

```bash
mongod --config backend/mongodb/mongod.conf
```

macOS cannot use `mongod --fork`. Leave that process running. `MONGODB_URI` points at `mongodb://127.0.0.1:27020` and `MONGODB_DATABASE` is `littleme`.

`docker compose up` publishes the same data directory on port 27020. Do not run compose and `mongod` at the same time against that folder.

For the event, use MongoDB Atlas dedicated (`M10+`) in GCP `me-central2` (Atlas region `MIDDLE_EAST_CENTRAL_2`, Dammam). Free and Flex tiers are not the right fit, and Atlas Search nodes are not offered in Dammam. This app does not need search nodes.

## Run the kiosk locally

```bash
cd backend
composer install
php artisan littleme:indexes
php artisan serve --host=127.0.0.1 --port=8000

cd ../frontend
npm install
npm run build
```

Open http://127.0.0.1:8000. For UI work, `npm run dev` in `frontend/` serves http://127.0.0.1:5173 and proxies `/api`, `/s`, and `/media` to Laravel.

`IMAGE_GENERATOR=fake` returns a placeholder after `FAKE_DELAY_MS` (2 seconds) so the screens can be tested without Google credentials. Set `IMAGE_GENERATOR=vertex` for real portraits.

Tests:

```bash
cd backend && php artisan test
```

Tests use the `littleme_testing` database on the same MongoDB server, so the files still live under `backend/mongodb/data`.

## Environment

Copy `backend/.env.example` to `backend/.env`.

| Variable | Purpose |
| --- | --- |
| `APP_URL` | Public origin used in links when there is no HTTP request |
| `MONGODB_URI` / `MONGODB_DATABASE` | Local or Atlas connection |
| `GCP_PROJECT_ID` | Google Cloud project |
| `GCP_LOCATION` | `me-central2` for Cloud Run and the bucket |
| `GEMINI_LOCATION` | `global`. Nano Banana 2.1 is only published on the global endpoint |
| `GEMINI_IMAGE_MODEL` | `gemini-nano-banana-2.1` |
| `GOOGLE_APPLICATION_CREDENTIALS` | Service account JSON when not using Workload Identity |
| `GCS_BUCKET` | Private bucket in `me-central2` |
| `GCS_SIGNED_URL_TTL_MINUTES` | How long an image URL works. The share page mints a new one each visit |
| `SHARE_LINK_TTL_MINUTES` | Documented alongside the signed URL TTL |
| `RETENTION_HOURS` | When generation and consent records expire |
| `GEN_TIMEOUT_SECONDS` / `GEN_MAX_RETRIES` | Model call budget. One retry on transient failures |
| `RATE_LIMIT_PER_MINUTE_PER_IP` | Abuse limit only. Default 30 |
| `IMAGE_GENERATOR` | `fake` or `vertex` |
| `STORAGE_DRIVER` | `local` or `gcs` |
| `FAKE_DELAY_MS` | Placeholder delay |
| `CONSENT_VERSION` | Stored with each acceptance |

The original selfie is held in memory for the model call and then discarded. It is not written to MongoDB or object storage. Logs record the generation id, status, duration, template key, and error code. They do not record photos, signed URLs, or share tokens. IP addresses are used only as a short-lived rate-limit key.

## Model and region

Checked against Google’s model location table on 9 Oct 2026. No Gemini image model is available in `me-central2` (or Doha, or Tel Aviv).

Nano Banana 2.1 (`gemini-nano-banana-2.1`) is global only. The app calls the global host `https://aiplatform.googleapis.com/.../locations/global/...`. Cloud Run, the bucket, and MongoDB stay in `me-central2`. The portrait request itself is processed on Google’s global endpoint, so the selfie is not guaranteed to stay in Saudi Arabia. That was an explicit choice because Dammam does not host this model. Change `GEMINI_LOCATION` and `GEMINI_IMAGE_MODEL` together if Google later publishes an image model in `me-central2`.

Output is requested at 1K and 3:4, the smallest size this model offers. If a real call is slower than about 8 seconds, the input is already limited to 1024 px on the long edge. The next lever is to keep `imageSize` at `1K` and watch Vertex latency in Cloud Monitoring.

## Edit the three templates

`backend/config/templates.php` holds the shared base instruction and the `playground`, `studio`, and `outdoor` settings. `{age}` and `{gender_word}` are filled in for each guest. Age is a random integer from 3 to 10. The template is chosen at random. Restart PHP or refresh the config cache after editing.

## Deploy

Build and deploy in `me-central2` only:

```bash
gcloud run deploy littleme \
  --source . \
  --region me-central2 \
  --min-instances 1 \
  --concurrency 4 \
  --cpu 2 \
  --memory 1Gi \
  --timeout 120 \
  --service-account littleme-runner@PROJECT.iam.gserviceaccount.com
```

The runtime service account needs `roles/aiplatform.user` and `roles/storage.objectAdmin` on this one bucket. Signed URLs from Workload Identity also need `roles/iam.serviceAccountTokenCreator` on itself, or a key file in `GOOGLE_APPLICATION_CREDENTIALS`.

Create the bucket in Dammam, private, uniform access, and delete objects after one day:

```bash
gcloud storage buckets create gs://BUCKET --location=me-central2 --uniform-bucket-level-access
gcloud storage buckets update gs://BUCKET --lifecycle-file=deploy/lifecycle.json
```

Set `STORAGE_DRIVER=gcs`, `IMAGE_GENERATOR=vertex`, `GCP_LOCATION=me-central2`, `GEMINI_LOCATION=global`, and the Atlas URI. Do not commit `.env` or the service account JSON.

HTTPS is terminated by Cloud Run. The app sends HSTS on HTTPS responses, plus `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, a camera-only permissions policy, and a CSP that allows the kiosk assets, blob previews, and `storage.googleapis.com` images.

A real image call and a Cloud Run load test were not run here: this machine has no Google credentials and no Docker. `load/generate.js` is the k6 script for about 30 concurrent guests:

```bash
k6 run -e BASE_URL=https://STAGING load/generate.js
```

Record p50, p95, and the error rate before the event. `php artisan serve` is single-threaded, so that number is not a capacity test.

## Retention

`RETENTION_HOURS` (default 24) sets `expires_at` on `generations` and `consents`. Both collections have a TTL index on `expires_at`. The bucket lifecycle deletes objects older than one day. The share page also returns 404 when `expires_at` has passed, without waiting for the TTL job. Run `php artisan littleme:indexes` after deploy. The container entrypoint does that.

## Activation-day checklist

- Cloud Run min instances is 1 in `me-central2`, so the first guest does not wait on a cold start.
- The bucket lifecycle rule is active.
- `IMAGE_GENERATOR=vertex` and `STORAGE_DRIVER=gcs`.
- Atlas is the Dammam cluster, and the URI is in the Cloud Run env, not in the image.
- Open Cloud Monitoring for the Cloud Run service and the Vertex `generateContent` errors.
- If portraits fail, the kiosk shows Try again and keeps the selfie in the browser. Check the generation `error_code` (`safety_block`, `empty_image`, `timeout`, `upstream`, `config`). Do not turn on debug pages for guests.
- If the model is slow, confirm the input photo is the downscaled JPEG and that `imageSize` is still `1K`.

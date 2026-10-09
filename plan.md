# BUILD PLAN: AI "Little Me" Photo Kiosk (React + Laravel + MongoDB)

You are building a single-day MVP of a browser-based photo kiosk for a live activation in Saudi Arabia. Work phase by phase. After each phase, run the checks listed and fix failures before moving on. Keep the code simple and readable. Do not add features that are not in this plan.

## 1. Product summary

A guest walks up to a screen and:
1. Accepts a consent notice.
2. Picks **Boy** or **Girl**.
3. Takes a selfie with the webcam.
4. Sees live status text: "Please wait…" -> "Generating your image…" -> "Your image is ready!"
5. Sees the generated image of themselves as a child (random age 3-10) on the RIGHT side of the screen, with a QR code beside it that opens a download/share page.

Hard requirements:
- Generation must feel as fast as possible (target 4-8 seconds end to end).
- No per-image billing visible to the client. Cost is absorbed in a flat fee, so the app has no usage caps for real guests (only abuse protection).
- Hosting and data must stay in Saudi Arabia (GCP Dammam, region `me-central2`).
- Photos are personal/facial data: consent first, delete fast, no personal data in URLs.

## 2. Stack

- Frontend: React 18 + Vite + TypeScript, plain CSS (or Tailwind), `qrcode.react` for the QR.
- Backend: Laravel 11/12 (PHP 8.3), JSON API only plus one public share page.
- Database: MongoDB via `mongodb/laravel-mongodb` (Eloquent-style models).
- Image generation: Google Vertex AI, Gemini image model ("Nano Banana 2" family), called server-side from Laravel via REST with a service account.
- Storage: Google Cloud Storage bucket in `me-central2`, private, signed URLs, lifecycle delete.
- Hosting: Google Cloud Run in `me-central2`, one container serving Laravel and the built React app.
- Single repo layout:

```
/backend    Laravel app
/frontend   React app (builds into backend/public/app)
/Dockerfile
/README.md  setup + deploy + handover notes
```

IMPORTANT verification step before coding the AI part: confirm the chosen Gemini image model ID is available in `me-central2` on Vertex AI. If it is not, STOP and report which regions it is available in, and propose the closest option. Do not silently switch to a non-Saudi region. Keep the model ID and region in env vars so this is a one-line change.

MongoDB hosting note: confirm a Saudi-resident option (MongoDB Atlas on GCP me-central2 if offered, otherwise a small self-managed MongoDB on a Compute Engine VM in me-central2). Report the choice. Locally use a Docker `mongo:7` container.

## 3. Environment variables (backend/.env.example)

```
APP_NAME=LittleMe
APP_URL=https://example.com
MONGODB_URI=mongodb://localhost:27017
MONGODB_DATABASE=littleme

GCP_PROJECT_ID=
GCP_LOCATION=me-central2
GEMINI_IMAGE_MODEL=            # set after availability check
GOOGLE_APPLICATION_CREDENTIALS=/secrets/sa.json   # use Workload Identity on Cloud Run instead when possible
GCS_BUCKET=
GCS_SIGNED_URL_TTL_MINUTES=60

SHARE_LINK_TTL_MINUTES=60
RETENTION_HOURS=24
GEN_TIMEOUT_SECONDS=45
GEN_MAX_RETRIES=1
RATE_LIMIT_PER_MINUTE_PER_IP=30   # abuse protection only, set high
```

## 4. Data model (MongoDB)

Collection `generations`:
```
_id, gender ("boy"|"girl"), template_key, age (3-10),
status ("processing"|"done"|"failed"),
output_path (GCS object path), share_token (random 40 chars),
duration_ms, error_code (nullable),
created_at, expires_at
```
Collection `consents`:
```
_id, generation_id, consent_version, accepted_at, locale
```
Rules:
- NEVER store the guest's original selfie. Hold it in memory (or a temp file) only for the model call, then discard it immediately, success or failure.
- Create a TTL index on `generations.expires_at` and `consents` so records auto-expire after `RETENTION_HOURS`.
- No names, emails, phone numbers, or IP addresses stored.

## 5. API contract

`POST /api/generate` (multipart: `photo` JPEG/PNG, `gender`, `consent=true`)
- Validate: consent must be true, gender in [boy, girl], image max 6 MB, mime jpeg/png/webp.
- Server picks one of the 3 templates for that gender AT RANDOM, and a random age 3-10.
- Calls the model synchronously (single request, no queue; Cloud Run autoscaling handles concurrency). Retry once on transient failure.
- Uploads the output to GCS, saves the `generations` record, returns:
```json
{ "id": "...", "image_url": "<short-lived signed url>", "share_url": "https://<host>/s/<share_token>", "duration_ms": 5120 }
```
- On failure return a friendly JSON error `{ "error": "generation_failed", "message": "..." }` with HTTP 502/422 (no stack traces).

`GET /s/{share_token}` (public Blade page)
- Mobile-first page showing the image, a big Download button, and a Share button (Web Share API fallback to copy link).
- Generates a fresh signed URL on each view. Returns 404 after expiry.
- Contains no personal data in the URL beyond the random token.

`GET /api/health` -> `{ "ok": true }`.

Add rate limiting (`RATE_LIMIT_PER_MINUTE_PER_IP`) on `/api/generate`, set generously so real guests are never blocked.

## 6. The three templates (config/templates.php)

Store as config so they can be edited without code changes. Each template has a key and a prompt with `{age}` and `{gender_word}` placeholders ("boy" / "girl").

Base instruction (prepended to every template):
"Using the person in the provided photo as the identity reference, create a photorealistic portrait of what this exact person would have looked like as a {age}-year-old {gender_word}. Preserve the recognizable facial structure, eye shape, skin tone, and hair color of the person. Age-appropriate, wholesome, family-friendly, fully clothed. No text or watermarks."

Templates (same set for boy and girl, wording adapts via `{gender_word}`):
1. `playground` -> "Setting: a sunny playground, natural daylight, candid smiling expression, casual colorful clothes."
2. `studio` -> "Setting: classic school-portrait studio, soft even lighting, plain pastel backdrop, neat clothes, gentle smile."
3. `outdoor` -> "Setting: a green park or garden at golden hour, shallow depth of field, relaxed happy expression, light layered clothing."

Safety: if the model returns no image or a safety block, return `generation_failed` and let the UI show the retry screen. Never return a partial or unrelated image.

## 7. Frontend screens (React)

Kiosk layout, landscape, large touch targets, minimal text. State machine: `consent -> select -> camera -> generating -> result`, plus `error`.

1. **Consent**: short notice that the photo is used only to create the image, is not stored beyond the event window, and is deleted automatically. Single "I agree and continue" button. Send `consent=true` with the request; the backend records consent version + timestamp.
2. **Select**: two big buttons, Boy / Girl.
3. **Camera**: `getUserMedia({ video: { facingMode: "user", width: 1280, height: 720 } })`, live preview, 3-2-1 countdown, Capture button. Draw to canvas, downscale to max 1024 px on the long edge, export JPEG quality 0.85 (smaller upload = faster). Allow Retake.
4. **Generating**: show status text that progresses on timers while the request runs: "Please wait…" (0 s) -> "Generating your image…" (after 1 s) -> on success "Your image is ready!" (flash for ~0.8 s). Use a simple animated indicator. Disable all buttons.
5. **Result**: two-column layout. LEFT: the guest's captured photo (kept in browser memory only) and a "Start over" button. RIGHT: the generated image large, with the QR code (`qrcode.react`, encoding `share_url`) and the caption "Scan to save and share". Auto-reset to the Consent/Select screen after 60 seconds of inactivity, and clear all in-memory photos on reset.
6. **Error**: friendly message + "Try again" (reuses the same captured photo if still in memory).

Also: stop the camera stream when leaving the camera screen; handle camera-permission-denied with a clear message; prevent double submits.

Optional stretch (only if everything else is done and tested): AR/EN language toggle using i18next with `dir="rtl"` switching and CSS logical properties. Do not start this before the core flow works.

## 8. Phases and checks

**Phase 1: Scaffold (30 min)**
- Create the repo layout, Laravel app with `mongodb/laravel-mongodb`, Vite React app, Docker compose for local Mongo.
- Check: `GET /api/health` returns ok; React dev server renders; Mongo connection works.

**Phase 2: Backend core (90 min)**
- Models, config/templates.php, `/api/generate` with validation and rate limiting, GCS upload + signed URLs, share page, TTL indexes.
- Put the model call behind an interface (`ImageGenerator`) with two implementations: `VertexGeminiGenerator` and `FakeGenerator` (returns a placeholder image after a 2 s delay) so the UI can be built and tested without cloud credentials.
- Check: Pest/PHPUnit feature tests for validation failures, consent required, template randomization (all 3 appear over many runs), and that the original photo is never persisted.

**Phase 3: Vertex integration (60 min)**
- Implement `VertexGeminiGenerator` with the verified model ID/region, timeout `GEN_TIMEOUT_SECONDS`, one retry, image-in/image-out request, and safe error mapping.
- Check: one real end-to-end generation; log duration_ms; confirm it is within the 4-8 s target. If slower, report timings and suggest fixes (smaller input image, lower output resolution) before moving on.

**Phase 4: Frontend (120 min)**
- Build all screens per section 7 against `FakeGenerator` first, then switch to the real one.
- Check: full flow works on desktop Chrome and on an Android tablet browser; QR scans from a phone and opens the share page; reset clears photos.

**Phase 5: Deploy (60 min)**
- Multi-stage Dockerfile (build React, then PHP-FPM + nginx or FrankenPHP serving Laravel and `/app`). Deploy to Cloud Run in `me-central2`, min instances 1 (avoids cold starts during the event), concurrency tuned for the CPU. Use a service account with only Vertex AI user + Storage object admin on the one bucket. GCS bucket: private, uniform access, lifecycle rule deleting objects older than 1 day.
- Security: HTTPS only, HSTS, CSP header, no secrets in the repo, no personal data in URLs or logs.
- Check: staging URL works end to end from a phone on mobile data; run a quick load test (k6 or Artillery) of ~30 concurrent generations and report p50/p95 latency and error rate.

**Phase 6: Handover (20 min)**
- README with: env vars, how to deploy, how to edit the 3 templates, how to change the model ID, how retention/deletion works, and a short activation-day checklist (min instances, bucket lifecycle, monitoring link, what to do if generation fails).

## 9. Acceptance criteria

- A guest completes the flow in under 30 seconds including photo capture.
- Generation returns within about 8 seconds under normal load.
- Each run uses one of the three templates chosen at random and a random age 3-10.
- Image appears on the right; QR opens the mobile share page and downloads the image.
- Original selfies are never stored; outputs and records auto-delete after the retention window.
- All data and compute are in `me-central2`.
- No unhandled errors reach the guest; every failure shows the friendly retry screen.

## 10. Rules for you (the coding agent)

- Ask before changing the stack, region, or scope.
- If a required service, region, or model is unavailable, stop and report instead of working around it.
- Commit after each phase with a clear message.
- Write tests for the backend rules in Phase 2, not for every UI detail.
- Do not log photos, signed URLs, or share tokens.

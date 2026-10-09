# EphemeraDrop

EphemeraDrop creates expiring, view-limited text and file links. The browser encrypts payloads locally with AES-256-GCM. File data is encrypted in 4 MiB chunks, including a separately authenticated metadata header; the server stores only opaque chunks and operational metadata. The decryption key (or password-wrapped key) is held in the URL fragment, which browsers do not send in HTTP requests.

An optional password wraps the content key with PBKDF2-SHA256 using at least 600,000 iterations. Passwords must be shared separately. A receiver can inspect expiry and remaining views without consuming one; a view is consumed only when they reveal/download. File chunks are downloaded through short-lived, signed application routes.

No accounts, emails, IP addresses, or user agents are retained by the application. The database-backed session handler intentionally omits IP address and user-agent columns. The server stores notification URLs encrypted at rest. This is not protection against a compromised server serving malicious JavaScript; see `/about/security`.

## Local development

Local development remains Laravel Sail with MySQL and Redis. In a WSL or Git Bash terminal, copy `.env.example` to `.env`, set a unique key with `php artisan key:generate`, then run:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
npm ci
npm run build
```

Visit `http://localhost`. Set `EPHEMERA_STORAGE_DISK=local` for local private storage. Local file chunks live under `storage/app/private`; this local disk is also available as an explicit demo-only fallback in production, where files are lost on container restart/redeploy.

## Tests

```powershell
php artisan test
node --test tests/Unit/crypto.test.js
npm run build
```

Run the complete PHP suite on both supported database engines (Docker Desktop required):

```powershell
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from test-mysql test-mysql
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from test-postgres test-postgres
docker compose -f compose.test.yaml down -v
```

The project uses PHPUnit (via Laravel's `artisan test`, not Pest). The concurrent one-view claim test skips SQLite and runs in the MySQL and PostgreSQL containers using 20 independent PHP processes against the same database row.

## Deploying to Render + Backblaze B2

### Deploy the Blueprint

1. Push the repository to GitHub. In Render choose **New → Blueprint**, select the repository, and apply its root `render.yaml`.
2. Enter the requested `sync: false` values only in the Render dashboard (never commit them or paste them into chat). The Blueprint creates one Docker web service and a Render Postgres database in Singapore.
3. Set a proper Laravel key in `APP_KEY`: `php artisan key:generate --show` prints a Laravel-formatted base64 key. Generate it locally and paste directly into Render; do not commit it.
4. The startup script checks required configuration, migrates Postgres, builds Laravel caches, and then Supervisor starts nginx, PHP-FPM, the database-backed queue worker, and the scheduler. Check the deployment and service logs in Render.

Render prompts for `sync: false` values when a Blueprint is first created. If you add one of these variables in a later Blueprint update, set it manually in the service's Environment page.

The default web plan is `free` for a demo. In `render.yaml`, change the web service's `plan: free` to `plan: starter` for an always-on service. Free web instances sleep after 15 minutes without inbound traffic and the first request after sleep can take about a minute. Free plans are not recommended for production. Render's free Postgres currently has 1 GB storage, expires 30 days after creation, has no backups, and is deleted after the 14-day post-expiration grace period if not upgraded. Check Render's current free-service, Postgres, and pricing terms before deploying.

### Create the private B2 bucket and key

1. In Backblaze B2, create a dedicated bucket and set its access to **Private**. Do not enable public access.
2. Create a new **Application Key** scoped to that bucket, with only the permissions needed for listing, reading, writing, and deleting files. Treat its `keyID` and `applicationKey` as credentials.
3. In the Backblaze bucket details, copy the S3-compatible **Endpoint** and **Region** exactly. The endpoint typically looks like `https://s3.<region>.backblazeb2.com`; use the region displayed for that bucket, not a guessed region.
4. Enter the following secret environment values in Render:

| Render variable | Value source |
|---|---|
| `APP_KEY` | Newly generated Laravel `base64:` key |
| `AWS_ACCESS_KEY_ID` | B2 Application Key `keyID` |
| `AWS_SECRET_ACCESS_KEY` | B2 Application Key `applicationKey` |
| `AWS_BUCKET` | Exact B2 bucket name |
| `AWS_ENDPOINT` | Exact S3-compatible endpoint from that bucket's details, including `https://` |
| `AWS_DEFAULT_REGION` | Exact bucket region |

The Blueprint sets `EPHEMERA_STORAGE_DISK=s3` and `AWS_USE_PATH_STYLE_ENDPOINT=true`. S3-compatible file operations use Laravel's `Storage` facade; chunk writes omit object-level ACL headers because B2 does not support them. Chunks remain private through the private bucket and are served by the app's signed routes, not from public B2 URLs. The installed AWS SDK for PHP is v3.399.1 and accepts `request_checksum_calculation` / `response_checksum_validation`; both default to `when_required` to avoid sending optional checksums that some S3-compatible APIs reject. No real B2 credentials are used in tests.

### B2 lifecycle cleanup and costs

B2 keeps file versions. An application delete may add a hide marker rather than immediately removing the older stored version. In the Backblaze console, open the bucket's **Lifecycle Settings** and configure a rule for the whole bucket (leave the file-name prefix blank) to:

- Hide files **8 days after upload**.
- Delete hidden files/old versions **1 day after hiding**.

Verify the exact option names and values in the current Backblaze UI before saving. Backblaze documents these as `daysFromUploadingToHiding: 8` and `daysFromHidingToDeleting: 1`; do not apply a blank-prefix rule to a bucket that contains unrelated data.

Backblaze's published entry-level pricing has included about 10 GB of free storage, with API transaction allowances and download/egress terms that may depend on usage and destination. Check the [current Backblaze B2 pricing page](https://www.backblaze.com/cloud-storage/pricing) for exact free allowances and rates before relying on them. The B2 S3-compatible API supports private buckets and presigned operations; B2 does not support the full AWS S3 feature set, including object-level ACLs, IAM roles, browser POST uploads, and object tagging.

### Production operations

- **Custom domain:** add the domain in Render's service settings, create the DNS records Render shows, and wait for Render-managed TLS. The app trusts Render's forwarded protocol/host headers and derives `APP_URL` from `RENDER_EXTERNAL_URL` when `APP_URL` is unset.
- **Storage:** never choose the local disk for durable user files on Render. Render's filesystem is ephemeral; use B2 (`EPHEMERA_STORAGE_DISK=s3`) for production. The local private disk is demo-only and files disappear on restart or deploy.
- **Queues and cache:** queue, cache, and session drivers default to the Postgres-backed database. Required `jobs`, `cache`, `cache_locks`, and privacy-safe `sessions` tables are migrated. If `REDIS_URL` is present and no explicit queue/cache driver is set, the app selects Redis.
- **Rotate B2 credentials:** create a replacement bucket-scoped B2 key, update `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` in Render, redeploy, verify uploads and deletes, then revoke the old key in Backblaze.
- **Rotate `APP_KEY`:** change `APP_KEY` only with care. Laravel's encrypted `notify_url` values use this key and become unreadable after rotation unless the old key is retained through a supported key-rotation/migration plan. Existing fragment-based drop content keys are not encrypted with `APP_KEY`.
- **Rollback:** use Render's deploy history to roll back the web image after checking logs. Database migrations are additive for the storage/upload fields; review any later schema changes before rolling application code back. Render free Postgres has no backups, so use a paid database plan with backups for data you need to recover.

### Deploy after pushing the repository

After the source is pushed to `kia3004/EphemeraDrop`, use Render Dashboard → **New → Blueprint** → select that repository → fill the `sync: false` values in the table above → **Apply** → monitor the build and deploy logs. The local workspace currently has no configured Git remote, so it must be connected and pushed before this Blueprint can build. The B2 values must come from your Backblaze account as described above; do not send them through chat.

The live service URL is shown in the Render dashboard after deployment. Verify `/health`, create a text link, open it in a second browser context, and reveal it. For files, also upload a file larger than 8 MiB to verify at least three encrypted chunks, then confirm in the B2 console that objects appear and are later cleaned up. Render deployments and Backblaze resources are account-specific; no live URL or B2 upload can be verified until your Blueprint and dashboard credentials are supplied.

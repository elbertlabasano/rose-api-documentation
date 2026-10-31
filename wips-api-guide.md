# WIPS global JSON API v1

Production base URL: `https://wips.ph/api/v1`. This guide is imported from the WIPS project documentation; deployment and integration tests described below were not run while publishing this documentation. Select **WIPS - Global Mobile API** in the Swagger dropdown for interactive requests.

The public entry point is `api/index.php`. Shared code lives in `app/Api`, with
identity, trip tracking and evidence services in `app/Services`. It does not load
`init.php`, browser sessions, module logins or desktop rendering. Existing module
endpoints continue working. No mobile endpoint dispatches, receives or adjusts stock.

## Install

1. Apply Trip Plans, then the API schema:
   ```text
   php cli.php migrations --only=2026_10_31_180000_add_logistics_trip_plans.php
   php cli.php migrations --only=2026_10_31_190000_add_global_mobile_api.php
   ```
2. Serve WIPS at the virtual-host root with Apache `mod_rewrite` and AllowOverride
   enabled. Deploy the tracked `api/.htaccess` and `app/.htaccess`: the first routes
   `/api/v1/*`; the second denies direct HTTP access to application source/storage.
   With rewriting unavailable, `/api/index.php/v1/*` is also recognized when PHP
   PATH_INFO is supported. No arbitrary executable API files are public.
3. Use HTTPS. Loopback HTTP is allowed for local testing. At a TLS-terminating proxy,
   configure only actual proxy addresses in `API_TRUSTED_PROXY_IPS`; forwarded HTTPS
   headers from unlisted hosts are ignored.
4. Copy the settings from `.env.api.example` into the deployment environment.
   `API_ALLOWED_ORIGINS` is an exact comma-separated allowlist, without wildcards.
   Add the actual Capacitor WebView origin and local development origin as needed.
   Browser sessions/cookies are not used; clients send bearer tokens.
5. Explicitly link a WIPS account to a Driver employee:
   ```text
   php api-manage.php driver:link USER_ID EMPLOYEE_ID
   ```
   Assign **Logistics Management > Trip Plans > View** in User Management. The
   mapping is unique in both directions and does not infer identity from names,
   plates or posted employee IDs. Mobile tracking of assigned trips does not
   require desktop Add/Update permission. Disabled accounts/mappings and removed
   Trip Plans View access are rechecked on every trip request.
6. Ensure PHP GD is available and `app/storage/api-evidence` is writable by PHP.
   Evidence is private, JPEG-normalized with metadata removed, and read only through
   authenticated endpoints. Back up files together with their database records.
   Apache mod_php upload limits are set in `api/.htaccess`. For PHP-FPM, set
   `upload_max_filesize=8M`, `post_max_size=10M`, and disable displayed errors in
   server configuration. JSON bodies are limited to 512 KB.

Tokens expire after 24 hours by default, are stored only as SHA-256 hashes, and
are bound to the account/device and current password fingerprint. Logging in on
the same device rotates its tokens; logging out revokes the current token.
Password changes, account disablement and device revocation invalidate access.
There is no refresh-token flow yet: prompt for sign-in after expiry while retaining
the offline queue. Use secure native credential storage in the mobile client.

```text
php api-manage.php driver:disable USER_ID
php api-manage.php device:revoke DEVICE_UUID
php api-manage.php prune
```

`prune` removes rate buckets idle for a day and tokens expired for over 30 days.
It retains trip, proof and audit history. Device handoff during an active trip is
not yet supported; do not reset/reinstall the primary phone mid-trip.

## Routes

All paths below start with `/api/v1`. The imported contract is in `wips.openapi.json` in this documentation project.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | Minimal database/API availability |
| POST | `/auth/login` | WIPS credentials + installation UUID -> bearer token |
| POST | `/auth/logout` | Revoke current token |
| GET | `/me` | Account, linked driver ID and current device |
| GET | `/me/trips?page=1` | Assigned confirmed, active and historical trips; 25/page |
| GET | `/trips/{id}` | Assigned trip with manifest |
| GET | `/trips/{id}/manifest` | Stops, locations, coordinates, shipments, items and sync cursor |
| GET | `/trips/{id}/events?after_id=0` | Accepted event feed, 100/page |
| POST | `/trips/{id}/events` | Ordered offline event batch, 1–100 |
| POST | `/trips/{id}/gps` | GPS samples, 1–500 |
| POST | `/shipments/{id}/delivery-photos` | Multipart photo evidence |
| POST | `/shipments/{id}/delivery-signatures` | Multipart signature evidence |
| GET | `/shipments/{id}/delivery-evidence/{evidence_id}` | Authorized private JPEG |
| POST | `/shipments/{id}/delivery-confirmations` | Immutable per-shipment proof |
| GET | `/shipments/{id}/delivery-confirmations?trip_id=...` | Read recorded proof |

Success: `{ "data": {...}, "meta": {"request_id": "...", "server_time": "..."} }`.
Failure: `{ "error": {"code": "...", "message": "...", "details": {...}}, "meta": {...} }`.
Errors use HTTP 400/401/403/404/405/409/413/415/422/426/429/500/503 as appropriate.
Unexpected server errors never expose SQL, credentials or filesystem paths.

## Mobile sequence

1. Create one installation UUID and log in with `username`, `password`, `device_id`
   and `device_name`. Download `/me/trips` and the chosen manifest into SQLite.
2. Keep the downloaded `plan_version`. A dispatcher can cancel a confirmed plan;
   stale/new events are rejected rather than silently applied to another version.
3. ERP dispatch remains independent. `trip.started` succeeds only when all linked
   shipments are already `in_transit`. Ideally synchronize this first event online
   before departure to establish this device as the primary tracker. An offline
   start can be queued, but cannot be considered server-authorized until acknowledged.
4. Queue event UUIDs and strictly increasing `client_sequence` values per trip,
   beginning at 1. Retries must retain the same IDs, sequences and payloads.
   Upload in order: `trip.started`, `stop.arrived`, `stop.departed` or
   `stop.skipped`, then `trip.returned`. `stop.issue_reported` requires notes and
   records a tracking issue only; ERP accountability exceptions remain separate.
   Stop events require `stop_id`; all events include timezone-qualified `occurred_at`.
   Skips require a reason and never declare shipments received.
5. Batch responses contain individual `accepted`, `duplicate` or `rejected` results.
   Only delete an outbox entry after `accepted` or `duplicate`. A rejected event
   leaves the sequence unchanged, so later dependent events remain pending too.
   Changed payloads under an existing UUID are conflicts, not corrections.
6. GPS points use a separate positive `sequence` per trip/device. Send latitude,
   longitude, accuracy in meters and capture time. Retries deduplicate by sequence;
   capture times must lie between trip start and return. Samples may arrive after
   return, but before dispatcher closure. Raw samples do not imply road distance.
7. Upload photos/signatures using multipart `trip_id`, `evidence_id` (UUID), and
   `file`. Signature uploads also require `kind=logistics_signature` or
   `kind=recipient_signature`. JPEG/PNG only, <=8 MB and <=20 megapixels. Reuse the
   original file bytes and evidence UUID when retrying. The response gives numeric
   `id` and UUID `evidence_id`; confirmations reference the numeric IDs.
8. Submit a confirmation per shipment, with signatory names/roles, signature IDs,
   1–3 photo IDs, stop ID, notes and occurrence time. Synchronize stop arrival first.
   Confirmation time must fall within that visit. Proof is immutable and never
   changes quantities, shipment custody or inventory receiving.
9. After all stops are departed/skipped, `trip.returned` records vehicle return.
   Its payload must declare `last_gps_sequence` (0 if no samples were captured),
   allowing late GPS uploads while preventing closure with an unknown backlog.
   The dispatcher can close a returned trip only after existing receiving/return
   workflows reconcile every shipment, no open exceptions/receiving variances remain,
   all completed shipments have confirmations, and every GPS sequence through the
   return checkpoint has synchronized. Closure releases trip reservations;
   it creates no inventory movement. Finalize all GPS/evidence uploads before closure.

The ERP Trip Plans detail screen shows stop progress, mobile event history, proof
links and the most recent 2,000 GPS samples. Its coordinate trace is not a road map:
gaps over five minutes break the line. Last-sync times and mobile occurrence/receipt
times are UTC; planned departure is Asia/Manila.

## Rate limits

Configured centrally in `app/config/api.php`, persisted atomically in the database.

| Bucket | Default |
| --- | --- |
| All API requests / direct remote IP | 180/minute |
| Authenticated requests / device | 240/minute |
| Login / IP | 10/15 minutes |
| Login / account | 5/15 minutes |
| Event uploads / device | 30 batches/minute |
| GPS uploads / device | 30 batches/minute |
| Evidence uploads / device | 20/minute |

Limits count requests (including successful login attempts), not only failures.
HTTP 429 includes `Retry-After` seconds. Keep queued records and apply backoff;
do not generate new UUIDs on retry. Remote IP is taken from the direct connection,
not an untrusted forwarded header; tune the aggregate IP limit for shared proxies.

## Verification

```text
php tests/api/integration_test.php
php tests/api/http_test.php
php Modules/Logistics_Management/tests/trip_plan_test.php
php Modules/Logistics_Management/tests/trip_plan_access_test.php
php Modules/Logistics_Management/tests/workflow_test.php
```

API tests use transaction-only fixtures, including login, token revocation,
cross-driver isolation, manifest output, ordered retries, GPS, proof, rate limiting
and unchanged shipment/order/inventory snapshots. HTTP tests launch a loopback-only
PHP server, use actual multipart uploads, then roll back fixtures and remove generated
files. Fixture routes run only under that CLI test server, never in Apache.

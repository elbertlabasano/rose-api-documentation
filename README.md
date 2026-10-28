# Rose Bakeshop Keycloak API documentation

Keycloak supports creating users, editing users, setting/changing passwords, and enabling/disabling accounts through its Admin REST API.

See [setup.md](setup.md) for local PHP and Apache/XAMPP setup instructions and how to use both API specifications.

## Open Swagger UI

For local use, run `php -S localhost:8080 gateway.php` and open <http://localhost:8080/index.html>; no documentation password is required for loopback requests. For local Apache, copy `.htaccess.example` to `.htaccess` and set `DOCS_ENV local`. Production Apache defaults to requiring HTTP Basic Authentication, including direct requests to either OpenAPI JSON file. Follow [setup.md](setup.md) to configure a production bcrypt password file outside the web root and keep `DOCS_ENV production`. API bearer tokens in Swagger remain separate from the documentation login.

Deploy `index.html`, `openapi.json`, `itsupporthub.openapi.json`, `sms.openapi.json`, `esigned-sso-setup.html`, `esigned-sso-setup.md`, and `gateway.php`. On initial Apache setup, copy `.htaccess.example` to `.htaccess` and configure your own absolute password file path. The local `.htaccess` is ignored by Git; the example is shared. Enable Apache's rewrite/env modules and `.htaccess` overrides as described in setup.md. Use HTTPS for deployed documentation. PHP 8.1+ is required. Previous static Python/Node preview commands do not enforce authentication and have been removed.

## Multiple APIs and Support Hub tickets

Each API has a separate OpenAPI file with its own server address and authentication scheme. Add more entries to `urls` in `index.html` to publish additional APIs in the selector. Also add the new specification to the protected file allowlist in `gateway.php`.

The Support Hub specification documents `POST https://itsupporthub.rosebakeshop.net/api/ticket`, updated from the supplied `ExternalTicketController::store`. Its route and bearer authentication are retained from the prior integration; route/middleware configuration and `TicketService` internals were not supplied. Use a Support Hub API token in Authorize. Example IDs must be replaced with active records, and the category must belong to the selected department unless it has no department.

Required fields: requester `name`, `email`, `department_id`, `category_id`, `subject`, and `description`. Optional fields include phone, priority, ticket type, board ID or slug, ticket assignee, source metadata, request context, and a checklist. Each checklist item requires nonblank `content` (maximum 500 characters); optional `is_completed` defaults to false and `assigned_to` defaults to null. Up to 50 checklist items are accepted. Ticket and checklist assignees must be active admin, super_admin, or technician users and must have access to the selected board. Non-empty board ID and slug cannot both be supplied; null/omitted selectors allow backlog creation.

Choose `application/json` for requests without files. For uploads, select `multipart/form-data`: send repeated `attachments[]` parts (up to 10 files, each at most 10240 KB). Allowed file types are jpg, jpeg, png, pdf, doc, docx, txt, and webp. Use bracketed form fields such as `checklist[0][content]`, `checklist[0][is_completed]` (0 or 1), `checklist[1][content]`, and `request_context[page]`; do not send a JSON string for these nested form values. Swagger exposes the first checklist item as an interactive example; clients can add indices up to 49. Omit unused numeric form fields rather than sending empty strings. Server/PHP upload limits may impose additional restrictions.

The controller normalizes requester email and can create a verified viewer account when no requester matches, or update an existing requester's phone. Successful creation returns HTTP 201 with ticket details, board/assignee, checklist items/count, external reference, and ticket URL. Validation returns 422; caught creation/response failures return 500. The specification includes the controller's response fields and examples. An external reference does not establish an idempotency guarantee.

Executing this operation creates a real production ticket. No ticket was created while preparing this documentation. Browser execution also depends on Support Hub allowing your documentation origin through CORS.

Swagger UI loads its assets from a CDN, so the viewing browser needs internet access. Requests from Swagger go directly to the configured Keycloak SSO server; hosting the documentation on Apache does not automatically resolve cross-origin restrictions.

### Optional local preview

If you want to preview before uploading, from this directory run:

```powershell
python -m http.server 8080 --bind 127.0.0.1
```

Open <http://localhost:8080>. Alternatively, with Node.js installed, run `npm start` (no package installation needed). These local preview tools are optional and are not needed on your Apache server. Import `openapi.json` directly into Swagger Editor or Postman if preferred.

## Configure access

The configured SSO base URL is `https://sso.rosebakeshop.net`. The deployment version, realm name, and proxy configuration have not been verified. Replace `YOUR_REALM` in requests with your actual realm name, not its internal ID. If your deployment uses a context prefix such as `/auth`, update the server URL in `openapi.json` accordingly.

For a backend integration, create a dedicated OpenID Connect client in the realm containing your users. Enable client authentication and service accounts. Assign its service account the appropriate `realm-management` client roles, typically `manage-users` for mutations and `view-users` / `query-users` for reading and searching. Fine-grained admin permissions and role scope mappings can change the necessary setup; ensure the issued token includes the permissions required for the operations.

1. Expand **Authentication → Get an admin service account access token**.
2. Enter your realm, client ID, and client secret. Use `grant_type=client_credentials`.
3. Execute and copy `access_token` from the response.
4. Click **Authorize** and paste only the token, without the `Bearer` prefix.
5. Execute the user operations with the target realm and internal user ID.

Keep service account secrets in your backend or trusted local tooling. Do not embed them in a public frontend or commit them to this repository. The documentation does not save authorization across reloads. Browser requests require the SSO deployment to allow the documentation origin through its CORS configuration; otherwise use a trusted backend, curl, or Postman. This documentation server does not proxy requests or bypass CORS.

## Operations

All paths below use the SSO base URL. Admin operations require a bearer token.

| Action | Method | Path |
| --- | --- | --- |
| Get service account token | POST | `/realms/{realm}/protocol/openid-connect/token` |
| Create user | POST | `/admin/realms/{realm}/users` |
| Find user ID | GET | `/admin/realms/{realm}/users?username=sample.user&exact=true` |
| Get user details | GET | `/admin/realms/{realm}/users/{user-id}` |
| Edit / activate / deactivate user | PUT | `/admin/realms/{realm}/users/{user-id}` |
| Set or change password | PUT | `/admin/realms/{realm}/users/{user-id}/reset-password` |
| Remove user sessions | POST | `/admin/realms/{realm}/users/{user-id}/logout` |

Create returns HTTP **201**, with the created user URL in the `Location` header. Use its final segment as `user-id`. Editing, password resets, and logout return HTTP **204**, with no response body. Swagger includes request examples for every requested operation.

Activate with `{"enabled": true}` and deactivate with `{"enabled": false}` using the edit endpoint. Read the user first and preserve required profile fields/attributes where needed by your realm configuration. Realm profile policies can require more fields than the sample create request.

Set or replace a password using:

```json
{
  "type": "password",
  "value": "ReplaceWithPolicyCompliantPassword!",
  "temporary": false
}
```

Use `temporary: true` to require a password update on the next login. This endpoint is an administrative reset without the old password; self-service changes should use Keycloak's account console or supported login actions. Realm password policies and writable user storage still apply.

Deactivation prevents new logins. Remove existing sessions with the logout endpoint as needed. Applications validating access tokens locally may continue accepting already-issued tokens until expiry; session logout does not guarantee immediate rejection of every JWT.

## Scope and verification

This is a focused OpenAPI 3.0.3 description of Keycloak's existing endpoints, not a new user management backend. No authenticated requests or changes have been made to the SSO deployment. Confirm your realm and installed Keycloak version before executing requests against real accounts.

Official references: [Admin REST API](https://www.keycloak.org/docs-api/latest/rest-api/index.html), [service accounts and administration](https://www.keycloak.org/docs/latest/server_admin/index.html#_service_accounts).

## eSigned SSO integration guide

Open **Laravel SSO Guide** in the definition dropdown, or visit `esigned-sso-setup.html`. The full Laravel/Keycloak setup guide includes connection settings, the employee claim prerequisites, account linking, controller and route examples, pilot testing, troubleshooting, and rollback. Its Markdown source is `esigned-sso-setup.md`. Both files require the documentation login in production and open without it locally.

The supplied reference has been preserved; it is not a verified deployment or implementation in eSigned. The appended local environment dump was excluded because it contained a client secret and conflicting local settings. No live secrets are included in the published guide.

## Get tickets assigned to a user

The Support Hub definition also includes **GET /api/ticket**, based on `ExternalTicketController::index`. The path is assumed to share the create endpoint URL and must be confirmed in `routes/api.php`; no route file was provided.

Example request (replace the sample user ID):

```http
GET /api/ticket?assigned_id=3&page=1&per_page=20
Authorization: Bearer YOUR_SUPPORT_HUB_TOKEN
Accept: application/json
```

`assigned_id` is required and must be an existing user ID greater than zero. It differs from the create request's `assignee_id`. `page` defaults to 1; `per_page` defaults to 20 and accepts 1?100. Results are ordered newest first by creation time, then internal ID. The controller does not implement status, board, priority, or date filters.

The HTTP 200 response contains `success`, `data`, `meta` (`current_page`, `last_page`, `per_page`, `total`), and `links` (`next`, `previous`, nullable). Each ticket includes its public identifier, UUID, subject, description, status, priority, type, board ID/details, all assignees, nullable due/created/updated dates, and ticket URL. Checklist items and attachments are not returned. An existing user with no matching tickets gets an empty `data` list. Invalid parameters return 422. Authorization and rate limits depend on deployed middleware; the controller does not restrict queries to the caller's own assigned ID.

## SMS gateway

Select **SMS Gateway - Messages and Devices** in the definition dropdown. The specification `sms.openapi.json` uses `https://sms.rosebakeshop.net` and was derived from `rose-web-socket/server.js` and `src/store.js`. The production domain/proxy has not been verified and no live SMS or registration was performed.

Use **Authorize** with the SMS gateway's configured `API_TOKEN`. This is separate from Keycloak, Support Hub, and the documentation login.

| Action | Method | Path |
| --- | --- | --- |
| Create device | POST | `/devices` |
| List devices and connection state | GET | `/devices` |
| Save and queue outbound SMS | POST | `/sms` |
| Get SMS/status | GET | `/sms/{messageId}` |

Create a device using `{"id":"tablet-1","name":"Office tablet"}`. HTTP 201 returns its ID and generated device token; store that token securely on the device because it cannot be retrieved later. A native device connects to `wss://sms.rosebakeshop.net/device?deviceId=tablet-1` with `Authorization: Bearer DEVICE_TOKEN` in its WebSocket handshake. Creating a device alone does not connect it or send messages.

Save an SMS using:

```json
{
  "requestId": "order-123-notification",
  "to": "+639171234567",
  "body": "Your order is ready.",
  "deviceId": "tablet-1"
}
```

Omit `deviceId` for automatic routing. **Saving queues the SMS and can immediately trigger sending through a connected device.** HTTP 202 means persisted/accepted, not delivered. Retrying identical recipient/body/requested device with the same `requestId` returns the saved message (HTTP 200); a different payload with the same ID returns 409. Use the returned `id`, not `requestId`, for status lookup. States are queued, dispatched, sent, failed, and unknown. Unknown messages are not automatically resent, and sent confirms device-reported sending rather than recipient delivery.

Swagger includes request validation, the 16 KiB JSON body limit, responses, and examples. Cross-origin browser execution requires deployment-level CORS support; the inspected Node HTTP handler does not itself implement CORS/OPTIONS handling. Backend clients can call the API directly.

### SMS dispatch pacing

The updated server paces each device at one dispatch attempt per 3 seconds (at most 20 jobs per minute), with one outstanding job at a time. Slow device results can reduce throughput. It waits 3 seconds after startup and retains device cooldowns through reconnects; failed/unknown attempts consume a slot. Waiting jobs remain queued and dispatch opportunities are checked every second. Duplicate POST requests return the existing job and do not create another job.

This is a single-process, in-memory device dispatch limit, not an HTTP request limit or a 429 response condition. POST /sms still returns 202 for a newly saved job and 200 for an identical repeated request. Its request fields and response shape have not changed. The pacing counts jobs, not multipart SMS segments or recipient delivery. Production behavior was not exercised while updating the documentation.

# Rose Bakeshop Keycloak API documentation

Keycloak supports creating users, editing users, setting/changing passwords, and enabling/disabling accounts through its Admin REST API.

See [setup.md](setup.md) for local PHP and Apache/XAMPP setup instructions and how to use both API specifications.

## Open Swagger UI

For local use, run `php -S localhost:8080 gateway.php` and open <http://localhost:8080/index.html>; no documentation password is required for loopback requests. For local Apache, copy `.htaccess.example` to `.htaccess` and set `DOCS_ENV local`. Production Apache defaults to requiring HTTP Basic Authentication, including direct requests to either OpenAPI JSON file. Follow [setup.md](setup.md) to configure a production bcrypt password file outside the web root and keep `DOCS_ENV production`. API bearer tokens in Swagger remain separate from the documentation login.

Deploy `index.html`, `openapi.json`, `itsupporthub.openapi.json`, `esigned-sso-setup.html`, `esigned-sso-setup.md`, and `gateway.php`. On initial Apache setup, copy `.htaccess.example` to `.htaccess` and configure your own absolute password file path. The local `.htaccess` is ignored by Git; the example is shared. Enable Apache's rewrite/env modules and `.htaccess` overrides as described in setup.md. Use HTTPS for deployed documentation. PHP 8.1+ is required. Previous static Python/Node preview commands do not enforce authentication and have been removed.

## Multiple APIs and Support Hub tickets

Each API has a separate OpenAPI file with its own server address and authentication scheme. Add more entries to `urls` in `index.html` to publish additional APIs in the selector. Also add the new specification to the protected file allowlist in `gateway.php`.

The Support Hub specification documents `POST https://itsupporthub.rosebakeshop.net/api/ticket`, using the request contract from the local create-ticket skill and client helper. This contract has not been verified against a live server schema. Example department/category IDs must be replaced with active compatible IDs from your application. Use a Support Hub API token in Authorize; its authentication is separate from the Keycloak documentation. The request requires requester name/email, department/category IDs, subject, and description. Optional fields include priority, ticket type, board, and assignee.

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

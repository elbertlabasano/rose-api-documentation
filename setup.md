# Local setup

This project serves Swagger documentation for Keycloak SSO and IT Support Hub. It does not run either API locally: requests executed from Swagger go to the configured production servers.

## Create documentation credentials first

Requires PHP 8.1 or newer. Use Apache's `htpasswd` tool to create a bcrypt password file **outside the document root**. For XAMPP, create `C:\xampp\security` if needed, then run:

```powershell
& 'C:\xampp\apache\bin\htpasswd.exe' -c -B -C 12 'C:\xampp\security\rose-docs.htpasswd' docsadmin
```

Enter your chosen password at the prompts. No default password is included. Use `-c` only when creating the file for the first time: it overwrites an existing file. To change the password or add another username, run the same command without `-c`. Restrict file access to the server account and administrators. Do not upload the password file into your website or commit it.

On Linux, an equivalent command is `htpasswd -c -B -C 12 /etc/apache2/rose-docs.htpasswd docsadmin`; use a location readable by your PHP worker and outside the web root.

## Option 1: PHP development server

Requires PHP installed and available in your terminal. No Composer dependencies, Python, or Node.js are needed.

1. Open PowerShell and enter the project directory:

   ```powershell
   cd C:\apps\jathnier\rose-api-documentation
   ```

2. Set the password file location and start the protected local server:

   ```powershell
   $env:DOCS_AUTH_FILE = 'C:\xampp\security\rose-docs.htpasswd'
   php -S localhost:8080 gateway.php
   ```

3. Open <http://localhost:8080/index.html>.

Keep the terminal running while using the documentation. Press **Ctrl+C** to stop the server. If port 8080 is occupied, use `php -S localhost:8081 gateway.php` and open <http://localhost:8081/index.html> instead.

If PowerShell cannot find `php`, use your PHP executable's full path. For XAMPP:

```powershell
$env:DOCS_AUTH_FILE = 'C:\xampp\security\rose-docs.htpasswd'
& 'C:\xampp\php\php.exe' -S localhost:8080 gateway.php
```

Run these commands from the project directory too. Always include `gateway.php`: the plain PHP static server does not enforce authentication. Do not use a Python or other static file server for this protected documentation.

## Option 2: Apache with XAMPP

1. Copy the project directory to `C:\xampp\htdocs\rose-api-documentation`.
2. Include the project's `.htaccess` and `gateway.php` files when copying. Check that `DOCS_AUTH_FILE` in `.htaccess` points to your password file; the default is `C:/xampp/security/rose-docs.htpasswd`.
3. Enable Apache `mod_rewrite` and `mod_env`, with `AllowOverride FileInfo Options` (or `AllowOverride All`) for this directory. PHP must be enabled. Restart Apache after changing its configuration. MySQL is not needed.
4. Open <http://localhost/rose-api-documentation/index.html>.

If Apache uses a different HTTP port, include it in the URL, for example `http://localhost:8080/rose-api-documentation/index.html`. If you edit the original project folder, copy the updated files into `htdocs` before refreshing.

For another Apache installation, place the documentation files in a folder under its configured document root and use the matching local URL.

## Required files

Keep these seven files together in the served directory:

```text
index.html
openapi.json
itsupporthub.openapi.json
esigned-sso-setup.html
esigned-sso-setup.md
gateway.php
.htaccess
```

The browser loads Swagger UI assets from a CDN, so it needs internet access. PHP authenticates requests before serving the UI or specifications. No database is required. The browser prompts for the documentation username and password; these are separate from API tokens in Swagger Authorize. Use HTTPS when deployed beyond localhost because Basic Authentication does not encrypt credentials. Browsers may remember credentials until closed.

## Read the SSO integration guide

After signing in, click **Laravel SSO Guide** in the definition dropdown or open <http://localhost:8080/esigned-sso-setup.html> when using the PHP development server. On Apache, use the same documentation directory followed by `esigned-sso-setup.html`. The page and its Markdown source require the same Basic Authentication as Swagger. The guide describes a proposed Laravel integration, not changes already applied to eSigned.

## Choose and use an API

Use the selector at the top of the page:

| Documentation | Configured API server | Specification |
| --- | --- | --- |
| Keycloak SSO — Users | `https://sso.rosebakeshop.net` | `openapi.json` |
| IT Support Hub — Tickets | `https://itsupporthub.rosebakeshop.net` | `itsupporthub.openapi.json` |

For Keycloak, replace `YOUR_REALM` in requests with your actual realm name. Use the authentication operation to obtain an authorized service account token, then paste its `access_token` into **Authorize**. See [README.md](README.md) for the required client permissions.

For Support Hub, select its documentation and enter your Support Hub API token in **Authorize**. Expand **POST /api/ticket** to review the create-ticket request. Replace the sample requester and department/category IDs with valid values for your application. The request contract comes from the existing client helper and has not been verified against a live server schema.

Paste tokens without the `Bearer` prefix. Click **Try it out** and **Execute** only when you intend to send the request. User changes and ticket creation affect the configured production systems. Running this documentation locally does not make the APIs local.

## Troubleshooting

- **503 authentication not configured:** Check `DOCS_AUTH_FILE` and that PHP can read the bcrypt password file.
- **Documentation loads without a password:** Stop serving it and check that `.htaccess` is enabled on Apache or `gateway.php` is used with the PHP development server. Test in a private browser window and request `openapi.json` directly too; unauthenticated requests must return 401.
- **Apache returns 500:** Check the error log for disabled rewrite/env modules or disallowed `.htaccess` directives.
- **Blank Swagger page:** Check internet access and the browser console for blocked CDN scripts or styles.
- **Failed to load API definition:** Keep the seven required files in the same directory. Check that `/openapi.json` and `/itsupporthub.openapi.json` load under the same local folder URL as `index.html`.
- **Failed to fetch / CORS error when executing:** The API server must allow requests from your local documentation origin. Serving Swagger locally does not bypass CORS. Use Postman or a trusted backend if browser requests are not allowed.
- **401 or 403:** Check the token for the selected API, its expiry, and its permissions.
- **Support Hub returns 422:** Review the returned validation errors and confirm requester details and active department/category/board IDs.

To point documentation at an existing development API, change `servers[].url` in the relevant specification. This only changes the request destination; starting a local Keycloak or Support Hub application requires that application's own source code and setup.

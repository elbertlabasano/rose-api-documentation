# Using Rose SSO in Laravel

Connect Laravel eSigned to Keycloak realm `jathnier` using client
`esigned`. Employees authenticate with Keycloak; their existing application
accounts, documents, roles, and permissions remain in eSigned. The managed
employee attribute `idcode` establishes an account link.

For Laravel developers integrating an application with the existing Rose SSO service.

This is a reference implementation, not confirmation of deployed changes.
The Laravel project has not been inspected. Adapt examples to its framework
version, authentication guard, employee relationships, and account eligibility
rules. The examples assume `App\Models\User`, the `web` guard, and `users.idcode`.

## 1 Connection settings

| Item | Value |
|---|---|
| Keycloak server | `https://sso.rosebakeshop.net` |
| Realm | `jathnier` |
| Client ID | `esigned` |
| Issuer | `https://sso.rosebakeshop.net/realms/jathnier` |
| Discovery | `https://sso.rosebakeshop.net/realms/jathnier/.well-known/openid-configuration` |
| Application | `https://esigned.rosebakeshop.net` |
| Proposed login route | `/auth/keycloak/redirect` |
| Proposed callback | `/auth/keycloak/callback` |
| Proposed logout route | `/auth/keycloak/logout` using POST |
| Employee claim | `idcode` as a single string |

Use the application-specific client ID and secret supplied by your SSO administrator. The values below illustrate eSigned; other Laravel applications need their own client and callback. Registering a callback with the SSO administrator does not create the Laravel route.

## 2 Login flow

1. An employee selects **Sign in with SSO** in eSigned.
2. Laravel redirects the browser to Keycloak with session-bound state and PKCE.
3. Keycloak authenticates the employee and returns an authorization code.
4. Laravel exchanges the code and requests UserInfo over HTTPS.
5. Laravel resolves an existing identity link, or matches exactly one eligible
   employee by `idcode` and records their Keycloak issuer and subject.
6. Laravel creates its normal session. Existing application permissions still
   control document access, signing actions, and administrator actions.

This does not automatically synchronize employees, create accounts, or grant roles.

## 3 Prerequisites from your SSO administrator

Before implementing Laravel login, obtain your application client ID, client secret, realm URL, and approved HTTPS callback URL. Ask your SSO administrator to confirm that the client supports the authorization code flow with S256 PKCE and returns `sub` plus a single string `idcode` in UserInfo.

Keep employee codes as strings, including leading zeros. Confirm that codes are unique and managed by authorized administrators; do not allow an employee to change the code used for account linking. The Laravel examples below consume these existing settings.

## 4 Laravel dependencies and environment

Run commands in the eSigned folder containing `artisan` and `composer.json`.
Use staging first and retain the existing login option during rollout.

```powershell
php artisan --version
php --version
composer require laravel/socialite socialiteproviders/keycloak
```

Review dependency compatibility and deploy the lock file through the normal
release process. The documentation does not install dependencies in eSigned.

Merge these values into the application's `.env`:

```dotenv
APP_URL=https://esigned.rosebakeshop.net
SSO_ENABLED=false
KEYCLOAK_BASE_URL=https://sso.rosebakeshop.net
KEYCLOAK_REALM=jathnier
KEYCLOAK_CLIENT_ID=esigned
KEYCLOAK_CLIENT_SECRET=REPLACE_WITH_THE_CLIENT_SECRET
KEYCLOAK_REDIRECT_URI=https://esigned.rosebakeshop.net/auth/keycloak/callback
SSO_IDCODE_CLAIM=idcode
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

Quote the secret according to dotenv syntax if its characters require it.
`SSO_ENABLED` and `SSO_IDCODE_CLAIM` are custom application settings defined below,
not built-in Laravel features. Use persistent session storage and a cookie name
unique to eSigned. Prefer host-only cookies. Coordinate cookie changes
because existing sessions may end.

Add this entry inside the returned array in `config/services.php`:

```php
'keycloak' => [
    'client_id' => env('KEYCLOAK_CLIENT_ID'),
    'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
    'redirect' => env('KEYCLOAK_REDIRECT_URI'),
    'base_url' => env('KEYCLOAK_BASE_URL'),
    'realms' => env('KEYCLOAK_REALM'),
],
```

The provider's key is `realms`, while the environment variable is singular.
Create `config/sso.php`:

```php
<?php

return [
    'enabled' => env('SSO_ENABLED', false),
    'idcode_claim' => env('SSO_IDCODE_CLAIM', 'idcode'),
];
```

For Laravel 11 and later, merge this listener into the existing `boot()` method
in `app/Providers/AppServiceProvider.php`:

```php
\Illuminate\Support\Facades\Event::listen(
    \SocialiteProviders\Manager\SocialiteWasCalled::class,
    function ($event) {
        $event->extendSocialite(
            'keycloak',
            \SocialiteProviders\Keycloak\Provider::class
        );
    }
);
```

For Laravel 10 and earlier, add this entry once to the existing `$listen` array
in `app/Providers/EventServiceProvider.php` instead:

```php
\SocialiteProviders\Manager\SocialiteWasCalled::class => [
    \SocialiteProviders\Keycloak\KeycloakExtendSocialite::class . '@handle',
],
```

Follow the [Manager installation instructions](https://socialiteproviders.com/usage)
for service-provider registration on the installed version. Ensure its Manager
service provider is registered, without duplicate managers or event listeners.
Provider reference: [Keycloak adapter setup](https://socialiteproviders.com/Keycloak/).

## 5 Local account preparation

The reference controller expects these fields on the authenticatable User model:

| Field | Purpose |
|---|---|
| `idcode` | Exact employee code as a string |
| `is_active` | Eligibility flag cast to boolean |
| `keycloak_issuer` | Linked realm issuer |
| `keycloak_subject` | Linked stable Keycloak `sub` |

Adapt lookup through the real employee relationship if the code lives in another
table. Use all existing eligibility rules, including suspension and termination.
Audit missing and duplicate codes and enforce uniqueness in the database where
business rules permit. Check case and whitespace comparisons; deny ambiguous matches.

Create a reviewed migration for identity columns. For a conventional `users`
table, the schema fragment is below. Add normal migration imports and rollback:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('keycloak_issuer', 191)->nullable();
    $table->string('keycloak_subject', 191)->nullable();
    $table->unique(
        ['keycloak_issuer', 'keycloak_subject'],
        'users_keycloak_identity_unique'
    );
});
```

Review nullable unique-index behavior for the actual database engine. Preserve
existing user IDs and document ownership. Cast `is_active` as boolean in the model
or replace the example's eligibility rule. Missing flags deliberately deny login.

An existing issuer and subject link takes precedence over code matching. Never
silently relink a user because a code changed. Recreated Keycloak users, reused
employee records, and soft-deleted accounts require administrator review.

## 6 Login callback and logout controller

Create `app/Http/Controllers/Auth/KeycloakController.php`. Adapt the model,
employee lookup, eligibility check, login error page, and landing page to the
application. The code below deliberately rejects missing or ambiguous identities.

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class KeycloakController extends Controller
{
    public function redirect()
    {
        abort_unless(config('sso.enabled'), 404);

        return Socialite::driver('keycloak')
            ->scopes(['openid', 'profile', 'email'])
            ->enablePKCE()->redirect();
    }

    public function callback(Request $request)
    {
        abort_unless(config('sso.enabled'), 404);

        try {
            $remote = Socialite::driver('keycloak')->enablePKCE()->user();
        } catch (Throwable $error) {
            // Exception messages can contain token responses; omit them here.
            Log::warning('SSO callback failed', [
                'exception_type' => get_class($error),
            ]);

            return redirect('/login')->withErrors([
                'sso' => 'SSO could not complete. Please try again.',
            ]);
        }

        $raw = $remote->getRaw();
        $code = $raw[config('sso.idcode_claim')] ?? null;
        $subject = $remote->getId();
        $issuer = rtrim(config('services.keycloak.base_url'), '/')
            . '/realms/' . config('services.keycloak.realms');

        abort_unless(
            is_string($code) && $code !== '' && trim($code) === $code
            && is_string($subject) && $subject !== '',
            403,
            'Your SSO account is not eligible for eSigned.'
        );

        $user = DB::transaction(function () use ($issuer, $subject, $code) {
            $user = User::where('keycloak_issuer', $issuer)
                ->where('keycloak_subject', $subject)
                ->lockForUpdate()->first();

            if (!$user) {
                $matches = User::where('idcode', $code)
                    ->lockForUpdate()->limit(2)->get();

                abort_unless($matches->count() === 1, 403,
                    'Your account is not eligible for eSigned.');

                $user = $matches->first();

                abort_unless(
                    $user->keycloak_issuer === null
                    && $user->keycloak_subject === null,
                    403,
                    'Contact support to review your account link.'
                );
            }

            // Replace with all existing account eligibility checks.
            abort_unless(
                $user->getAttribute('is_active') === true
                && (string) $user->idcode === $code,
                403,
                'Your account is not eligible for eSigned.'
            );

            if ($user->keycloak_subject === null) {
                $user->keycloak_issuer = $issuer;
                $user->keycloak_subject = $subject;
                $user->save();
            }

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // Replace with eSigned's actual fixed local landing page.
        return redirect('/');
    }

    public function logout(Request $request)
    {
        // Sign out of eSigned only; preserve the shared Rose SSO session.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
```

Keep the identity unique constraint to protect concurrent linking attempts.
Handle any resulting conflict as a generic denied or failed login; never retry
by attaching a different user. Local accounts are linked rather than recreated.

This adapter checks state, exchanges the code on the server, and requests
UserInfo from the fixed HTTPS realm. The stored issuer is derived from server
configuration, not the browser. Keep TLS verification enabled. If a different
library consumes ID tokens directly, it must validate signature, issuer,
audience, expiry, and any applicable nonce before trusting the claims.

Do not add `stateless()` to these browser routes. The session must retain state
and the PKCE verifier from redirect to callback. Do not log codes, client
secrets, full claims, or token responses.

References: [Laravel Socialite](https://laravel.com/docs/12.x/socialite),
[Socialite state and PKCE](https://github.com/laravel/socialite/blob/5.x/src/Two/AbstractProvider.php),
[Laravel session authentication](https://laravel.com/docs/12.x/authentication).

## 7 Routes and user interface

Merge these routes into `routes/web.php` without replacing existing routes:

```php
use App\Http\Controllers\Auth\KeycloakController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest:web')->group(function () {
    Route::get('/auth/keycloak/redirect', [KeycloakController::class, 'redirect'])
        ->name('sso.redirect');
    Route::get('/auth/keycloak/callback', [KeycloakController::class, 'callback'])
        ->name('sso.callback');
});

Route::post('/auth/keycloak/logout', [KeycloakController::class, 'logout'])
    ->middleware('auth:web')->name('sso.logout');
```

The web middleware stack must provide sessions. Retain CSRF protection on the
logout POST. Confirm `/login` exists for callback failures and replace the
controller's landing page with the correct local page. Do not place these
routes in a stateless API group.

On the existing login page, show **Sign in with SSO** only when
`config('sso.enabled')` is true and link it to `route('sso.redirect')`.
Display the `sso` validation error using the application's normal error component.
For logout, use a POST form targeting `route('sso.logout')` with Blade `@csrf`.
Keep existing password login available for the pilot.

This logout flow invalidates only the local eSigned session. It does not open
Keycloak logout or revoke the shared Rose SSO session. Selecting Rose SSO again
can reuse the central session without another password prompt when that session
and its browser cookies are still valid. Do not force `prompt=login` or
`max_age=0` for an ordinary sign-in. Keycloak policies may still require
reauthentication.

For a Vue SPA, restore the user from the authenticated session endpoint on direct
visits to home and login. Keep home accessible while signed in and show a
permission-appropriate **Go to dashboard** link. If a valid session visits
`/login`, redirect to an existing, permitted local intended page or the normal
application landing page. Do not start a new SSO flow merely to visit home or
`/login`. Preserve existing authorization checks and reject external redirects.

A logout or account disable in Keycloak alone does not automatically destroy
every existing Laravel session. Immediate central revocation requires a
separate backchannel logout or session revalidation implementation and tests.

## 8 Deployment and pilot testing

1. Confirm both domains have valid HTTPS and the Laravel server can reach
   Keycloak discovery, token, and UserInfo endpoints.
2. Review schema changes and account eligibility checks; apply migrations using
   the application's normal release process and a database backup.
3. If Laravel is behind a proxy, trust only the intended proxy and ensure
   Laravel recognizes HTTPS. Keep the session cookie stable during login.
4. Set `SSO_ENABLED=true` for a pilot and rebuild cached configuration:

   ```powershell
   php artisan config:cache
   php artisan route:list --path=auth/keycloak
   ```

5. Test with a normal employee in a private browser window. Keep administrator
   recovery access available. Deploy only after the following checks pass.

| Test | Expected result |
|---|---|
| Eligible employee with matching code | Existing account signs in with its current document ownership and permissions |
| Code with leading zeros | Exact value preserved throughout |
| Missing or array-valued code | Login denied |
| Unknown or duplicate local code | Login denied and no account created |
| Inactive or suspended employee | Existing eligibility policy denies access |
| Changed code or conflicting identity link | No silent relinking; administrator review |
| Altered state or reused code | Callback rejected |
| Standard employee opens an administrator page | Existing authorization still enforced |
| Logout POST | eSigned session ends; shared Keycloak session remains active |
| Home with a valid app session | Signed-in state is restored and a permitted dashboard link is shown |
| Login page with a valid app session | Redirects to a permitted local destination without a new SSO login |
| Sign in again after local sign-out | Reuses the existing Keycloak session when cookies and session policies allow |
| Keycloak unavailable | Controlled error and no unverified login |
| Second integrated application in the same realm | Keycloak browser session can be reused by that application's own client |
| Keycloak account disabled while Laravel session exists | Behavior matches the documented local-session revocation policy |

Automate callback tests using mocked provider results for valid, missing,
duplicate, inactive, and conflicting identities. Test real browser state,
PKCE, proxy behavior, HTTPS, and logout in staging separately.

## 9 Employee instructions

1. Open `https://esigned.rosebakeshop.net/` and select **Sign in with SSO**.
2. Use your assigned Keycloak username and password, then complete any password
   update or MFA prompt.
3. After successful login, your existing eSigned account opens.
4. If access is denied, ask support to review your employee code and eligibility.
   Do not create another account to bypass the error.
5. Use **Sign out** to leave eSigned. This does not sign you out of Rose SSO or
   other applications. On a shared computer, also use your organization's
   approved central SSO sign-out procedure and close the browser.

Adding `idcode` does not make it the login username. If employees should type
their code into Keycloak's login page, provision their Keycloak username as
that code under an agreed username policy. Account linking still uses `idcode`.

## 10 Troubleshooting

| Symptom | Check |
|---|---|
| Invalid redirect URI | Exact HTTPS callback in Keycloak, Laravel environment, and deployed routes |
| Driver keycloak not supported | Dependencies, Manager provider, event registration, configuration cache |
| Invalid client | Client ID, current secret, realm, client authentication and Standard flow |
| PKCE failure | Supported Socialite release, `enablePKCE()` in both methods, persistent session |
| Invalid state | Cookie host and name, HTTPS, session storage, parallel or repeated login attempts |
| Missing code claim | Correct user and mapper, Add to userinfo On, single string value |
| Account rejected | Exact code, unique local match, eligibility status cast, guard, existing identity link |
| Callback 404 | Laravel route, deployed code, route cache, Apache application routing |
| Callback 500 | Laravel logs, actual schema, eligibility rules; keep debug output private |
| App sign-out ends other SSO apps | Remove Keycloak logout calls or browser redirects from the app's sign-out flow |
| Immediate SSO login after app sign-out | Expected when the shared Keycloak session remains valid; do not automatically start SSO from the app login page |
| Credentials requested after local sign-out | Confirm the latest local-only logout is deployed; inspect Keycloak session expiry, browser cookies, and forced-reauthentication policies |
| PHP TLS error | Trusted CA bundle configuration; never disable certificate verification |

Application diagnostics from its project root:

```powershell
php artisan route:list --path=auth/keycloak
Get-Content .\storage\logs\laravel.log -Tail 80
```

Do not change Laravel's `APP_KEY` or disable state validation to resolve a callback failure. Review application logs for secrets before sharing them.

## 11 Laravel rollout and rollback

Back up the application database, including identity links, before applying migrations. Keep existing password login available during the pilot and deploy reviewed migrations and the Composer lock file through the normal release process.

To disable new SSO logins, set `SSO_ENABLED=false` and run `php artisan config:cache`. Retain existing password login and identity-link data. Disabling the entry point does not invalidate existing sessions; follow your application's session-revocation policy if needed. Do not delete users, documents, or signing records during rollback.

Before applying the examples, confirm the actual Laravel version, authentication model and guard, employee code relationship, account eligibility rules, login and landing routes, database engine, and approved linking policy.

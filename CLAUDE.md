# CLAUDE.md

Guidance for working on `cssllc-uptimerobot.php` in this repo.

## What this is

A single-file PHP mu-plugin that combines an HTTP status check and a DNS record check behind one URL so UptimeRobot can alert on both with a single monitor. No framework, no dependencies (beyond WordPress core). Registers a REST route at `/wp-json/cssllc/v1/uptimerobot`. REST routes register against WP's existing `wp-json` rewrite rule, so no rewrite-rule flush is needed. There is no standalone (non-WordPress) mode — it's mu-plugin only.

Everywhere except local environments (`wp_get_environment_type() !== 'local'`), requests authenticate via HTTP Basic auth + a WordPress application password (core handles the authentication itself). `permission_callback` then authorizes via `current_user_allowed()`, checking the authenticated user's login/email against `allowed_users()`. A non-match returns `401` as WP's standard `rest_forbidden` REST error, so the rejection is formatted the same way core does. Point UptimeRobot's monitor at the route using HTTP Basic auth with an allowed user's application password. Local environments skip auth entirely — `permission_callback` short-circuits to `true` when the environment is `local`.

The response body is `{ "status": <int> }` on 200 (no `failures` key), or `{ "status": <int>, "failures": [ { "check", ...properties, "error" } ] }` on 503/500 — `failures` holds exactly the one check that failed (or errored), flattened: `check` (class name) and `error` (exception detail, or `null`) alongside the check's own configured properties (`url`, `host`, `expected`, etc.) all at the top level, built via `describe_check_failure()`. Checking stops at the first failure rather than running the remaining checks, since one failure at a time is sufficient. Safe to include full config since the endpoint requires authentication, and it's the only record since nothing is logged server-side — meant to be read from UptimeRobot's own failure history.

Canonical copy lives in the repository (it started as a secret Gist, which now just points here):
https://github.com/cssllc/cssllc-uptimerobot

## Conventions to follow when editing

- **PHP 8.0+** — named parameters are used throughout `$checks` construction; don't downgrade to positional args.
- Everything lives under the `CSSLLC\UptimeRobot` namespace. Don't reintroduce `cssllc_`-prefixed global names for anything inside it — that redundancy is what the namespace replaced.
- Checks are configured per site, not in this file: `checks()` returns `apply_filters( 'cssllc/uptimerobot/checks', [] )`, and each site adds its checks from a separate `wp-content/mu-plugins/cssllc-uptimerobot.config.php`, so re-pulling this file never wipes them. Config files must construct checks inside the filter callback, because `cssllc-uptimerobot.config.php` sorts (and loads) before `cssllc-uptimerobot.php`. `checks()`'s `array` return type is what turns a bad filter return into a caught `TypeError` (500); keep it. `checks()` and its docblock (the config example) stay near the top of the file, right after the namespace declaration and before the check-type classes, so they're the first thing anyone opening the file sees.
- `checks_status()` is a pure function — it returns a `CheckResult` (status int + an `array<int,array<string,mixed>>` of `$failures`, either empty or one entry) and has no output side effects (no echo/print, no `error_log()` — nothing is logged server-side). It stops at the first failing (or errored) check rather than running the rest — one failure at a time is sufficient, and the response is the only record of it. Checks share a `TIME_BUDGET` (20s, under UptimeRobot's default 30s timeout): each gets the remaining time as `passes( $timeout )`, HTTP checks cap their curl timeouts to it, and a check with under a second left isn't started (500, `error` says why). `checks()` is called inside the `try`, and a non-`HealthCheck` entry throws, so config mistakes come back as a 500 with `check: null` rather than a fatal. Authentication/authorization and how the result gets delivered (the REST envelope) both live in the entry point at the bottom, per context — don't move that logic into `checks_status()`.
- `describe_check_failure()` builds a flat associative array from a `HealthCheck`: bare class name keyed as `check`, `get_object_vars($check)` merged in at the top level (not nested under a `details` key), plus `error` (exception message, or `null`). It's a plain array, not a dedicated class — there's nothing to encapsulate beyond what `get_object_vars()` already gives you. The class name is keyed `check`, not `type`, because `DnsRecordCheck` already has its own `type` property (`'A'`, `'MX'`, etc.) — using `type` for both would collide when flattened into one array.
- The application password named `APP_PASSWORD_NAME` (`UptimeRobot`) is locked to this endpoint, in both directions. A `wp_authenticate_application_password_errors` hook rejects it at authentication time on any request where `is_endpoint_request()` is false (other REST routes, the batch endpoint, XML-RPC). `permission_callback` also requires `authenticated_with_endpoint_password()`, so no other application password opens the endpoint, and renaming or revoking the `UptimeRobot` one makes the monitor fail visibly instead of quietly lifting the lock. The lock runs in every environment, not just production. `is_endpoint_request()` reads `$GLOBALS['wp']->query_vars['rest_route']`, which is safe because core only accepts application passwords on REST once `rest_api_loaded()` has defined `REST_REQUEST`, by which point that query var is set; it compares exactly, failing closed on anything else (e.g. different case). On other routes the rejection usually surfaces as `rest_not_logged_in`, not the lock's own error code: core runs `rest_authentication_errors` before the current user is lazily determined, so the app-password error isn't collected yet. Verified against WordPress 7.1.3. Don't replace this with a dedicated low-privilege user; that was considered and rejected.
- Authorization is by application-password-authenticated identity, not IP. `current_user_allowed()` checks the current user (set by core's application-password auth on the Basic-auth header) against `allowed_users()` (usernames and/or email addresses). Don't reintroduce IP-based gating — application passwords are the intended access control now.
- Allowed users are configured per site, not in this file: `allowed_users()` returns the `cssllc/uptimerobot/allowed_users` filter, set in the same `cssllc-uptimerobot.config.php` as the checks. It replaced a hard-coded `ALLOWED_USER_IDENTIFIERS` constant so the public repo doesn't publish whose accounts matter on client sites. It starts empty and falls back to empty on a non-array filter result, so an unconfigured site fails closed (401 outside local) instead of fatally. Don't put real usernames or emails back in this file, the README, or the template.
- Auth is enforced in every environment except `local` — `permission_callback` short-circuits to `true` only when `wp_get_environment_type() === 'local'`. It used to exempt everything but `production`, which meant a live site mislabelled `staging`/`development` (e.g. a staging `WP_ENVIRONMENT_TYPE` carried over at launch) was silently unlocked. Only `local` is exempt now, which no server should carry, so manual checks on a local machine stay easy. Staging/dev copies made from production carry the `UptimeRobot` application password in the database, so they need no extra setup. Requiring auth everywhere (no exemption at all) was considered; `local` was kept for convenience.
- `error_reporting( 0 )` / `ini_set( 'display_errors', '0' )` live inside `checks_status()`, not at file scope. Keeping them at file scope would suppress PHP error reporting/display for *every* request on the site when loaded as an mu-plugin (mu-plugins load unconditionally on every request), not just the monitor's own check.
- **WordPress Coding Standards (WPCS)** spacing: space inside parentheses and array brackets — `if ( $x ) {`, `foo( $a, $b )`, `[ 'a', 'b' ]`. Array offset access (`$record['target']`) does NOT get inner spaces.
- **Yoda conditionals** — constant/literal on the left: `if ( false === $records )`, not `if ( $records === false )`.
- **Aligned `=>`** in multi-line arrays.
- **`match`, not `switch`** — combine arms with shared logic using comma-separated conditions.
- Every class/interface/method gets a docblock; constructor docblocks use `@param` per promoted property.
- New check types implement the `HealthCheck` interface (`passes( float $timeout ): bool` — curl-based checks must respect `$timeout`) rather than being bolted onto `UrlCheck` or `DnsRecordCheck`.
- `UrlCheck` uses GET (body discarded via `CURLOPT_WRITEFUNCTION`), not HEAD: WordPress exits HEAD requests after `template_redirect` (`exit_on_http_head`), before the theme template renders, so HEAD returns 200 even when a template fatals. `RedirectCheck` stays HEAD, since redirects happen before that point. Both HTTP checks send `USER_AGENT`; curl sends none by default, and WAFs often block that.
- Real REST responses (`WP_REST_Response` for the checks route, `WP_Error` only for the `permission_callback` rejection) so WP's REST server formats the JSON envelope the way core does — don't hand-roll JSON there. The checks route always returns `WP_REST_Response` (even on 503/500) with `status`, and `failures` (the array from `CheckResult::$failures`, passed straight through) added to the body only when non-empty (i.e. never on 200) — it's not an "error" from the REST server's perspective, just an application-level result with a non-200 status. It carries `wp_get_nocache_headers()` explicitly, since core only sends those to logged-in users and auth is skipped on local.
- File carries a standard WordPress plugin header (`Plugin Name`, `Plugin URI`, `Description`, `Author`) so it displays properly on the Must-Use Plugins screen. Deliberately no `Version` header — the repository is the single source of truth, no per-site version to track.

## Testing changes

Run `php -l cssllc-uptimerobot.php` before deploying.

## Deployment

See `README.md` for the install command. This is a one-way pull to a site's `wp-content/mu-plugins/` — the repository is the source of truth, not something edited per-site. Per-site checks live in `cssllc-uptimerobot.config.php` next to it, which the pull never touches.

## Fixed since initial draft

- **`CheckFailure` (the class) is gone — flattened into a plain array.** Its `details` sub-object was unnecessary nesting: `describe_check_failure()` now returns one flat associative array with `check` (class name) and `error` merged alongside the check's own `get_object_vars()` properties, instead of wrapping them in a dedicated class + a nested `details` key. Renamed the class-name field from `type` to `check` in the process, since `DnsRecordCheck` has its own `type` property that would otherwise collide once flattened into the same array.
- **`label` was dropped from `CheckFailure` and `HealthCheck` entirely.** Once `CheckFailure` carried full `type` + `details`, the separate `label()` identifier (and the interface method + four per-class implementations behind it) was redundant dead weight — `type` + `details` already say which check it is. Removed `HealthCheck::label()` and every implementation.
- **`CheckFailure` now carries full check configuration** (`type`, `details` from `get_object_vars($check)`, and `error` for the exception case), not just `label`. Since the endpoint is authenticated and there's no server-side error logging, the response is the only record of a failure — and UptimeRobot retains that response in its own failure history, so it's worth putting everything there rather than just an identifying label.
- **The `checks` response key was renamed to `failures`, and each entry's `passed` field was dropped.** Since that key is only ever present (non-empty) when a check failed, `"passed": false` on every entry was dead weight — `failures: [ { label } ]` says the same thing with less noise. `CheckOutcome` (label + passed) was renamed `CheckFailure` (label only) to match.
- **`checks_status()` briefly ran every configured check unconditionally** (rather than stopping at the first failure) so the response could report on all of them, and logged failures/errors server-side via `error_log()` and `describe_check()`. Reverted both: one failure at a time is sufficient, so it stops (early-return) at the first failing/errored check again, and `describe_check()`/`error_log()` calls are gone entirely — no server-side logging, the response is the only record.
- **The response used to be a single pass/fail signal** — `true` on 200, or one `failure` string (the first failing check's description) on 503/500 — with no way to identify *which* check failed without exposing its full config. Now every `HealthCheck` implements `label()` (a short, non-sensitive identifier — type + primary target, no expected values or PII), and the REST callback always returns a `WP_REST_Response` of `{ status, checks }`, where `checks` holds the one `CheckOutcome` for whichever check failed (or is empty on 200).
- **Standalone (non-WordPress) mode and IP-based gating were dropped.** The
  file used to run two ways (bare document-root script or mu-plugin) and
  gated requests by resolving `ip.uptimerobot.com` and comparing against
  `REMOTE_ADDR`. Standalone mode was never actually used, and IP gating is
  more fragile than real authentication (depends on DNS resolving correctly
  per request, and UptimeRobot's IP list changing over time). Replaced with:
  mu-plugin only, and access control via HTTP Basic auth + WordPress
  application passwords, authorized by `current_user_allowed()` matching
  against `ALLOWED_USER_IDENTIFIERS` (since replaced by the `allowed_users()`
  filter). `request_ip_allowed()` is gone.
- **`checks_status()` used to return a bare `int`**, and both entry points
  returned only the HTTP status with no body — the failing check couldn't be
  identified from the response, only from server-side logs (and even that
  wasn't recorded until an `error_log()` call was added). Since the endpoint
  is already IP-gated to UptimeRobot's own IPs, there's no reason to withhold
  which check failed from the response itself. `checks_status()` now returns
  a `CheckResult` (status + a `failure` description built by
  `describe_check()`), and both entry points surface that description —
  standalone as a plain-text body, mu-plugin as the `WP_Error` message —
  so it shows up directly in UptimeRobot's dashboard.
- **`error_reporting( 0 )` / `ini_set( 'display_errors', '0' )` were at file
  scope**, which meant loading this file as an mu-plugin suppressed PHP
  error reporting/display for *every* request on the site (mu-plugins load
  unconditionally on every request), not just the monitor's own check.
  Moved both calls inside `run_checks()` so they only apply while a check
  is actually running.
- **The convention line above previously said the opposite of this** — it
  claimed `$checks` belonged inline inside `run_checks()`, and an earlier
  pass moved the array there to match. That was backwards: the top-level
  `checks()` function is the intended, requested design (easy to find and
  edit without scrolling past the check-type classes), so it's back at the
  top of the file and the convention note above now matches it.
- **Added a standard WordPress plugin header** (`Plugin Name`, `Plugin URI`,
  `Description`, `Author`) so the file displays properly on the Must-Use
  Plugins screen when dropped into `wp-content/mu-plugins/`. Deliberately
  no `Version` header — the repository is the single source of truth (see
  Deployment above) and there's no per-site version to track.
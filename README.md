# cssllc-uptimerobot.php

Single-file PHP health check WordPress mu-plugin for UptimeRobot. Checks a set of URLs (status code, optional redirect-following), URL redirects (target location, following the whole redirect chain by default), DNS records (host, type, expected values), and WordPress users (email, role), and returns one HTTP status: `200` if everything passes, `503` if any check fails, `500` on an unexpected error, `401` if the request isn't authenticated as an allowed user (except in local environments — see Authentication below).

Served as a REST route. Checks run in order and stop at the first failure — one at a time is sufficient, so the response names only that one:

```json
{
  "status": 503,
  "failures": [
    {
      "check": "RedirectCheck",
      "url": "https://www.example.com",
      "expected": "https://example.com",
      "followRedirects": true,
      "error": null
    }
  ]
}
```

On `200` the body is just `{ "status": 200 }` — no `failures` key. `check` names the check class, the check's own configured properties (`url`, `host`, `expected`, etc.) are flattened in alongside it, and `error` carries the exception message on a `500` (`null` otherwise). If `checks()` itself throws or returns something that isn't a check, `check` is `null`.

All checks share a 20-second time budget (`TIME_BUDGET`), so a slow run still returns a response naming the check rather than timing out UptimeRobot's request. A check left with under a second isn't run, and comes back as a `500` with `error` saying so. The response is sent with no-cache headers so a page cache or CDN can't serve a stale result. Nothing is logged server-side, so this is the only record of a failure — that's deliberate, since it means UptimeRobot's own failure history captures the full detail.

Source: https://github.com/cssllc/cssllc-uptimerobot

## Install

Run from `wp-content/mu-plugins/`:

```bash
curl -o cssllc-uptimerobot.php \
  https://raw.githubusercontent.com/cssllc/cssllc-uptimerobot/master/cssllc-uptimerobot.php

# First install only: start the site's config from the template.
[ -e cssllc-uptimerobot.config.php ] || curl -o cssllc-uptimerobot.config.php \
  https://raw.githubusercontent.com/cssllc/cssllc-uptimerobot/master/cssllc-uptimerobot.config.template.php
```

The first command is also how to update: it replaces the plugin and never touches `cssllc-uptimerobot.config.php`. The second only runs if that file doesn't exist yet.

Registers a REST route, served at `/wp-json/cssllc/v1/uptimerobot`. No rewrite flush needed.

Then edit `cssllc-uptimerobot.config.php` for the site (see Configure). Until it lists at least one allowed user, the endpoint rejects every request outside local environments.

## Configure

Each site is configured in a separate mu-plugin file, `wp-content/mu-plugins/cssllc-uptimerobot.config.php`, which sets who may call the endpoint and what it checks. Because it's a separate file, re-pulling `cssllc-uptimerobot.php` never overwrites a site's configuration. Start from `cssllc-uptimerobot.config.template.php`, which has a sample of every check type and option.

Allowed users go in the `cssllc/uptimerobot/allowed_users` filter, as usernames and/or email addresses matched exactly against the user's login or email:

```php
add_filter( 'cssllc/uptimerobot/allowed_users', static fn( array $users ) => [
	...$users,
	'example-user',
	'user@example.com',
] );
```

Checks go in the `cssllc/uptimerobot/checks` filter:

```php
<?php
namespace CSSLLC\UptimeRobot;

add_filter( 'cssllc/uptimerobot/checks', static fn( array $checks ) => [
	...$checks,
	new UrlCheck(
		url: 'https://example.com/',
		statusCodes: [ 200 ],
	),
	new RedirectCheck(
		url: 'https://www.example.com',
		expected: 'https://example.com',
	),
] );
```

Build the checks inside the callback, not at file scope. This file loads before the plugin (mu-plugins load alphabetically), so the check classes don't exist yet when it's included. The callback only runs when the endpoint is hit.

See the `checks()` docblock near the top of the plugin for `DnsRecordCheck`, `RedirectCheck`, `UrlCheck`, and `WordPressUserCheck` examples. `UrlCheck` sends a GET, not HEAD: WordPress exits HEAD requests before the theme template renders, so HEAD would miss a fatal error in a template.

## Authentication

Everywhere except **local** environments (`wp_get_environment_type() !== 'local'`), requests must authenticate via HTTP Basic auth using a WordPress application password for a user listed in the site's `cssllc/uptimerobot/allowed_users` filter (see Configure). Create the application password under that user's profile (Users → Profile → Application Passwords) and name it exactly **`UptimeRobot`**. Then give UptimeRobot the username and generated password as HTTP Basic auth credentials.

The `UptimeRobot` application password is locked to this endpoint. WordPress refuses it everywhere else, including the rest of the REST API and XML-RPC, so if it ever leaks it can't be used to act as your user. The reverse also holds: the endpoint only accepts that password, not your other application passwords. Renaming or revoking it makes the monitor fail, so the lock can't be lifted without you noticing. The lock applies in every environment, since copying a production database to staging copies its application passwords too.

**Local** environments skip authentication entirely, so you can call the endpoint by hand there. Staging and development sites require it like production does, so a live site mislabelled as staging stays locked. Copies made from the production database already include the `UptimeRobot` application password; a staging site built from scratch needs its own.

Anything unauthenticated (outside local), or authenticated with any application password other than `UptimeRobot`, gets a `401`, formatted as WP's standard `rest_forbidden` REST error.

## Point UptimeRobot at it

Create a single HTTP(s) monitor targeting:

`https://example.com/wp-json/cssllc/v1/uptimerobot`

Set the monitor's authentication to HTTP Basic auth with the allowed user's login and application password.

Expect `200`. Any other code (or no response) triggers UptimeRobot's normal alert.

## Check the response manually

```bash
curl -s https://example.com/wp-json/cssllc/v1/uptimerobot
```

Anywhere but a local environment, pass the `UptimeRobot` application password:

```bash
curl -s -u 'example-user:xxxx xxxx xxxx xxxx xxxx xxxx' \
  https://example.com/wp-json/cssllc/v1/uptimerobot
```
<?php
/**
 * Per-site configuration for the CSSLLC UptimeRobot Monitor mu-plugin: who may
 * call the endpoint, and what it checks.
 *
 * Copy to wp-content/mu-plugins/cssllc-uptimerobot.config.php, next to
 * cssllc-uptimerobot.php, and replace the sample values below with this
 * site's. Re-pulling cssllc-uptimerobot.php never touches this file.
 */

namespace CSSLLC\UptimeRobot;

// ---- Allowed users --------------------------------------------------------

// Usernames and/or email addresses of the users allowed to call the endpoint,
// each authenticating with an application password named "UptimeRobot".
// Matched exactly against the user's login or email. With none listed, the
// endpoint rejects every request, except in local environments.
add_filter( 'cssllc/uptimerobot/allowed_users', static fn( array $users ): array => [
	...$users,
	'example-user',
	'user@example.com',
] );

// ---- Checks ---------------------------------------------------------------

// Checks run top to bottom and stop at the first failure, within a shared
// 20-second budget — so order them by importance.
//
// Build checks inside the callback, not at file scope: this file sorts (and
// so loads) before cssllc-uptimerobot.php, so the check classes don't exist
// yet when it's included. The callback runs only when the endpoint is hit.
add_filter( 'cssllc/uptimerobot/checks', static fn( array $checks ): array => [
	...$checks,

	// ---- URLs: status code (GET) ------------------------------------------

	// Homepage responds 200, without following redirects.
	new UrlCheck(
		url: 'https://www.example.com/',
		statusCodes: [ 200 ],
	),

	// Shop page responds 200 once any redirects are followed.
	new UrlCheck(
		url: 'https://www.example.com/shop/',
		statusCodes: [ 200 ],
		followRedirects: true,
	),

	// ---- Redirects: where a URL ends up -----------------------------------

	// Apex redirects to www, comparing the end of the whole redirect chain.
	new RedirectCheck(
		url: 'https://example.com',
		expected: 'https://www.example.com',
	),

	// http:// upgrades to https:// in a single hop (first Location header only).
	new RedirectCheck(
		url: 'http://www.example.com',
		expected: 'https://www.example.com',
		followRedirects: false,
	),

	// ---- DNS: every expected value must be among the records ---------------

	// A and AAAA compare exactly; write IPv6 compressed and lowercase, as
	// dns_get_record() returns it.
	new DnsRecordCheck(
		host: 'example.com',
		type: 'A',
		expected: [ '192.0.2.10' ],
	),
	new DnsRecordCheck(
		host: 'example.com',
		type: 'AAAA',
		expected: [ '2001:db8::10' ],
	),

	// CNAME, MX, and NS ignore case and a trailing dot.
	new DnsRecordCheck(
		host: 'www.example.com',
		type: 'CNAME',
		expected: [ 'hosting.example.net' ],
	),
	new DnsRecordCheck(
		host: 'example.com',
		type: 'MX',
		expected: [ 'mx1.example.net', 'mx2.example.net' ],
	),
	new DnsRecordCheck(
		host: 'example.com',
		type: 'NS',
		expected: [ 'ns1.example.net', 'ns2.example.net' ],
	),

	// TXT compares the full record value exactly.
	new DnsRecordCheck(
		host: 'example.com',
		type: 'TXT',
		expected: [ 'v=spf1 include:_spf.example.net ~all' ],
	),

	// ---- WordPress users ---------------------------------------------------

	// A user with this email exists and has this role.
	new WordPressUserCheck(
		email: 'admin@example.com',
		role: 'administrator',
	),
] );

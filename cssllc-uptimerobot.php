<?php
/**
 * Plugin Name: CSSLLC UptimeRobot Monitor
 * Plugin URI: https://github.com/cssllc/cssllc-uptimerobot
 * Description: Single combined health check for UptimeRobot — checks a set of URLs, DNS records, redirects, and WordPress users, returning one HTTP status (200 pass, 503 fail, 500 error). Served at /wp-json/cssllc/v1/uptimerobot.
 * Author: Caleb Stauffer
 *
 * WordPress mu-plugin only: drop in wp-content/mu-plugins/, checks are served
 * at /wp-json/cssllc/v1/uptimerobot. Everywhere except local environments, requests must
 * authenticate as an allowed user via a WordPress application password
 * named "UptimeRobot" (HTTP Basic auth) — point UptimeRobot's HTTP Basic auth
 * at that user/application-password pair. That password works only on this
 * endpoint, everywhere else it's rejected. Local environments skip auth.
 *
 * Response body: `{ "status": <int> }`, plus `"failures": [ { "check", ...its
 * own properties, "error" } ]` (the one check that failed or errored) when
 * status isn't 200 — full check configuration included (no server-side
 * logging, so this is the only record; safe since the endpoint requires
 * authentication).
 *
 * 200 if all checks pass.
 * 401 if the request isn't authenticated as an allowed user (except local).
 * 503 if any check fails.
 * 500 on unexpected script error.
 *
 * @link https://github.com/cssllc/cssllc-uptimerobot
 *
 * @author Caleb Stauffer
 * @author Claude
 */

namespace CSSLLC\UptimeRobot;

// ---- Configuration ----------------------------------------------------

/**
 * Checks to run. Each entry implements HealthCheck.
 *
 * Configured per site via the 'cssllc/uptimerobot/checks' filter, in a
 * separate mu-plugin file (wp-content/mu-plugins/cssllc-uptimerobot.config.php,
 * see cssllc-uptimerobot.config.template.php)
 * so re-pulling this file never overwrites a site's checks:
 *
 *     <?php
 *     namespace CSSLLC\UptimeRobot;
 *
 *     add_filter( 'cssllc/uptimerobot/checks', static fn( array $checks ) => [
 *         ...$checks,
 *         new DnsRecordCheck(
 *             host: 'example.com',
 *             type: 'A',
 *             expected: [ '93.184.216.34' ],
 *         ),
 *         new RedirectCheck(
 *             url: 'https://example.com',
 *             expected: 'https://www.example.com',
 *         ),
 *         new UrlCheck(
 *             url: 'https://example.com/',
 *             statusCodes: [ 200 ],
 *         ),
 *         new WordPressUserCheck(
 *             email: 'admin@example.com',
 *             role: 'administrator',
 *         ),
 *     ] );
 *
 * Construct checks inside the callback, not at file scope: that file sorts
 * (and so loads) before this one, so the check classes don't exist yet when
 * it's included. The callback runs only when the endpoint is hit.
 *
 * @return HealthCheck[]
 */
function checks(): array {
	return apply_filters( 'cssllc/uptimerobot/checks', [] );
}

// ---- Check types ----------------------------------------------------

/**
 * User-Agent sent by the HTTP checks. curl sends none by default, and many
 * WAFs (Cloudflare, Wordfence, etc.) reject requests without one.
 *
 * @var string
 */
const USER_AGENT = 'CSSLLC UptimeRobot Monitor';

/**
 * Contract for a single health check.
 */
interface HealthCheck {

	/**
	 * Whether this check currently passes.
	 *
	 * @param float $timeout Seconds this check may take — what's left of TIME_BUDGET.
	 * @return bool
	 */
	public function passes( float $timeout ): bool;
}

/**
 * Checks that a host's DNS records contain a set of expected values.
 */
final class DnsRecordCheck implements HealthCheck {

	/**
	 * Map of supported record type strings to their PHP DNS constants.
	 *
	 * @var array<string, int>
	 */
	private const TYPE_MAP = [
		'A'     => DNS_A,
		'AAAA'  => DNS_AAAA,
		'CNAME' => DNS_CNAME,
		'MX'    => DNS_MX,
		'TXT'   => DNS_TXT,
		'NS'    => DNS_NS,
	];

	/**
	 * @param string   $host     Hostname to look up.
	 * @param string   $type     Record type, e.g. 'A', 'MX'.
	 * @param string[] $expected Values that must all be present in the result.
	 */
	public function __construct(
		public readonly string $host,
		public readonly string $type,
		public readonly array $expected,
	) {}

	/**
	 * Lowercase and strip the trailing dot, since hostnames are case-insensitive
	 * and may be written fully qualified.
	 *
	 * @param string $host Hostname to normalize.
	 * @return string
	 */
	private static function normalize_hostname( string $host ): string {
		return strtolower( rtrim( $host, '.' ) );
	}

	/**
	 * $timeout is unused: dns_get_record() has no timeout control.
	 *
	 * @param float $timeout Seconds this check may take.
	 * @return bool
	 */
	public function passes( float $timeout ): bool {
		$type_upper = strtoupper( $this->type );

		if ( ! isset( self::TYPE_MAP[ $type_upper ] ) ) {
			return false;
		}

		$records = @dns_get_record( $this->host, self::TYPE_MAP[ $type_upper ] );

		if ( false === $records || empty( $records ) ) {
			return false;
		}

		$actual = [];
		foreach ( $records as $record ) {
			$actual[] = match ( $type_upper ) {
				'A'                 => $record['ip'] ?? null,
				'AAAA'              => $record['ipv6'] ?? null,
				'CNAME', 'MX', 'NS' => self::normalize_hostname( $record['target'] ?? '' ),
				'TXT'               => $record['txt'] ?? null,
				default             => null,
			};
		}
		$actual = array_filter( $actual, static fn( $v ) => null !== $v );

		$expected_normalized = in_array( $type_upper, [ 'CNAME', 'MX', 'NS' ], true )
			? array_map( [ self::class, 'normalize_hostname' ], $this->expected )
			: $this->expected;

		// Every expected value must be present among the actual records.
		foreach ( $expected_normalized as $exp ) {
			if ( ! in_array( $exp, $actual, true ) ) {
				return false;
			}
		}

		return true;
	}
}

/**
 * Checks that a URL redirects to an expected target URL.
 */
final class RedirectCheck implements HealthCheck {

	/**
	 * @param string $url             URL to request.
	 * @param string $expected        Target URL the request must arrive at.
	 * @param bool   $followRedirects Whether to follow the whole redirect chain and compare against the final URL, rather than checking only the first hop's Location header.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $expected,
		public readonly bool $followRedirects = true,
	) {}

	/**
	 * @param float $timeout Seconds this check may take.
	 * @return bool
	 */
	public function passes( float $timeout ): bool {
		$ch = curl_init( $this->url );

		if ( false === $ch ) {
			return false;
		}

		curl_setopt_array( $ch, [
			CURLOPT_NOBODY            => true,
			CURLOPT_FOLLOWLOCATION    => $this->followRedirects,
			CURLOPT_MAXREDIRS         => 10,
			CURLOPT_TIMEOUT_MS        => (int) ( min( 10, $timeout ) * 1000 ),
			CURLOPT_CONNECTTIMEOUT_MS => (int) ( min( 5, $timeout ) * 1000 ),
			CURLOPT_RETURNTRANSFER    => true,
			CURLOPT_SSL_VERIFYPEER    => true,
			CURLOPT_USERAGENT         => USER_AGENT,
		] );
		curl_exec( $ch );
		$code     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$location = $this->followRedirects
			? curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL )
			: curl_getinfo( $ch, CURLINFO_REDIRECT_URL );
		$err      = curl_errno( $ch );
		curl_close( $ch );

		if ( 0 !== $err ) {
			return false;
		}

		if ( $this->followRedirects ) {
			if ( $code < 200 || $code >= 300 ) {
				return false;
			}
		} elseif ( $code < 300 || $code >= 400 ) {
			return false;
		}

		return rtrim( $this->expected, '/' ) === rtrim( $location, '/' );
	}
}

/**
 * Checks that a URL responds with one of a set of accepted status codes.
 *
 * Uses GET, not HEAD: WordPress exits HEAD requests right after
 * template_redirect (see exit_on_http_head), before the theme template
 * renders — so a fatal in a template would 500 on GET but still 200 on HEAD.
 * The body is discarded as it streams in.
 */
final class UrlCheck implements HealthCheck {

	/**
	 * @param string $url             URL to request.
	 * @param int[]  $statusCodes     HTTP status codes considered a pass.
	 * @param bool   $followRedirects Whether to follow HTTP redirects.
	 */
	public function __construct(
		public readonly string $url,
		public readonly array $statusCodes,
		public readonly bool $followRedirects = false,
	) {}

	/**
	 * @param float $timeout Seconds this check may take.
	 * @return bool
	 */
	public function passes( float $timeout ): bool {
		$ch = curl_init( $this->url );

		if ( false === $ch ) {
			return false;
		}

		curl_setopt_array( $ch, [
			CURLOPT_FOLLOWLOCATION    => $this->followRedirects,
			CURLOPT_TIMEOUT_MS        => (int) ( min( 10, $timeout ) * 1000 ),
			CURLOPT_CONNECTTIMEOUT_MS => (int) ( min( 5, $timeout ) * 1000 ),
			CURLOPT_ENCODING          => '',
			CURLOPT_WRITEFUNCTION     => static fn( $ch, string $data ): int => strlen( $data ),
			CURLOPT_SSL_VERIFYPEER    => true,
			CURLOPT_USERAGENT         => USER_AGENT,
		] );
		curl_exec( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err  = curl_errno( $ch );
		curl_close( $ch );

		if ( 0 !== $err ) {
			return false;
		}

		return in_array( $code, $this->statusCodes, true );
	}
}

/**
 * Checks that a WordPress user exists with a given email address and role.
 *
 * WordPress-only — relies on get_user_by(), so it's only meaningful when
 * this file is running as a WordPress mu-plugin.
 */
final class WordPressUserCheck implements HealthCheck {

	/**
	 * @param string $email Email address to look up.
	 * @param string $role  Role slug the user must have.
	 */
	public function __construct(
		public readonly string $email,
		public readonly string $role,
	) {}

	/**
	 * @param float $timeout Seconds this check may take (unused: a local query).
	 * @return bool
	 */
	public function passes( float $timeout ): bool {
		$user = get_user_by( 'email', $this->email );

		if ( false === $user ) {
			return false;
		}

		return in_array( $this->role, (array) $user->roles, true );
	}
}

// ---- Run all checks -------------------------------------------------------

/**
 * Result of running all configured checks.
 */
final class CheckResult {

	/**
	 * @param int                             $status   HTTP status: 200, 503, or 500.
	 * @param array<int,array<string,mixed>>  $failures The check that failed or errored, if any, flattened (see describe_check_failure()) — empty when status is 200.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $failures,
	) {}
}

/**
 * Build a flat, associative description of a failed (or errored) check, for
 * inclusion in the response: which check class it is, its own configured
 * properties (url, host, expected, etc.), and — only when it errored rather
 * than simply failing — what was thrown.
 *
 * Full check configuration is included deliberately: the endpoint requires
 * authentication, so there's no unauthenticated audience to withhold it
 * from, and this is the only record of a failure (nothing is logged
 * server-side) — it's meant to be read from UptimeRobot's own history.
 *
 * The class name is keyed as 'check', not 'type', because DnsRecordCheck
 * already has its own 'type' property (e.g. 'A', 'MX') — flattening both
 * into one array under the same key would collide.
 *
 * @param HealthCheck $check Check to describe.
 * @param string|null $error Exception class + message, if the check errored.
 * @return array<string,mixed>
 */
function describe_check_failure( HealthCheck $check, ?string $error = null ): array {
	$class_parts = explode( '\\', get_class( $check ) );

	return array_merge(
		[ 'check' => end( $class_parts ) ],
		get_object_vars( $check ),
		[ 'error' => $error ]
	);
}

/**
 * Seconds all checks together may take.
 *
 * Kept under UptimeRobot's default 30-second request timeout and PHP's common
 * 30-second max_execution_time, with margin for WordPress to boot — so a slow
 * run still returns a response naming the check, rather than timing out with
 * no detail. Each HTTP check gets whatever's left (capped at its own 10s
 * limit); a check with under a second left isn't started.
 *
 * @var int
 */
const TIME_BUDGET = 20;

/**
 * Run all configured checks and return the resulting status.
 *
 * Pure result, no output side effects — authentication and how the result is
 * delivered (the REST envelope) both happen in the entry point below, per
 * context. Stops at the first failing (or errored) check — one at a time is
 * sufficient.
 *
 * @return CheckResult
 */
function checks_status(): CheckResult {
	error_reporting( 0 );
	ini_set( 'display_errors', '0' );

	$deadline = microtime( true ) + TIME_BUDGET;
	$check    = null;

	try {
		foreach ( checks() as $check ) {
			if ( ! $check instanceof HealthCheck ) {
				throw new \UnexpectedValueException( sprintf( 'checks() returned %s, expected HealthCheck', get_debug_type( $check ) ) );
			}

			$remaining = $deadline - microtime( true );

			if ( $remaining < 1 ) {
				return new CheckResult( 500, [
					describe_check_failure( $check, sprintf( 'Not run: %d-second time budget exhausted', TIME_BUDGET ) ),
				] );
			}

			if ( ! $check->passes( $remaining ) ) {
				return new CheckResult( 503, [ describe_check_failure( $check ) ] );
			}
		}

		return new CheckResult( 200, [] );
	} catch ( \Throwable $e ) {
		$error = sprintf( '%s: %s', get_class( $e ), $e->getMessage() );

		// $check is null if checks() itself threw, or not a HealthCheck if it returned something else.
		return new CheckResult( 500, [
			$check instanceof HealthCheck
				? describe_check_failure( $check, $error )
				: [ 'check' => null, 'error' => $error ],
		] );
	}
}

// ---- Allowed users ------------------------------------------------------

/**
 * Usernames and email addresses allowed to authenticate against the endpoint.
 *
 * Configured per site via the 'cssllc/uptimerobot/allowed_users' filter, in
 * the same config file as the checks, so no one's identity is published in
 * this file. Starts empty: a site that configures no one rejects every
 * request (except in local environments), and so does a filter that returns
 * anything but an array.
 *
 * Matched against the user authenticated via HTTP Basic auth + WordPress
 * application password (core handles that authentication itself; this is
 * just the authorization check in permission_callback).
 *
 * @return string[]
 */
function allowed_users(): array {
	$users = apply_filters( 'cssllc/uptimerobot/allowed_users', [] );

	return is_array( $users ) ? $users : [];
}

/**
 * Whether the currently authenticated user is allowed to hit the endpoint.
 *
 * @return bool
 */
function current_user_allowed(): bool {
	$user = wp_get_current_user();

	if ( ! $user->exists() ) {
		return false;
	}

	$allowed = allowed_users();

	return in_array( $user->user_login, $allowed, true )
		|| in_array( $user->user_email, $allowed, true );
}

// ---- Application password lock ------------------------------------------

/**
 * REST namespace the endpoint is registered under.
 *
 * @var string
 */
const REST_NAMESPACE = 'cssllc/v1';

/**
 * REST route the endpoint is registered at, within REST_NAMESPACE.
 *
 * @var string
 */
const REST_ROUTE = '/uptimerobot';

/**
 * Name of the application password UptimeRobot authenticates with.
 *
 * Application passwords otherwise grant everything their user can do over the
 * REST API and XML-RPC, so one stored in UptimeRobot would be as good as the
 * user's own login if it leaked. Instead, an application password with exactly
 * this name (on any user) only authenticates requests for this endpoint, and
 * this endpoint only accepts an application password with this name — so a
 * leaked copy is useless elsewhere, and renaming or revoking it fails the
 * monitor instead of quietly lifting the restriction.
 *
 * @var string
 */
const APP_PASSWORD_NAME = 'UptimeRobot';

/**
 * Whether the current request is for this endpoint.
 *
 * Reads the route WordPress itself dispatches on: the rest_route query var,
 * which is set before rest_api_loaded() defines REST_REQUEST — and core only
 * accepts application passwords on REST requests once REST_REQUEST is defined
 * (or on XML-RPC, which is never this endpoint). So whenever an application
 * password is being checked, this answer is already final. Compared exactly:
 * anything else, including the batch endpoint, is rejected.
 *
 * @return bool
 */
function is_endpoint_request(): bool {
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || ! isset( $GLOBALS['wp'] ) ) {
		return false;
	}

	$route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;

	return is_string( $route ) && '/' . REST_NAMESPACE . REST_ROUTE === untrailingslashit( $route );
}

/**
 * Whether the current request authenticated with the APP_PASSWORD_NAME
 * application password.
 *
 * @return bool
 */
function authenticated_with_endpoint_password(): bool {
	$uuid = rest_get_authenticated_app_password();

	if ( null === $uuid ) {
		return false;
	}

	$item = \WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );

	return is_array( $item ) && APP_PASSWORD_NAME === $item['name'];
}

// Reject the APP_PASSWORD_NAME application password anywhere but this
// endpoint. Fires after core has verified the password, before it logs the
// user in; an added error makes core refuse it (401 on REST, a failed login
// on XML-RPC). Applies in every environment, since database copies carry
// application passwords from production to staging.
add_action( 'wp_authenticate_application_password_errors', static function ( \WP_Error $error, \WP_User $user, array $item ) {
	if ( APP_PASSWORD_NAME === $item['name'] && ! is_endpoint_request() ) {
		$error->add(
			'cssllc_uptimerobot_password_restricted',
			sprintf( 'The "%s" application password can only be used for the UptimeRobot endpoint.', APP_PASSWORD_NAME )
		);
	}
}, 10, 3 );

// ---- Entry point ------------------------------------------------------

// No rewrite flush needed: REST routes register against WP's existing wp-json rewrite rule.
// Authentication is HTTP Basic + WordPress application passwords (handled by
// core); permission_callback checks that the authenticated user is one of
// allowed_users() and that it authenticated with the APP_PASSWORD_NAME
// application password, so WP's own REST error envelope formats the
// rejection the same way core does. Enforced in every environment except
// 'local' (wp_get_environment_type()), so a live site mislabelled as staging or
// development stays locked; only a 'local' label, which no server should carry,
// opens it. Staging/dev copies made from production carry the application
// password over in the database, so they need no extra setup.

add_action( 'rest_api_init', static function () {
	register_rest_route( REST_NAMESPACE, REST_ROUTE, [
		'methods'             => 'GET',
		'callback'            => static function () {
			$result = checks_status();

			$body = [ 'status' => $result->status ];

			if ( ! empty( $result->failures ) ) {
				$body['failures'] = $result->failures;
			}

			$response = new \WP_REST_Response( $body, $result->status );

			// Core only sends no-cache headers to logged-in users, and auth is skipped
			// on local — don't let a page cache or CDN serve a stale result.
			// array_filter() drops Last-Modified, which core sets to false to mean "remove".
			$response->set_headers( array_filter( wp_get_nocache_headers() ) );

			return $response;
		},
		'permission_callback' => static function () {
			if ( 'local' === wp_get_environment_type() || ( current_user_allowed() && authenticated_with_endpoint_password() ) ) {
				return true;
			}

			return new \WP_Error(
				'rest_forbidden',
				'Sorry, you are not allowed to do that.',
				[ 'status' => 401 ]
			);
		},
		'show_in_index'       => false,
	] );
} );
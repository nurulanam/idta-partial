<?php
/**
 * Facts about the current request.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * The caller's address, hashed, and the rate limit built on it.
 *
 * The raw IP is never stored. It is used to derive a hash for throttling and
 * then dropped: the plugin has no use for the address itself, and a column of
 * them alongside names and phone numbers is a liability with no matching
 * benefit.
 */
final class Request_Context {

	/**
	 * A stable, non-reversible identifier for the caller.
	 *
	 * Salted with the site's own auth salt, so the hashes cannot be compared
	 * against a precomputed table of the whole IPv4 space — which, without a
	 * salt, a plain SHA-256 of an IP address trivially is.
	 *
	 * @return string
	 */
	public static function ip_hash(): string {
		return hash( 'sha256', self::ip() . wp_salt( 'auth' ) );
	}

	/**
	 * The caller's address.
	 *
	 * Behind Cloudflare the connecting address is Cloudflare's, so the
	 * forwarded headers are read first. They are trivially spoofable by anyone
	 * talking to the origin directly, which is acceptable here: the value is
	 * only ever used to throttle, so a forged one costs the forger their own
	 * shared bucket and nothing else.
	 *
	 * @return string
	 */
	private static function ip(): string {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );

			// X-Forwarded-For is a chain; the client is the first entry.
			$value = trim( (string) strtok( $value, ',' ) );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Whether this caller may make another partial submission.
	 *
	 * Counted per address and per email, so neither one host submitting for
	 * many addresses nor many hosts submitting for one can run unbounded.
	 *
	 * @param string $email Address on the submission.
	 * @param int    $limit Submissions allowed per hour.
	 *
	 * @return bool
	 */
	public static function rate_limit_allows( string $email, int $limit ): bool {
		$buckets = array( 'ip_' . substr( self::ip_hash(), 0, 32 ) );

		if ( '' !== $email ) {
			$buckets[] = 'em_' . substr( hash( 'sha256', strtolower( $email ) . wp_salt( 'auth' ) ), 0, 32 );
		}

		$allowed = true;

		foreach ( $buckets as $bucket ) {
			$key  = 'idta_partial_rl_' . $bucket;
			$hits = (int) get_transient( $key );

			if ( $hits >= $limit ) {
				$allowed = false;
			}

			// Counted even once the limit is reached, so a caller that keeps
			// hammering keeps its window rolling forward rather than being let
			// back in an hour after its first request.
			set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		}

		return $allowed;
	}
}

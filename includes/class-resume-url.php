<?php
/**
 * Builds the link in the reminder email.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a lead into a resume URL carrying nothing but application state.
 *
 * The link restores which plan the customer was looking at and nothing else. It
 * deliberately fetches nothing from the server, and deliberately carries no
 * personal data: a URL is the leakiest place to put any, since it reaches the
 * Referer header sent to every third party the page loads, browser history, CDN
 * and proxy logs, and the link scanners most mail providers run.
 *
 * The cost is that a resumed application starts with the personal fields empty.
 * That is the intended trade, not an oversight — see section 70 of the design.
 */
final class Resume_URL {

	/**
	 * Package values the application form understands.
	 *
	 * @var string[]
	 */
	private const PACKAGES = array( 'printed', 'digital' );

	/**
	 * Validity periods, in years, the form offers.
	 *
	 * @var int[]
	 */
	private const VALIDITY_YEARS = array( 1, 2, 3 );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The URL for one lead.
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	public function for_record( Record $record ): string {
		$base = (string) $this->settings->get( 'resume_url', '' );

		if ( '' === $base ) {
			return '';
		}

		$args = array( 'resume' => '1' );

		$package = $this->package( $record );

		if ( '' !== $package ) {
			$args['package'] = $package;
		}

		if ( in_array( $record->validity_years, self::VALIDITY_YEARS, true ) ) {
			$args['validity'] = (string) $record->validity_years;
		}

		/*
		 * The two countries step 2 asks for. Carried so the link lands on step 3
		 * rather than back at the country question — the form's existing
		 * ?license_country= / ?destination_country= prefill, which the home page
		 * and countries.html have always used, does the work.
		 *
		 * An ISO country code is application state, not personal data: it says
		 * which permit is being bought, the same as the package does, and it is
		 * already public in the links those other pages build.
		 */
		foreach ( array(
			'license_country'     => 'license_issued_country',
			'destination_country' => 'destination_country',
		) as $param => $payload_key ) {
			$code = $this->country_code( $record, $payload_key );

			if ( '' !== $code ) {
				$args[ $param ] = $code;
			}
		}

		/**
		 * Filters the resume URL parameters.
		 *
		 * Anything added here ends up in an email, in browser history and in
		 * access logs. Add application state, never personal data.
		 *
		 * @param array<string,string> $args   Query arguments.
		 * @param Record               $record Lead.
		 */
		$args = (array) apply_filters( 'idta_partial_resume_args', $args, $record );

		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $base );
	}

	/**
	 * A two-letter country code from the payload.
	 *
	 * @param Record $record      Lead.
	 * @param string $payload_key Key holding a {code, name} pair.
	 *
	 * @return string
	 */
	private function country_code( Record $record, string $payload_key ): string {
		$country = $record->payload( $payload_key );

		if ( ! is_array( $country ) ) {
			return '';
		}

		$code = strtoupper( trim( (string) ( $country['code'] ?? '' ) ) );

		// Anything that is not a plain alpha-2 code is dropped rather than
		// passed on: this value goes straight into a URL in an email.
		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}

	/**
	 * Which plan card the customer had selected.
	 *
	 * Read from the payload where the form recorded it, and derived from the
	 * application type otherwise, so an older lead still resumes onto the right
	 * card.
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function package( Record $record ): string {
		$package = (string) $record->payload( 'package', '' );

		if ( in_array( $package, self::PACKAGES, true ) ) {
			return $package;
		}

		if ( 'print_digital' === $record->application_type ) {
			return 'printed';
		}

		if ( 'digital_only' === $record->application_type ) {
			return 'digital';
		}

		return '';
	}
}

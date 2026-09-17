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
 * Turns a lead into a resume URL that refills the whole form.
 *
 * The link carries the applicant's own details, so someone returning from the
 * reminder finds the form as they left it rather than blank. That is a decision
 * taken with its cost understood: a URL reaches browser history, CDN and proxy
 * logs, and the link scanners most mail providers run.
 *
 * Two things hold that cost down, and both matter:
 *
 *  - The application page strips these parameters in an inline script at the
 *    top of <head>, before Google Tag Manager loads, so no analytics or ads tag
 *    ever sees them. Without that, every field below would land in Google
 *    Analytics as part of page_location.
 *  - The driver's licence number and the four images are not here, and are not
 *    stored at all. They are the fields where this trade would stop being
 *    reasonable.
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
		// The site this application was started on, not a single global one — a
		// customer who began on idpa must be sent back to idpa.
		$base = $this->settings->source_url( $record->source );

		if ( '' === $base ) {
			return '';
		}

		$args = array( 'resume' => '1' );

		/*
		 * The lead's own token, so finishing from this link updates THIS row
		 * rather than starting a second one.
		 *
		 * Without it the flow breaks in a way that looks like the feature
		 * working: the token lives in sessionStorage, which is per-tab and dies
		 * with the tab, so a reminder opened hours later in a fresh tab mints a
		 * brand new token. Step 3 then inserts a second lead, the order carries
		 * the new token, and the original row — the one that was actually
		 * reminded — is never converted and sits at "reminded" for good.
		 *
		 * The email fallback does not save it either: that only runs when the
		 * order has no token at all, and here it has one. It is just the wrong
		 * one.
		 *
		 * Safe to expose. The token is a correlation key, never a credential —
		 * holding it lets you overwrite an unconverted lead you would have had
		 * to guess a UUIDv4 to find, and nothing else. Everything that IS a
		 * credential (resume_token, the API key) stays server-side.
		 */
		if ( '' !== $record->lead_token ) {
			$args['lead'] = $record->lead_token;
		}

		$package = $this->package( $record );

		if ( '' !== $package ) {
			$args['package'] = $package;
		}

		// Step 1: the licence question, so the gate does not ask again.
		$has_license = strtolower( trim( (string) $record->payload( 'has_license', '' ) ) );

		if ( in_array( $has_license, array( 'yes', 'no' ), true ) ) {
			$args['has_license'] = $has_license;
		}

		// Step 3: the applicant. Column values, not payload, where both exist —
		// the columns are what the admin screen and the email itself read, so a
		// link built from them cannot disagree with what staff are looking at.
		foreach ( array(
			'first_name' => $record->first_name,
			'last_name'  => $record->last_name,
			'email'      => $record->email,
		) as $param => $value ) {
			$value = trim( $value );

			if ( '' !== $value ) {
				$args[ $param ] = $value;
			}
		}

		$phone = $this->phone( $record );

		if ( array() !== $phone ) {
			$args += $phone;
		}

		$dob = trim( (string) $record->payload( 'date_of_birth', '' ) );

		// Only an ISO date, which is the one shape the three birth-date selects
		// can be driven from.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
			$args['dob'] = $dob;
		}

		$gender = strtolower( trim( (string) $record->payload( 'gender', '' ) ) );

		if ( in_array( $gender, array( 'male', 'female' ), true ) ) {
			$args['gender'] = $gender;
		}

		$classes = $this->classes( $record );

		if ( '' !== $classes ) {
			$args['classes'] = $classes;
		}

		foreach ( array(
			'birth_country'     => 'country_of_birth',
			'residence_country' => 'country_of_residence',
		) as $param => $payload_key ) {
			$code = $this->country_code( $record, $payload_key );

			if ( '' !== $code ) {
				$args[ $param ] = $code;
			}
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
	 * The phone number, split the way the form holds it.
	 *
	 * The form has a country picker and a national-number box, not one combined
	 * field, so a single "+8801711000000" could not be put back without guessing
	 * where the dial code ends — which is genuinely ambiguous between, say, +1
	 * and +1-242.
	 *
	 * @param Record $record Lead.
	 *
	 * @return array<string,string>
	 */
	private function phone( Record $record ): array {
		$national = trim( (string) $record->payload( 'phone_national', '' ) );
		$dial     = ltrim( trim( (string) $record->payload( 'dial_code', '' ) ), '+' );

		$out = array();

		if ( '' !== $national && preg_match( '/^[0-9 ()-]{3,20}$/', $national ) ) {
			$out['phone'] = $national;
		}

		if ( '' !== $dial && preg_match( '/^[0-9]{1,5}$/', $dial ) ) {
			$out['phone_code'] = $dial;
		}

		return $out;
	}

	/**
	 * The selected licence classes, as "B,C".
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function classes( Record $record ): string {
		$categories = $record->payload( 'license_categories' );

		if ( ! is_array( $categories ) ) {
			return '';
		}

		$codes = array();

		foreach ( $categories as $category ) {
			$code = is_array( $category )
				? (string) ( $category['value'] ?? '' )
				: (string) $category;

			$code = strtoupper( trim( $code ) );

			// The form offers exactly these five, and this value goes into a
			// selector on the other end.
			if ( in_array( $code, array( 'A', 'B', 'C', 'D', 'E' ), true ) ) {
				$codes[] = $code;
			}
		}

		return implode( ',', array_unique( $codes ) );
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

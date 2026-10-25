<?php
/**
 * Settings repository.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the plugin option, with defaults.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION_KEY = 'idta_partial_settings';

	/**
	 * Option holding globally suppressed addresses.
	 */
	public const SUPPRESSION_KEY = 'idta_partial_suppressed_emails';

	/**
	 * Option holding the shared API key, used only when the constant is absent.
	 */
	public const API_KEY_OPTION = 'idta_partial_api_key';

	/**
	 * When conversion is recorded.
	 */
	public const CONVERT_ON_ORDER   = 'order_created';
	public const CONVERT_ON_PAYMENT = 'payment_complete';

	/**
	 * Front ends known out of the box, keyed by their `source` value.
	 *
	 * Mirrors idta-pdf's Order_Data::SOURCES, which keys the same two names to
	 * their upload buckets. Same keys, different thing being addressed: there it
	 * is where the images live, here it is where the application form lives.
	 *
	 * @var array<string,string>
	 */
	public const DEFAULT_SOURCES = array(
		'idta' => 'https://e-idta.com/application.html',
		'idpa' => '',
	);

	/**
	 * Default upload sources and their base URLs.
	 *
	 * Mirrors idta-pdf's Order_Data::SOURCES.
	 *
	 * @var array<string,string>
	 */
	public const DEFAULT_ASSET_SOURCES = array(
		'idta' => 'https://idta-upload.shamim66ewu.workers.dev/files/',
		'idpa' => 'https://pub-1c2e77688359483ca692ff2d8369b41b.r2.dev/',
	);

	/**
	 * Cached option values.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $values = null;

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'enabled'                    => true,
			// Whether the reminder email itself is sent. Capture carries on when off.
			'reminder_enabled'           => true,
			// Reminder wording. {first_name} and {validity_years} are replaced.
			'email_subject'              => __( 'Continue Your International Driving Permit Application', 'idta-partial' ),
			'email_body'                 => __( "Hi {first_name},\n\nIt looks like you started your International Driving Permit application but didn’t complete it.\n\nIf you had any questions or something was unclear, don’t worry we’re here to help.\n\nYou can continue your application using the link below:", 'idta-partial' ),
			'email_button_text'          => __( 'Continue Your Application', 'idta-partial' ),
			// Shown under the button.
			'email_footer'               => __( "Simply reply to this email if you need any help. We’ll be happy to assist you with your application.\n\nBest regards,\niAA Support Team", 'idta-partial' ),
			// Minutes between the end of step 3 and the reminder.
			'reminder_delay'             => 12,
			// An order is created unpaid and the customer is sent to the
			// payment page — counting that as a conversion marks someone who
			// never actually paid as converted, and stops their reminder.
			'convert_on'                 => self::CONVERT_ON_PAYMENT,
			// Days an unconverted lead is kept before deletion.
			'retention_days'             => 90,
			// Days a converted lead keeps its personal data before anonymisation.
			'converted_retention_days'   => 365,
			// Days before the same address may be reminded again.
			'reminder_cooldown_days'     => 7,
			/*
			 * Where each front end's application form lives, keyed by the value
			 * that front end sends as `source` — the same value idta-pdf stores
			 * as `_idp_order_from`.
			 *
			 * A reminder has to link back to the site the application was
			 * started on. With one global URL, a lead taken on idpa was emailed
			 * a link to e-idta.com, which is a dead end for that customer.
			 */
			'sources'                    => self::DEFAULT_SOURCES,
			// Partial submissions accepted per hour, per IP and per address.
			'rate_limit'                 => 20,
		);
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->values ) {
			$stored = get_option( self::OPTION_KEY, array() );

			$this->values = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				$this->defaults()
			);
		}

		/**
		 * Filters the resolved plugin settings.
		 *
		 * @param array<string,mixed> $values Settings.
		 */
		return apply_filters( 'idta_partial_settings', $this->values );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 *
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$values = $this->all();

		return $values[ $key ] ?? $default;
	}

	/**
	 * Whether capture and reminders are switched on.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return (bool) $this->get( 'enabled', true );
	}

	/**
	 * Whether reminder emails are switched on.
	 *
	 * @return bool
	 */
	public function reminders_enabled(): bool {
		return (bool) $this->get( 'reminder_enabled', true );
	}

	/**
	 * Reminder delay, in seconds.
	 *
	 * @return int
	 */
	public function reminder_delay(): int {
		return max( 1, (int) $this->get( 'reminder_delay', 12 ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Whether the lead converts on order creation rather than on payment.
	 *
	 * @return bool
	 */
	public function converts_on_order(): bool {
		return self::CONVERT_ON_PAYMENT !== $this->get( 'convert_on', self::CONVERT_ON_ORDER );
	}

	/**
	 * The shared secret the Worker presents.
	 *
	 * The constant wins: a key in wp-config.php is not in the database, so it
	 * cannot be read out of a database backup or an option-editor plugin, and it
	 * survives a staging clone without being copied along with the data.
	 *
	 * @return string
	 */
	public function api_key(): string {
		if ( defined( 'IDTA_PARTIAL_API_KEY' ) && is_string( IDTA_PARTIAL_API_KEY ) ) {
			return (string) IDTA_PARTIAL_API_KEY;
		}

		return (string) get_option( self::API_KEY_OPTION, '' );
	}

	/**
	 * Whether the API key came from wp-config.php.
	 *
	 * @return bool
	 */
	public function api_key_is_constant(): bool {
		return defined( 'IDTA_PARTIAL_API_KEY' ) && '' !== (string) IDTA_PARTIAL_API_KEY;
	}

	/**
	 * Persist submitted settings.
	 *
	 * @param array<string,mixed> $raw Raw input.
	 */
	public function save( array $raw ): void {
		update_option( self::OPTION_KEY, $this->sanitize( $raw ) );

		$this->values = null;
	}

	/**
	 * Sanitise submitted settings field by field.
	 *
	 * @param array<string,mixed> $raw Raw input.
	 *
	 * @return array<string,mixed>
	 */
	public function sanitize( array $raw ): array {
		$defaults = $this->defaults();

		return array(
			'enabled'                  => ! empty( $raw['enabled'] ),
			'reminder_enabled'         => ! empty( $raw['reminder_enabled'] ),
			'email_subject'            => $this->text( $raw['email_subject'] ?? null, (string) $defaults['email_subject'], false ),
			'email_body'               => $this->text( $raw['email_body'] ?? null, (string) $defaults['email_body'], true ),
			'email_button_text'        => $this->text( $raw['email_button_text'] ?? null, (string) $defaults['email_button_text'], false ),
			'email_footer'             => $this->text( $raw['email_footer'] ?? null, (string) $defaults['email_footer'], true ),
			'reminder_delay'           => $this->clamp( $raw['reminder_delay'] ?? null, 1, 1440, (int) $defaults['reminder_delay'] ),
			'convert_on'               => self::CONVERT_ON_PAYMENT === ( $raw['convert_on'] ?? '' )
				? self::CONVERT_ON_PAYMENT
				: self::CONVERT_ON_ORDER,
			'retention_days'           => $this->clamp( $raw['retention_days'] ?? null, 1, 3650, (int) $defaults['retention_days'] ),
			'converted_retention_days' => $this->clamp( $raw['converted_retention_days'] ?? null, 1, 3650, (int) $defaults['converted_retention_days'] ),
			'reminder_cooldown_days'   => $this->clamp( $raw['reminder_cooldown_days'] ?? null, 0, 365, (int) $defaults['reminder_cooldown_days'] ),
			'sources'                  => $this->sanitize_sources( (array) ( $raw['sources'] ?? array() ) ),
			'rate_limit'               => $this->clamp( $raw['rate_limit'] ?? null, 1, 1000, (int) $defaults['rate_limit'] ),
		);
	}

	/**
	 * Every configured front end, keyed by source.
	 *
	 * @return array<string,string>
	 */
	public function sources(): array {
		$sources = $this->get( 'sources', self::DEFAULT_SOURCES );

		if ( ! is_array( $sources ) || array() === $sources ) {
			$sources = self::DEFAULT_SOURCES;
		}

		/**
		 * Filters the front ends partial applications may arrive from.
		 *
		 * Keyed by the `source` value the front end sends, each an application
		 * URL the reminder link is built on. Applied on top of whatever is
		 * configured on the settings screen.
		 *
		 * @param array<string,string> $sources Application URLs keyed by source.
		 */
		return (array) apply_filters( 'idta_partial_sources', $sources );
	}

	/**
	 * The source keys a submission may declare.
	 *
	 * @return string[]
	 */
	public function source_keys(): array {
		return array_keys( $this->sources() );
	}

	/**
	 * The application URL a lead from this source should be sent back to.
	 *
	 * Falls back to the first source that has a URL rather than to nothing: a
	 * reminder with no link is worse than one pointing at the main site, and a
	 * source added without a URL is a configuration slip, not a reason to send a
	 * dead email.
	 *
	 * @param string $source Source key.
	 *
	 * @return string
	 */
	public function source_url( string $source ): string {
		$sources = $this->sources();
		$url     = trim( (string) ( $sources[ $source ] ?? '' ) );

		if ( '' !== $url ) {
			return $url;
		}

		foreach ( $sources as $candidate ) {
			$candidate = trim( (string) $candidate );

			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Upload sources, keyed by source value.
	 *
	 * Uses idta-pdf's configured asset sources when available, falling back to
	 * default asset sources. Filterable via idta_partial_asset_sources.
	 *
	 * @return array<string,string>
	 */
	public function asset_sources(): array {
		if ( class_exists( '\IDTA\PDF\Order_Data' ) && method_exists( '\IDTA\PDF\Order_Data', 'sources' ) ) {
			$pdf_sources = \IDTA\PDF\Order_Data::sources();
			if ( is_array( $pdf_sources ) && array() !== $pdf_sources ) {
				return (array) apply_filters( 'idta_partial_asset_sources', $pdf_sources );
			}
		}

		$sources = (array) $this->get( 'asset_sources', self::DEFAULT_ASSET_SOURCES );
		if ( array() === $sources ) {
			$sources = self::DEFAULT_ASSET_SOURCES;
		}

		return (array) apply_filters( 'idta_partial_asset_sources', $sources );
	}

	/**
	 * Base URL for uploaded verification documents.
	 *
	 * @param string $source Source key (e.g. 'idta').
	 *
	 * @return string
	 */
	public function asset_base_url( string $source ): string {
		$sources = $this->asset_sources();
		$key     = strtolower( trim( $source ) );

		if ( isset( $sources[ $key ] ) && '' !== trim( (string) $sources[ $key ] ) ) {
			return trailingslashit( trim( (string) $sources[ $key ] ) );
		}

		if ( isset( $sources['idta'] ) && '' !== trim( (string) $sources['idta'] ) ) {
			return trailingslashit( trim( (string) $sources['idta'] ) );
		}

		return 'https://idta-upload.shamim66ewu.workers.dev/files/';
	}

	/**
	 * Clean the source rows the settings screen submits.
	 *
	 * @param array<int|string,mixed> $rows Submitted rows.
	 *
	 * @return array<string,string>
	 */
	private function sanitize_sources( array $rows ): array {
		$clean = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			$url = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ) );

			// A key with no URL is kept: it still declares a source the endpoint
			// will accept, which is the half of this that matters for capture.
			// A URL with no key is meaningless and goes.
			if ( '' === $key ) {
				continue;
			}

			$clean[ $key ] = $url;
		}

		return array() !== $clean ? $clean : self::DEFAULT_SOURCES;
	}

	/**
	 * Clean a line or block of wording, falling back to the default when blank.
	 *
	 * @param mixed  $value     Submitted value.
	 * @param string $fallback  Used when nothing usable was submitted.
	 * @param bool   $multiline Whether line breaks are kept.
	 *
	 * @return string
	 */
	private function text( $value, string $fallback, bool $multiline ): string {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$value = $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

		return '' === trim( $value ) ? $fallback : $value;
	}

	/**
	 * Read an integer within bounds.
	 *
	 * @param mixed $value    Submitted value.
	 * @param int   $min      Lowest accepted.
	 * @param int   $max      Highest accepted.
	 * @param int   $fallback Used when nothing usable was submitted.
	 *
	 * @return int
	 */
	private function clamp( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}
}

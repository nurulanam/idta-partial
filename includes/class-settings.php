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
			// Minutes between the end of step 3 and the reminder.
			'reminder_delay'             => 12,
			'convert_on'                 => self::CONVERT_ON_ORDER,
			// Days an unconverted lead is kept before deletion.
			'retention_days'             => 90,
			// Days a converted lead keeps its personal data before anonymisation.
			'converted_retention_days'   => 365,
			// Days before the same address may be reminded again.
			'reminder_cooldown_days'     => 7,
			// Where the application form lives, for the resume link.
			'resume_url'                 => 'https://e-idta.com/application.html',
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

		$resume = isset( $raw['resume_url'] ) ? esc_url_raw( trim( (string) $raw['resume_url'] ) ) : '';

		return array(
			'enabled'                  => ! empty( $raw['enabled'] ),
			'reminder_delay'           => $this->clamp( $raw['reminder_delay'] ?? null, 1, 1440, (int) $defaults['reminder_delay'] ),
			'convert_on'               => self::CONVERT_ON_PAYMENT === ( $raw['convert_on'] ?? '' )
				? self::CONVERT_ON_PAYMENT
				: self::CONVERT_ON_ORDER,
			'retention_days'           => $this->clamp( $raw['retention_days'] ?? null, 1, 3650, (int) $defaults['retention_days'] ),
			'converted_retention_days' => $this->clamp( $raw['converted_retention_days'] ?? null, 1, 3650, (int) $defaults['converted_retention_days'] ),
			'reminder_cooldown_days'   => $this->clamp( $raw['reminder_cooldown_days'] ?? null, 0, 365, (int) $defaults['reminder_cooldown_days'] ),
			'resume_url'               => '' !== $resume ? $resume : (string) $defaults['resume_url'],
			'rate_limit'               => $this->clamp( $raw['rate_limit'] ?? null, 1, 1000, (int) $defaults['rate_limit'] ),
		);
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

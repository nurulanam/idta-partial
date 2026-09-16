<?php
/**
 * One partial application.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * A typed, read-only view over one row of the partial-applications table.
 */
final class Record {

	public const STATUS_NEW       = 'new';
	public const STATUS_REMINDED  = 'reminded';
	public const STATUS_CONVERTED = 'converted';
	public const STATUS_EXPIRED   = 'expired';

	public int $id                 = 0;
	public string $lead_token      = '';
	public string $resume_token    = '';
	public string $status          = self::STATUS_NEW;
	public string $source          = 'idta';
	public string $email           = '';
	public string $first_name      = '';
	public string $last_name       = '';
	public string $phone           = '';
	public string $application_type = '';
	public int $validity_years     = 0;
	public int $product_id         = 0;
	public string $currency        = '';
	public string $locale          = '';
	public bool $unsubscribed      = false;
	public int $order_id           = 0;
	public string $created_at      = '';
	public string $updated_at      = '';
	public ?string $reminder_due_at  = null;
	public ?string $reminder_sent_at = null;
	public ?string $converted_at     = null;

	/**
	 * Decoded payload.
	 *
	 * @var array<string,mixed>
	 */
	public array $payload = array();

	/**
	 * Build from a database row.
	 *
	 * @param array<string,mixed> $row Row as returned by $wpdb, all strings.
	 *
	 * @return Record
	 */
	public static function from_row( array $row ): Record {
		$record = new self();

		$record->id               = (int) ( $row['id'] ?? 0 );
		$record->lead_token       = (string) ( $row['lead_token'] ?? '' );
		$record->resume_token     = (string) ( $row['resume_token'] ?? '' );
		$record->status           = (string) ( $row['status'] ?? self::STATUS_NEW );
		$record->source           = (string) ( $row['source'] ?? 'idta' );
		$record->email            = (string) ( $row['email'] ?? '' );
		$record->first_name       = (string) ( $row['first_name'] ?? '' );
		$record->last_name        = (string) ( $row['last_name'] ?? '' );
		$record->phone            = (string) ( $row['phone'] ?? '' );
		$record->application_type = (string) ( $row['application_type'] ?? '' );
		$record->validity_years   = (int) ( $row['validity_years'] ?? 0 );
		$record->product_id       = (int) ( $row['product_id'] ?? 0 );
		$record->currency         = (string) ( $row['currency'] ?? '' );
		$record->locale           = (string) ( $row['locale'] ?? '' );
		$record->unsubscribed     = ! empty( $row['unsubscribed'] );
		$record->order_id         = (int) ( $row['order_id'] ?? 0 );
		$record->created_at       = (string) ( $row['created_at'] ?? '' );
		$record->updated_at       = (string) ( $row['updated_at'] ?? '' );
		$record->reminder_due_at  = self::nullable( $row['reminder_due_at'] ?? null );
		$record->reminder_sent_at = self::nullable( $row['reminder_sent_at'] ?? null );
		$record->converted_at     = self::nullable( $row['converted_at'] ?? null );

		$decoded = json_decode( (string) ( $row['payload'] ?? '' ), true );

		$record->payload = is_array( $decoded ) ? $decoded : array();

		return $record;
	}

	/**
	 * Full name, or the email's local part when no name was given.
	 *
	 * @return string
	 */
	public function full_name(): string {
		$name = trim( $this->first_name . ' ' . $this->last_name );

		if ( '' !== $name ) {
			return $name;
		}

		$at = strpos( $this->email, '@' );

		return false === $at ? '' : substr( $this->email, 0, $at );
	}

	/**
	 * First name, falling back to something usable in a greeting.
	 *
	 * @return string
	 */
	public function greeting_name(): string {
		return '' !== $this->first_name ? $this->first_name : $this->full_name();
	}

	/**
	 * A payload value, by key.
	 *
	 * @param string $key     Payload key.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	public function payload( string $key, $default = null ) {
		return $this->payload[ $key ] ?? $default;
	}

	/**
	 * Whether this lead is still a candidate for a reminder.
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return self::STATUS_NEW === $this->status;
	}

	/**
	 * Normalise a nullable datetime column.
	 *
	 * MySQL hands back the zero date rather than NULL in some configurations,
	 * and "0000-00-00 00:00:00" formats as a real date if it reaches a template.
	 *
	 * @param mixed $value Column value.
	 *
	 * @return string|null
	 */
	private static function nullable( $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}

		return $value;
	}
}

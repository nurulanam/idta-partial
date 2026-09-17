<?php
/**
 * Database access for partial applications.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Every read and write against the partial-applications table.
 *
 * Nothing outside this class writes SQL. Every statement goes through
 * $wpdb->prepare(); the only interpolated value is the table name, which comes
 * from $wpdb->prefix and a class constant.
 */
final class Repository {

	/**
	 * Columns an ordinary duplicate submission is allowed to change.
	 *
	 * Deliberately excludes resume_token, created_at and reminder_due_at: a
	 * second step-3 submission must not rotate a token that may already be in a
	 * sent email, must not reset the age the cleanup job measures, and must not
	 * push the reminder further out — a customer who spends twenty minutes
	 * adjusting their plan would otherwise never be reminded at all.
	 *
	 * @var string[]
	 */
	/**
	 * Columns the leads list renders.
	 *
	 * Everything except `payload`. Kept as a constant so the list query and this
	 * reasoning stay next to each other.
	 */
	private const LIST_COLUMNS = 'id, lead_token, resume_token, status, source, email, first_name, last_name, phone, application_type, validity_years, product_id, currency, locale, unsubscribed, reminder_enabled, reminder_count, order_id, created_at, updated_at, reminder_due_at, reminder_sent_at, converted_at';

	private const MUTABLE = array(
		'email',
		'first_name',
		'last_name',
		'phone',
		'application_type',
		'validity_years',
		'product_id',
		'currency',
		'locale',
		'payload',
		'ip_hash',
	);

	/**
	 * Insert a lead, or update it when its token is already known.
	 *
	 * @param array<string,mixed> $data Sanitised column values, keyed by column.
	 *
	 * @return array{id:int,created:bool,record:Record|null}
	 */
	public function upsert( array $data ): array {
		global $wpdb;

		if ( ! Table::exists() ) {
			return array(
				'id'      => 0,
				'created' => false,
				'record'  => null,
			);
		}

		$table = Table::name();
		$now   = self::now();

		$row = array(
			'lead_token'       => (string) $data['lead_token'],
			'resume_token'     => self::new_resume_token(),
			'status'           => Record::STATUS_NEW,
			'source'           => (string) ( $data['source'] ?? 'idta' ),
			'email'            => (string) ( $data['email'] ?? '' ),
			'first_name'       => (string) ( $data['first_name'] ?? '' ),
			'last_name'        => (string) ( $data['last_name'] ?? '' ),
			'phone'            => (string) ( $data['phone'] ?? '' ),
			'application_type' => (string) ( $data['application_type'] ?? '' ),
			'validity_years'   => (int) ( $data['validity_years'] ?? 0 ),
			'product_id'       => (int) ( $data['product_id'] ?? 0 ),
			'currency'         => (string) ( $data['currency'] ?? '' ),
			'locale'           => (string) ( $data['locale'] ?? '' ),
			'payload'          => (string) ( $data['payload'] ?? '' ),
			'ip_hash'          => (string) ( $data['ip_hash'] ?? '' ),
			'created_at'       => $now,
			'updated_at'       => $now,
			'reminder_due_at'  => (string) ( $data['reminder_due_at'] ?? $now ),
		);

		$columns      = array_keys( $row );
		$placeholders = array();

		foreach ( $columns as $column ) {
			$placeholders[] = in_array( $column, array( 'validity_years', 'product_id' ), true ) ? '%d' : '%s';
		}

		/*
		 * One statement, and the UNIQUE index on lead_token is what makes it
		 * idempotent — not a SELECT followed by a decision, which two concurrent
		 * requests from the same session could both lose.
		 *
		 * Every assignment is wrapped in IF(status = 'converted', <old>, <new>),
		 * so a late or retried POST — a customer hitting Back after paying, a
		 * request the browser held in its keepalive queue — can never reopen a
		 * converted lead and earn it a reminder for an order already placed.
		 */
		$updates = array( 'updated_at = VALUES(updated_at)' );

		foreach ( self::MUTABLE as $column ) {
			$updates[] = sprintf(
				'%1$s = IF(status = %2$s, %1$s, VALUES(%1$s))',
				$column,
				"'" . Record::STATUS_CONVERTED . "'"
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = sprintf(
			'INSERT INTO %1$s (%2$s) VALUES (%3$s) ON DUPLICATE KEY UPDATE %4$s',
			$table,
			implode( ', ', $columns ),
			implode( ', ', $placeholders ),
			implode( ', ', $updates )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, array_values( $row ) ) );

		if ( false === $result ) {
			return array(
				'id'      => 0,
				'created' => false,
				'record'  => null,
			);
		}

		/*
		 * MySQL reports 1 affected row for an insert and 2 for an update that
		 * changed something — but 0 for an update that changed nothing, which is
		 * exactly what an identical re-submission looks like. So "created" is
		 * decided on the insert id, which ON DUPLICATE KEY UPDATE leaves alone.
		 */
		$record = $this->find_by_lead_token( (string) $data['lead_token'] );

		if ( null === $record ) {
			return array(
				'id'      => 0,
				'created' => false,
				'record'  => null,
			);
		}

		return array(
			'id'      => $record->id,
			'created' => ( 1 === $result ),
			'record'  => $record,
		);
	}

	/**
	 * Find one lead by primary key.
	 *
	 * @param int $id Row ID.
	 *
	 * @return Record|null
	 */
	public function find( int $id ): ?Record {
		return $this->find_one( 'id = %d', array( $id ) );
	}

	/**
	 * Find one lead by its correlation token.
	 *
	 * @param string $token Lead token.
	 *
	 * @return Record|null
	 */
	public function find_by_lead_token( string $token ): ?Record {
		if ( '' === $token ) {
			return null;
		}

		return $this->find_one( 'lead_token = %s', array( $token ) );
	}

	/**
	 * Find one lead by its resume token.
	 *
	 * @param string $token Resume token.
	 *
	 * @return Record|null
	 */
	public function find_by_resume_token( string $token ): ?Record {
		if ( '' === $token ) {
			return null;
		}

		return $this->find_one( 'resume_token = %s', array( $token ) );
	}

	/**
	 * The newest unconverted lead for an address, within a window.
	 *
	 * The fallback used when an order carries no lead token. Narrow on purpose:
	 * a wider window would convert a returning customer's fresh lead against an
	 * order they placed from an older one.
	 *
	 * @param string $email Billing address.
	 * @param int    $hours How far back to look.
	 *
	 * @return Record|null
	 */
	public function find_recent_open_by_email( string $email, int $hours = 24 ): ?Record {
		$email = sanitize_email( $email );

		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

		return $this->find_one(
			'email = %s AND status IN ( %s, %s ) AND created_at >= %s',
			array(
				$email,
				Record::STATUS_NEW,
				Record::STATUS_REMINDED,
				self::now( -$hours * HOUR_IN_SECONDS ),
			),
			'created_at DESC'
		);
	}

	/**
	 * Mark a lead converted, and note the order that did it.
	 *
	 * Idempotent: the status guard means a second call — both conversion hooks
	 * firing for the same order — costs one UPDATE that matches no rows.
	 *
	 * @param int $id       Row ID.
	 * @param int $order_id Order ID, 0 when unknown.
	 *
	 * @return bool Whether this call was the one that converted it.
	 */
	public function mark_converted( int $id, int $order_id ): bool {
		return $this->update(
			$id,
			array(
				'status'       => Record::STATUS_CONVERTED,
				'order_id'     => $order_id,
				'converted_at' => self::now(),
			),
			'status <> %s',
			array( Record::STATUS_CONVERTED )
		);
	}

	/**
	 * Record the order against a lead without converting it.
	 *
	 * Used in payment_complete mode, where reaching the pay page is not yet a
	 * conversion but is worth knowing about.
	 *
	 * @param int $id       Row ID.
	 * @param int $order_id Order ID.
	 *
	 * @return bool
	 */
	public function attach_order( int $id, int $order_id ): bool {
		return $this->update( $id, array( 'order_id' => $order_id ), 'order_id = 0', array() );
	}

	/**
	 * Record that the reminder has been processed.
	 *
	 * The status moves whether or not the send succeeded. One nudge email is
	 * worth less than the risk of a mail-stack fault producing three copies, and
	 * there is nothing a customer would ever ask to have resent.
	 *
	 * @param int  $id   Row ID.
	 * @param bool $sent Whether the mailer accepted it.
	 *
	 * @return bool
	 */
	public function mark_reminded( int $id, bool $sent ): bool {
		return $this->update(
			$id,
			array(
				'status'           => Record::STATUS_REMINDED,
				'reminder_sent_at' => $sent ? self::now() : null,
			),
			'status = %s',
			array( Record::STATUS_NEW )
		);
	}

	/**
	 * Turn automatic reminders on or off for one lead.
	 *
	 * Staff-facing, and deliberately independent of `unsubscribed`: see the note
	 * on Table. Switching reminders back on never un-does a customer's own
	 * opt-out, because that is a different column and is checked separately.
	 *
	 * @param int  $id      Row ID.
	 * @param bool $enabled Whether the scheduler may send.
	 *
	 * @return bool
	 */
	public function set_reminder_enabled( int $id, bool $enabled ): bool {
		return $this->update( $id, array( 'reminder_enabled' => $enabled ? 1 : 0 ) );
	}

	/**
	 * Record that a reminder went out, however it was triggered.
	 *
	 * Separate from mark_reminded(): that one closes an open lead after the
	 * scheduled attempt and refuses to touch anything else. This one also serves
	 * a manual send, which staff may fire at a lead that is already `reminded`,
	 * so it carries no status guard and counts every send instead.
	 *
	 * @param int  $id   Row ID.
	 * @param bool $sent Whether the mailer accepted it.
	 *
	 * @return bool
	 */
	public function record_send( int $id, bool $sent ): bool {
		global $wpdb;

		if ( ! Table::exists() || $id <= 0 ) {
			return false;
		}

		$table = Table::name();
		$now   = self::now();

		if ( ! $sent ) {
			return $this->update( $id, array( 'status' => Record::STATUS_REMINDED ) );
		}

		// reminder_count is incremented in SQL rather than read-then-written, so
		// two sends racing cannot both write the same total.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
				    SET status = IF(status = %s, status, %s),
				        reminder_sent_at = %s,
				        reminder_count = reminder_count + 1,
				        updated_at = %s
				  WHERE id = %d",
				Record::STATUS_CONVERTED,
				Record::STATUS_REMINDED,
				$now,
				$now,
				$id
			)
		);

		return is_numeric( $affected ) && (int) $affected > 0;
	}

	/**
	 * Remove one key from every stored payload that still carries it.
	 *
	 * Used once, on upgrade, to clear driver's licence numbers that earlier
	 * versions collected before the field was dropped. Deciding not to store
	 * something going forward does nothing about the copies already held, and
	 * those are the ones that have been sitting there longest.
	 *
	 * @param string $key   Payload key to remove.
	 * @param int    $limit Rows per batch.
	 *
	 * @return int Rows rewritten.
	 */
	public function strip_payload_key( string $key, int $limit = 500 ): int {
		global $wpdb;

		if ( ! Table::exists() || '' === $key ) {
			return 0;
		}

		$table   = Table::name();
		$cleaned = 0;

		do {
			// LIKE on the quoted key, so it matches the JSON field name rather
			// than any value that happens to contain the same text.
			$like = '%' . $wpdb->esc_like( '"' . $key . '"' ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, payload FROM {$table} WHERE payload LIKE %s LIMIT %d",
					$like,
					$limit
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || array() === $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$payload = json_decode( (string) $row['payload'], true );

				// Unreadable JSON, or the match was a false positive. Either
				// way the key is not there to remove, and rewriting the row
				// would only risk making it worse — but it must be blanked, or
				// the LIKE would return it forever and this would not terminate.
				if ( ! is_array( $payload ) || ! array_key_exists( $key, $payload ) ) {
					$wpdb->update( $table, array( 'payload' => null ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );

					++$cleaned;

					continue;
				}

				unset( $payload[ $key ] );

				$wpdb->update(
					$table,
					array( 'payload' => (string) wp_json_encode( $payload ) ),
					array( 'id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);

				++$cleaned;
			}
		} while ( count( $rows ) === $limit );

		return $cleaned;
	}

	/**
	 * Delete one lead outright.
	 *
	 * The manual counterpart to the retention job. Staff delete a lead when it
	 * is a test row, a duplicate, or a customer who asked to be forgotten — and
	 * in the last case a soft delete would not actually answer the request, so
	 * this really does remove the row.
	 *
	 * @param int $id Row ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( ! Table::exists() || $id <= 0 ) {
			return false;
		}

		$deleted = $wpdb->delete( Table::name(), array( 'id' => $id ), array( '%d' ) );

		return is_numeric( $deleted ) && (int) $deleted > 0;
	}

	/**
	 * Suppress every lead belonging to an address.
	 *
	 * @param string $email Address.
	 *
	 * @return int Rows affected.
	 */
	public function unsubscribe_email( string $email ): int {
		global $wpdb;

		if ( ! Table::exists() || '' === $email ) {
			return 0;
		}

		$table = Table::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET unsubscribed = 1, updated_at = %s WHERE email = %s",
				self::now(),
				$email
			)
		);

		return is_numeric( $affected ) ? (int) $affected : 0;
	}

	/**
	 * Whether this address was reminded within the cooldown window.
	 *
	 * Keyed on the address rather than the lead, so someone who abandons three
	 * times in a week is nudged once, not three times.
	 *
	 * @param string $email   Address.
	 * @param int    $days    Cooldown, in days.
	 * @param int    $exclude Row to ignore — the one being considered.
	 *
	 * @return bool
	 */
	public function reminded_recently( string $email, int $days, int $exclude = 0 ): bool {
		global $wpdb;

		if ( ! Table::exists() || '' === $email || $days <= 0 ) {
			return false;
		}

		$table = Table::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table}
				  WHERE email = %s
				    AND id <> %d
				    AND reminder_sent_at IS NOT NULL
				    AND reminder_sent_at >= %s
				  LIMIT 1",
				$email,
				$exclude,
				self::now( -$days * DAY_IN_SECONDS )
			)
		);

		return null !== $found;
	}

	/**
	 * Whether WooCommerce already holds an order for this address.
	 *
	 * The last check before sending, and the reason it exists: the conversion
	 * listener can be missed — an order created while this plugin was inactive,
	 * one taken by hand in the admin — and a customer who has already ordered
	 * must never be told they did not finish.
	 *
	 * @param string   $email    Billing address.
	 * @param string   $since    UTC datetime the lead was created.
	 * @param string[] $statuses Statuses that count, or empty for any.
	 *
	 * @return int Order ID, or 0.
	 */
	public function find_order_for_email( string $email, string $since, array $statuses = array() ): int {
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		/*
		 * Backdated by an hour. The lead's created_at is written by WordPress
		 * and the order's date by WooCommerce, and on a store behind a queue or
		 * a replica the two clocks need not agree to the second; an order that
		 * reads as a minute older than its own lead would otherwise be missed
		 * and the reminder sent anyway.
		 */
		$after = max( 0, (int) strtotime( $since . ' UTC' ) - HOUR_IN_SECONDS );

		$args = array(
			'billing_email' => $email,
			'limit'         => 1,
			'return'        => 'ids',
			// A timestamp, which is the form WC_Order_Query documents for a
			// comparison; a formatted string is parsed inconsistently across
			// versions.
			'date_created'  => '>=' . $after,
		);

		/*
		 * Left unset, wc_get_orders returns every status but trash — which is
		 * what "they got as far as checkout" means. In payment_complete mode the
		 * caller narrows this to the paid statuses instead, since there an
		 * unpaid order is precisely the case the reminder exists for.
		 */
		if ( array() !== $statuses ) {
			$args['status'] = $statuses;
		}

		$orders = wc_get_orders( $args );

		if ( ! is_array( $orders ) || array() === $orders ) {
			return 0;
		}

		return (int) reset( $orders );
	}

	/**
	 * Leads for the admin screen.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 *
	 * @return array{rows:Record[],total:int}
	 */
	public function paginate( array $args ): array {
		global $wpdb;

		if ( ! Table::exists() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		$table    = Table::name();
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$status   = (string) ( $args['status'] ?? '' );
		$search   = trim( (string) ( $args['search'] ?? '' ) );

		$where  = array( '1 = 1' );
		$values = array();

		if ( in_array( $status, array( Record::STATUS_NEW, Record::STATUS_REMINDED, Record::STATUS_CONVERTED, Record::STATUS_EXPIRED ), true ) ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR lead_token = %s )';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $search;
		}

		$clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			array() === $values
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				? "SELECT COUNT(*) FROM {$table} WHERE {$clause}"
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$clause}", $values )
		);

		$paged = array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) );

		/*
		 * Named columns, not SELECT * — the one column left out is `payload`,
		 * which is a longtext holding the whole application snapshot and which
		 * the list does not render. Pulling it meant shipping 25 JSON blobs
		 * across the wire, and parsing them into 25 Record objects, to display
		 * a name and a date. The detail view uses find(), which selects
		 * everything.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT " . self::LIST_COLUMNS . " FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d",
				$paged
			),
			ARRAY_A
		);

		return array(
			'rows'  => array_map( array( Record::class, 'from_row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Counts by status, for the admin screen's summary.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		global $wpdb;

		if ( ! Table::exists() ) {
			return array();
		}

		$table = Table::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$counts = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Delete unconverted leads past their retention date.
	 *
	 * @param int $days  Retention, in days.
	 * @param int $limit Batch size.
	 *
	 * @return int Rows deleted.
	 */
	public function purge_unconverted( int $days, int $limit = 500 ): int {
		global $wpdb;

		if ( ! Table::exists() ) {
			return 0;
		}

		$table = Table::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table}
				  WHERE status IN ( %s, %s, %s )
				    AND created_at < %s
				  LIMIT %d",
				Record::STATUS_NEW,
				Record::STATUS_REMINDED,
				Record::STATUS_EXPIRED,
				self::now( -$days * DAY_IN_SECONDS ),
				$limit
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Strip the personal data from old converted leads, keeping the shape.
	 *
	 * Anonymised rather than deleted: id, status, order_id and the timestamps
	 * are what conversion reporting counts, and deleting the rows would quietly
	 * rewrite last year's numbers.
	 *
	 * @param int $days  Retention, in days.
	 * @param int $limit Batch size.
	 *
	 * @return int Rows anonymised.
	 */
	public function anonymise_converted( int $days, int $limit = 500 ): int {
		global $wpdb;

		if ( ! Table::exists() ) {
			return 0;
		}

		$table = Table::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
				    SET email = '', first_name = '', last_name = '', phone = '',
				        payload = NULL, ip_hash = '', updated_at = %s
				  WHERE status = %s
				    AND converted_at IS NOT NULL
				    AND converted_at < %s
				    AND email <> ''
				  LIMIT %d",
				self::now(),
				Record::STATUS_CONVERTED,
				self::now( -$days * DAY_IN_SECONDS ),
				$limit
			)
		);

		return is_numeric( $affected ) ? (int) $affected : 0;
	}

	/**
	 * Run one UPDATE against one row, with an optional guard.
	 *
	 * @param int                  $id           Row ID.
	 * @param array<string,mixed>  $columns      Column => value.
	 * @param string               $guard        Extra WHERE clause, with placeholders.
	 * @param array<int,mixed>     $guard_values Values for the guard.
	 *
	 * @return bool Whether a row changed.
	 */
	private function update( int $id, array $columns, string $guard = '', array $guard_values = array() ): bool {
		global $wpdb;

		if ( ! Table::exists() || $id <= 0 || array() === $columns ) {
			return false;
		}

		$columns['updated_at'] = self::now();

		$table       = Table::name();
		$assignments = array();
		$values      = array();

		foreach ( $columns as $column => $value ) {
			if ( null === $value ) {
				$assignments[] = $column . ' = NULL';

				continue;
			}

			$assignments[] = $column . ' = ' . ( is_int( $value ) ? '%d' : '%s' );
			$values[]      = $value;
		}

		$where = 'id = %d';

		$values[] = $id;

		if ( '' !== $guard ) {
			$where .= ' AND ' . $guard;

			$values = array_merge( $values, $guard_values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = sprintf( 'UPDATE %s SET %s WHERE %s', $table, implode( ', ', $assignments ), $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$affected = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return is_numeric( $affected ) && (int) $affected > 0;
	}

	/**
	 * Fetch one row by an arbitrary clause.
	 *
	 * @param string           $where  WHERE clause with placeholders.
	 * @param array<int,mixed> $values Values for it.
	 * @param string           $order  ORDER BY clause, from this file only.
	 *
	 * @return Record|null
	 */
	private function find_one( string $where, array $values, string $order = 'id DESC' ): ?Record {
		global $wpdb;

		if ( ! Table::exists() ) {
			return null;
		}

		$table = Table::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = sprintf( 'SELECT * FROM %s WHERE %s ORDER BY %s LIMIT 1', $table, $where, $order );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return is_array( $row ) ? Record::from_row( $row ) : null;
	}

	/**
	 * Current UTC datetime, optionally offset.
	 *
	 * @param int $offset Seconds to add.
	 *
	 * @return string
	 */
	public static function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	/**
	 * A fresh resume token.
	 *
	 * @return string
	 */
	public static function new_resume_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}
}

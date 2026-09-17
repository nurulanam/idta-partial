<?php
/**
 * The partial-applications table.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the schema and keeps it current.
 */
final class Table {

	/**
	 * Schema version. Bump when the CREATE TABLE below changes.
	 */
	public const DB_VERSION = '1.1.0';

	/**
	 * Option recording the installed schema version.
	 */
	public const VERSION_OPTION = 'idta_partial_db_version';

	/**
	 * Unqualified table name.
	 */
	private const NAME = 'idta_partial_applications';

	/*
	 * Two separate switches govern whether a lead is reminded, and they are not
	 * interchangeable:
	 *
	 *   unsubscribed     the customer said no. Set by the unsubscribe link, and
	 *                    honoured everywhere, including a manual send by staff.
	 *   reminder_enabled the shop said no. Set by staff on the leads screen, for
	 *                    a lead they do not want chased. It stops the scheduled
	 *                    reminder, but a human clicking "Send now" overrides it,
	 *                    because that human is making the decision afresh.
	 *
	 * Collapsing them into one column would mean staff re-enabling reminders for
	 * someone who had opted out, which is the one mistake this must not allow.
	 */

	/**
	 * Cached answer to exists(), for the life of the request.
	 *
	 * @var bool|null
	 */
	private static ?bool $exists = null;

	/**
	 * Fully qualified table name.
	 *
	 * Built from $wpdb->prefix and a constant, never from input, so it is safe
	 * to interpolate into SQL — which it has to be, since table names cannot be
	 * bound as prepared-statement parameters.
	 *
	 * @return string
	 */
	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . self::NAME;
	}

	/**
	 * Create or update the table when the stored version is behind.
	 *
	 * Cheap enough to call on every request: one option read when up to date.
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Run dbDelta against the schema.
	 *
	 * Called from activation and from the version check above. The second path
	 * is the one that matters in production: this plugin is updated by replacing
	 * its folder over SFTP, which never fires the activation hook, so a schema
	 * change that only ran on activation would never reach the live site.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		/*
		 * dbDelta is particular about formatting: two spaces after PRIMARY KEY,
		 * one field per line, KEY names matching the column list. Reformatting
		 * this block casually will make it try to re-create indexes on every
		 * run.
		 *
		 * On the indexes, which are chosen for the four queries that actually
		 * run rather than for every column that might one day be filtered:
		 *
		 *   status_created  the cleanup job (status + age) and the date-bounded
		 *                   lookup behind the email fallback.
		 *   status_id       the leads list, which filters by status and sorts by
		 *                   id — without it that sort is a filesort.
		 *   email           the conversion fallback and the reminder cooldown.
		 *                   One address owns a handful of rows, so the index
		 *                   finds them and the rest is filtered in place; a
		 *                   composite here would earn nothing.
		 *   order_id        reporting, and answering "which lead became this
		 *                   order?" from the order side.
		 *
		 * Nothing speculative: every index costs a write on an upsert, and the
		 * write path is the one thing here that runs while a customer waits.
		 */
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_token varchar(64) NOT NULL,
			resume_token char(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			source varchar(20) NOT NULL DEFAULT 'idta',
			email varchar(190) NOT NULL DEFAULT '',
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			application_type varchar(40) NOT NULL DEFAULT '',
			validity_years tinyint(3) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT '',
			locale varchar(10) NOT NULL DEFAULT '',
			payload longtext NULL,
			ip_hash char(64) NOT NULL DEFAULT '',
			unsubscribed tinyint(1) NOT NULL DEFAULT 0,
			reminder_enabled tinyint(1) NOT NULL DEFAULT 1,
			reminder_count int(10) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			reminder_due_at datetime NULL,
			reminder_sent_at datetime NULL,
			converted_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY lead_token (lead_token),
			UNIQUE KEY resume_token (resume_token),
			KEY status_created (status,created_at),
			KEY status_id (status,id),
			KEY email (email),
			KEY order_id (order_id)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::DB_VERSION );

		// A table created part-way through a request has to be visible to the
		// rest of it, or everything after this point still believes it missing.
		self::$exists = true;
	}

	/**
	 * Whether the table is actually present.
	 *
	 * Consulted before every read and write. A missing table must degrade to
	 * "this feature does nothing" rather than to a database error on the REST
	 * endpoint — or, far worse, inside order creation.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		if ( null !== self::$exists ) {
			return self::$exists;
		}

		/*
		 * Answered from the version option, not from SHOW TABLES.
		 *
		 * The option is autoloaded, so it is already in memory by the time
		 * anything asks — this costs nothing. SHOW TABLES is a metadata query
		 * that cannot be cached by the query cache and was being run on every
		 * request that touched a lead, including inside order creation, to
		 * re-establish a fact that only changes when the plugin is installed.
		 *
		 * install() writes the option only after dbDelta has returned, so the
		 * option cannot be set while the table is missing. The reverse — someone
		 * dropping the table by hand and leaving the option — is recoverable:
		 * the queries fail, are caught, and the admin screen says the table is
		 * missing.
		 */
		self::$exists = '' !== (string) get_option( self::VERSION_OPTION, '' );

		return self::$exists;
	}

	/**
	 * Drop the table. Used only by uninstall.php.
	 */
	public static function drop(): void {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		delete_option( self::VERSION_OPTION );

		self::$exists = false;
	}
}

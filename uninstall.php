<?php
/**
 * Uninstall routine.
 *
 * Removes the lead table and this plugin's options. Order meta is left alone:
 * `_idp_lead_token` is written by the checkout flow and read by idta-pdf, so it
 * is not this plugin's to delete.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'idta_partial_applications' );

foreach ( array(
	'idta_partial_settings',
	'idta_partial_db_version',
	'idta_partial_version',
	'idta_partial_api_key',
	'idta_partial_suppressed_emails',
) as $idta_partial_option ) {
	delete_option( $idta_partial_option );
}

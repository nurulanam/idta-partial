<?php
/**
 * Plugin Name:       IDTA Partial Applications
 * Plugin URI:        https://am2am.com
 * Description:       Captures an eIDTA application at the end of step 3, reminds the customer once if no order follows, and marks the lead converted when the WooCommerce order arrives.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            am2am software
 * Author URI:        https://am2am.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       idta-partial
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.9
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/class-autoloader.php';

Autoloader::register( __DIR__ . '/includes' );

/**
 * Retrieve the plugin container.
 *
 * @return Plugin
 */
function plugin(): Plugin {
	return Plugin::instance();
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 *
 * This plugin reads order meta through the CRUD API only, so HPOS changes
 * nothing for it — but an undeclared plugin puts the whole store back into
 * compatibility mode, which would penalise idta-pdf as well.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PLUGIN_FILE,
				true
			);
		}
	}
);

/*
 * 20, the same priority idta-pdf boots on. Nothing here depends on that plugin
 * — the two share only the `_idp_lead_token` order meta key, which is a string
 * on an order either of them can read — so load order between them is free.
 */
add_action( 'plugins_loaded', static fn() => plugin()->boot(), 20 );

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

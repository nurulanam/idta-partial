<?php
/**
 * Plugin container and hook wiring.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the plugin and owns its long-lived services.
 */
final class Plugin {

	/**
	 * Option recording the version whose one-time setup has already run.
	 */
	private const VERSION_OPTION = 'idta_partial_version';

	/**
	 * Sole instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Lead storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Action Scheduler logging.
	 *
	 * @var Job_Log
	 */
	private Job_Log $log;

	/**
	 * Reminder scheduling.
	 *
	 * @var Reminder_Scheduler
	 */
	private Reminder_Scheduler $scheduler;

	/**
	 * Resume link builder.
	 *
	 * @var Resume_URL
	 */
	private Resume_URL $resume;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings   = new Settings();
		$this->repository = new Repository();
		$this->log        = Job_Log::instance();
		$this->scheduler  = new Reminder_Scheduler( $this->settings, $this->repository, $this->log );
		$this->resume     = new Resume_URL( $this->settings );
	}

	/**
	 * Retrieve the sole instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Settings accessor.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Lead storage accessor.
	 *
	 * @return Repository
	 */
	public function repository(): Repository {
		return $this->repository;
	}

	/**
	 * Register hooks. Safe to call once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'idta-partial', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );

		if ( ! class_exists( \WooCommerce::class ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );

			return;
		}

		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );

		$this->log->register();
		$this->scheduler->register();

		( new REST_Controller( $this->settings, $this->repository, $this->scheduler ) )->register();
		( new Conversion_Listener( $this->settings, $this->repository, $this->scheduler ) )->register();
		( new Cleanup( $this->settings, $this->repository, $this->log ) )->register();
		( new Unsubscribe( $this->repository ) )->register();
		( new Admin_Page( $this->settings, $this->repository, $this->scheduler, $this->resume ) )->register();

		add_filter( 'woocommerce_email_classes', array( $this, 'register_email' ) );
	}

	/**
	 * Add the reminder to WooCommerce's mailer.
	 *
	 * @param mixed $emails Email instances, keyed by class name.
	 *
	 * @return array<string,\WC_Email>
	 */
	public function register_email( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();

		$emails['IDTA_Partial_Reminder'] = new Reminder_Email( $this->resume );

		return $emails;
	}

	/**
	 * Run one-time work after the plugin files change.
	 *
	 * activate() only fires when the plugin is activated, so a site updated by
	 * replacing the folder over SFTP — which is how this one is updated — would
	 * never get its table. Gated on a stored version, so it costs one option
	 * read per request and nothing else.
	 */
	public function maybe_upgrade(): void {
		Table::maybe_install();

		if ( get_option( self::VERSION_OPTION ) === VERSION ) {
			return;
		}

		update_option( self::VERSION_OPTION, VERSION );
	}

	/**
	 * Create storage on activation.
	 */
	public static function activate(): void {
		Table::install();

		add_option( Settings::OPTION_KEY, ( new Settings() )->defaults() );

		/*
		 * A key so the endpoint works out of the box. It is shown on the
		 * settings screen until an administrator moves it into wp-config.php,
		 * which is where it belongs.
		 */
		if ( '' === (string) get_option( Settings::API_KEY_OPTION, '' ) ) {
			add_option( Settings::API_KEY_OPTION, bin2hex( random_bytes( 24 ) ), '', false );
		}
	}

	/**
	 * Clear queued work on deactivation.
	 *
	 * The table is deliberately left alone. Deactivating a plugin is routine —
	 * a debugging step, a conflict check — and it must never be the thing that
	 * destroys the store's lead history.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Reminder_Scheduler::HOOK );
		wp_clear_scheduled_hook( Cleanup::HOOK );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Reminder_Scheduler::HOOK, array(), Reminder_Scheduler::GROUP );
			as_unschedule_all_actions( Cleanup::HOOK, array(), Reminder_Scheduler::GROUP );
		}
	}

	/**
	 * Warn when WooCommerce is unavailable.
	 */
	public function render_missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'IDTA Partial Applications requires WooCommerce to be installed and active.', 'idta-partial' )
		);
	}
}

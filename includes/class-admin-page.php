<?php
/**
 * The leads screen.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Lists partial applications, with a settings tab alongside.
 *
 * Deliberately a hand-rolled table rather than WP_List_Table: the latter is a
 * private-ish base class with a habit of changing between releases, and this
 * screen needs four columns, a status filter and a search box.
 */
final class Admin_Page {

	/**
	 * Menu slug.
	 */
	public const SLUG = 'idta-partial';

	/**
	 * Capability required throughout.
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Settings.
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
	 * Constructor.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Repository $repository Lead storage.
	 */
	public function __construct( Settings $settings, Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_idta_partial_save_settings', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the menu entry.
	 *
	 * A top-level menu rather than a WooCommerce submenu, matching idta-pdf.
	 * The two plugins are operated together — an order's permit on one screen,
	 * the lead it came from on the other — and burying one of them three levels
	 * into a menu the other sits outside of makes that pairing harder to find
	 * than it needs to be.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'IDTA Partials', 'idta-partial' ),
			__( 'IDTA Partials', 'idta-partial' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-clipboard'
		);
	}

	/**
	 * Persist submitted settings.
	 */
	public function handle_save(): void {
		check_admin_referer( 'idta-partial-settings' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'idta-partial' ), '', array( 'response' => 403 ) );
		}

		// Values are sanitised field by field in Settings::sanitize().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['idta_partial'] ) ? wp_unslash( (array) $_POST['idta_partial'] ) : array();

		$this->settings->save( $raw );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		if ( ! empty( $_POST['idta_partial_generate_key'] ) && ! $this->settings->api_key_is_constant() ) {
			update_option( Settings::API_KEY_OPTION, bin2hex( random_bytes( 24 ) ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'tab'     => 'settings',
					'updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses which panel to show.
		$tab = isset( $_GET['tab'] ) && 'settings' === sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) ? 'settings' : 'leads';

		echo '<div class="wrap">';

		printf( '<h1>%s</h1>', esc_html__( 'Partial Applications', 'idta-partial' ) );

		echo '<h2 class="nav-tab-wrapper">';

		foreach ( array(
			'leads'    => __( 'Leads', 'idta-partial' ),
			'settings' => __( 'Settings', 'idta-partial' ),
		) as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</h2>';

		if ( ! Table::exists() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'The partial-applications table is missing. Deactivate and reactivate the plugin to create it.', 'idta-partial' )
			);
		}

		if ( 'settings' === $tab ) {
			$this->render_settings();
		} else {
			$this->render_leads();
		}

		echo '</div>';
	}

	/**
	 * The leads table.
	 */
	private function render_leads(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters on a capability-gated screen.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 25;

		$result = $this->repository->paginate(
			array(
				'status'   => $status,
				'search'   => $search,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		$this->render_counts( $status );

		echo '<form method="get">';

		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );

		if ( '' !== $status ) {
			printf( '<input type="hidden" name="status" value="%s">', esc_attr( $status ) );
		}

		echo '<p class="search-box">';

		printf(
			'<input type="search" name="s" value="%1$s" placeholder="%2$s"> ',
			esc_attr( $search ),
			esc_attr__( 'Email, name or lead token', 'idta-partial' )
		);

		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Search', 'idta-partial' ) );

		echo '</p></form>';

		echo '<table class="widefat striped"><thead><tr>';

		foreach ( array(
			__( 'ID', 'idta-partial' ),
			__( 'Status', 'idta-partial' ),
			__( 'Created (UTC)', 'idta-partial' ),
			__( 'Customer', 'idta-partial' ),
			__( 'Application', 'idta-partial' ),
			__( 'Reminder', 'idta-partial' ),
			__( 'Order', 'idta-partial' ),
		) as $heading ) {
			printf( '<th>%s</th>', esc_html( $heading ) );
		}

		echo '</tr></thead><tbody>';

		if ( array() === $result['rows'] ) {
			printf( '<tr><td colspan="7">%s</td></tr>', esc_html__( 'No partial applications yet.', 'idta-partial' ) );
		}

		foreach ( $result['rows'] as $record ) {
			$this->render_row( $record );
		}

		echo '</tbody></table>';

		$this->render_pagination( $result['total'], $page, $per_page );
	}

	/**
	 * One lead row.
	 *
	 * @param Record $record Lead.
	 */
	private function render_row( Record $record ): void {
		echo '<tr>';

		printf( '<td>%d</td>', (int) $record->id );

		printf(
			'<td><span class="idta-partial-status">%s</span></td>',
			esc_html( $this->status_label( $record->status ) )
		);

		printf( '<td>%s</td>', esc_html( $record->created_at ) );

		printf(
			'<td>%1$s<br><small>%2$s</small></td>',
			esc_html( $record->full_name() ),
			esc_html( $record->email )
		);

		printf(
			'<td>%1$s%2$s</td>',
			esc_html( 'digital_only' === $record->application_type ? __( 'Digital only', 'idta-partial' ) : __( 'Printed + digital', 'idta-partial' ) ),
			$record->validity_years > 0
				? esc_html( sprintf( ' — %dy', (int) $record->validity_years ) )
				: ''
		);

		printf(
			'<td>%s</td>',
			esc_html(
				null !== $record->reminder_sent_at
					? $record->reminder_sent_at
					: ( null !== $record->reminder_due_at ? sprintf( /* translators: %s: datetime. */ __( 'due %s', 'idta-partial' ), $record->reminder_due_at ) : '—' )
			)
		);

		if ( $record->order_id > 0 ) {
			printf(
				'<td><a href="%1$s">#%2$d</a></td>',
				esc_url( admin_url( 'post.php?post=' . $record->order_id . '&action=edit' ) ),
				(int) $record->order_id
			);
		} else {
			echo '<td>—</td>';
		}

		echo '</tr>';
	}

	/**
	 * Status filter links with counts.
	 *
	 * @param string $current Selected status.
	 */
	private function render_counts( string $current ): void {
		$counts = $this->repository->counts();
		$total  = array_sum( $counts );

		$links = array();

		$all_url = admin_url( 'admin.php?page=' . self::SLUG );

		$links[] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
			esc_url( $all_url ),
			'' === $current ? ' class="current"' : '',
			esc_html__( 'All', 'idta-partial' ),
			(int) $total
		);

		foreach ( array( Record::STATUS_NEW, Record::STATUS_REMINDED, Record::STATUS_CONVERTED, Record::STATUS_EXPIRED ) as $status ) {
			$links[] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $all_url ) ),
				$status === $current ? ' class="current"' : '',
				esc_html( $this->status_label( $status ) ),
				(int) ( $counts[ $status ] ?? 0 )
			);
		}

		echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) . '</li></ul><br class="clear">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link escaped above.
	}

	/**
	 * Pager.
	 *
	 * @param int $total    Rows matching the filter.
	 * @param int $page     Current page.
	 * @param int $per_page Rows per page.
	 */
	private function render_pagination( int $total, int $page, int $per_page ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );

		if ( $pages < 2 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';

		echo wp_kses_post(
			(string) paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => $pages,
				)
			)
		);

		echo '</div></div>';
	}

	/**
	 * The settings form.
	 */
	private function render_settings(): void {
		$values = $this->settings->all();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		wp_nonce_field( 'idta-partial-settings' );

		echo '<input type="hidden" name="action" value="idta_partial_save_settings">';

		echo '<table class="form-table" role="presentation">';

		$this->checkbox_row( 'enabled', __( 'Capture partial applications', 'idta-partial' ), ! empty( $values['enabled'] ), __( 'When off, the REST endpoint accepts requests and stores nothing, and no reminders are sent.', 'idta-partial' ) );

		$this->number_row( 'reminder_delay', __( 'Reminder delay (minutes)', 'idta-partial' ), (int) $values['reminder_delay'], __( 'Measured from the moment step 3 was completed.', 'idta-partial' ) );

		$this->select_row(
			'convert_on',
			__( 'Count a lead as converted', 'idta-partial' ),
			(string) $values['convert_on'],
			array(
				Settings::CONVERT_ON_ORDER   => __( 'When the order is created (reaches checkout)', 'idta-partial' ),
				Settings::CONVERT_ON_PAYMENT => __( 'Only when the order is paid', 'idta-partial' ),
			),
			__( 'Orders are created unpaid and the customer is sent to the payment page. Choosing "only when paid" means someone who abandons on that page still receives a reminder.', 'idta-partial' )
		);

		$this->text_row( 'resume_url', __( 'Application URL', 'idta-partial' ), (string) $values['resume_url'], __( 'Where the reminder link points. The resume parameters are appended to it.', 'idta-partial' ) );

		$this->number_row( 'reminder_cooldown_days', __( 'Reminder cooldown (days)', 'idta-partial' ), (int) $values['reminder_cooldown_days'], __( 'The same address is not reminded twice inside this window, however many applications it abandons.', 'idta-partial' ) );

		$this->number_row( 'retention_days', __( 'Keep unconverted leads (days)', 'idta-partial' ), (int) $values['retention_days'], __( 'Deleted after this.', 'idta-partial' ) );

		$this->number_row( 'converted_retention_days', __( 'Keep converted lead details (days)', 'idta-partial' ), (int) $values['converted_retention_days'], __( 'After this the personal data is stripped; the conversion itself is kept for reporting.', 'idta-partial' ) );

		$this->number_row( 'rate_limit', __( 'Submissions per hour', 'idta-partial' ), (int) $values['rate_limit'], __( 'Per IP address and per email address.', 'idta-partial' ) );

		$this->render_api_key_row();

		echo '</table>';

		submit_button();

		echo '</form>';
	}

	/**
	 * The API key row.
	 */
	private function render_api_key_row(): void {
		$configured = '' !== $this->settings->api_key();
		$constant   = $this->settings->api_key_is_constant();

		printf( '<tr><th scope="row">%s</th><td>', esc_html__( 'Worker API key', 'idta-partial' ) );

		if ( $constant ) {
			printf(
				'<p><strong>%s</strong></p><p class="description">%s</p>',
				esc_html__( 'Configured in wp-config.php', 'idta-partial' ),
				esc_html__( 'IDTA_PARTIAL_API_KEY is defined, which is the recommended place for it. Remove the constant to manage the key here instead.', 'idta-partial' )
			);

			echo '</td></tr>';

			return;
		}

		printf(
			'<p><strong>%s</strong></p>',
			esc_html( $configured ? __( 'Configured', 'idta-partial' ) : __( 'Not configured — the endpoint rejects every request', 'idta-partial' ) )
		);

		printf(
			'<label><input type="checkbox" name="idta_partial_generate_key" value="1"> %s</label>',
			esc_html__( 'Generate a new key when saving', 'idta-partial' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The key is shown once, immediately after it is generated, and never again. Better still, define IDTA_PARTIAL_API_KEY in wp-config.php so it is not in the database at all.', 'idta-partial' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, immediately after the redirect from a nonce-checked save.
		if ( isset( $_GET['updated'] ) && $configured && ! $constant ) {
			printf(
				'<p><code>%s</code></p>',
				esc_html( (string) get_option( Settings::API_KEY_OPTION, '' ) )
			);
		}

		echo '</td></tr>';
	}

	/**
	 * A translated status label.
	 *
	 * @param string $status Stored status.
	 *
	 * @return string
	 */
	private function status_label( string $status ): string {
		$labels = array(
			Record::STATUS_NEW       => __( 'New', 'idta-partial' ),
			Record::STATUS_REMINDED  => __( 'Reminded', 'idta-partial' ),
			Record::STATUS_CONVERTED => __( 'Converted', 'idta-partial' ),
			Record::STATUS_EXPIRED   => __( 'Expired', 'idta-partial' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * A checkbox row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param bool   $checked     Current value.
	 * @param string $description Help text.
	 */
	private function checkbox_row( string $key, string $label, bool $checked, string $description ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="idta_partial[%2$s]" value="1"%3$s> %1$s</label><p class="description">%4$s</p></td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_html( $description )
		);
	}

	/**
	 * A number row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param int    $value       Current value.
	 * @param string $description Help text.
	 */
	private function number_row( string $key, string $label, int $value, string $description ): void {
		printf(
			'<tr><th scope="row"><label for="%2$s">%1$s</label></th><td><input type="number" min="0" id="%2$s" name="idta_partial[%2$s]" value="%3$d" class="small-text"><p class="description">%4$s</p></td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			(int) $value,
			esc_html( $description )
		);
	}

	/**
	 * A text row.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $description Help text.
	 */
	private function text_row( string $key, string $label, string $value, string $description ): void {
		printf(
			'<tr><th scope="row"><label for="%2$s">%1$s</label></th><td><input type="url" id="%2$s" name="idta_partial[%2$s]" value="%3$s" class="regular-text"><p class="description">%4$s</p></td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			esc_attr( $value ),
			esc_html( $description )
		);
	}

	/**
	 * A select row.
	 *
	 * @param string                $key         Setting key.
	 * @param string                $label       Field label.
	 * @param string                $value       Current value.
	 * @param array<string,string>  $options     Value => label.
	 * @param string                $description Help text.
	 */
	private function select_row( string $key, string $label, string $value, array $options, string $description ): void {
		printf(
			'<tr><th scope="row"><label for="%2$s">%1$s</label></th><td><select id="%2$s" name="idta_partial[%2$s]">',
			esc_html( $label ),
			esc_attr( $key )
		);

		foreach ( $options as $option_value => $option_label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $option_value ),
				selected( $value, $option_value, false ),
				esc_html( $option_label )
			);
		}

		printf( '</select><p class="description">%s</p></td></tr>', esc_html( $description ) );
	}
}

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
 * Lists partial applications, with a detail view and a settings tab alongside.
 *
 * Deliberately a hand-rolled table rather than WP_List_Table: the latter is a
 * private-ish base class with a habit of changing between releases, and this
 * screen needs row actions, status pills and a detail panel that it does not
 * model well.
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
	 * Transient holding the message to show after a redirect, per user.
	 */
	private const NOTICE_TRANSIENT = 'idta_partial_notice_';

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
	 * Constructor.
	 *
	 * @param Settings           $settings   Settings.
	 * @param Repository         $repository Lead storage.
	 * @param Reminder_Scheduler $scheduler  Reminder scheduling.
	 * @param Resume_URL         $resume     Resume link builder.
	 */
	public function __construct( Settings $settings, Repository $repository, Reminder_Scheduler $scheduler, Resume_URL $resume ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->scheduler  = $scheduler;
		$this->resume     = $resume;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_idta_partial_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_idta_partial_lead_action', array( $this, 'handle_lead_action' ) );
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

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

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

		$this->redirect( array( 'tab' => 'settings', 'updated' => 'true' ) );
	}

	/**
	 * Run a row action against one lead.
	 *
	 * One entry point for all four, because they share every precondition —
	 * capability, nonce, a lead that exists — and splitting them would mean four
	 * copies of those checks, which is three chances to leave one out.
	 */
	public function handle_lead_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage partial applications.', 'idta-partial' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below, against this ID.
		$lead_id = isset( $_REQUEST['lead'] ) ? absint( $_REQUEST['lead'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same.
		$action  = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['do'] ) ) : '';

		// The nonce is tied to the specific lead, so a link for one row cannot
		// be edited into an action against another.
		check_admin_referer( 'idta-partial-lead-' . $lead_id );

		$record = $this->repository->find( $lead_id );

		if ( null === $record ) {
			$this->notice( 'error', __( 'That lead no longer exists.', 'idta-partial' ) );
			$this->redirect();
		}

		switch ( $action ) {
			case 'delete':
				// The queued reminder goes first. Deleting the row on its own
				// would leave a job that wakes up to a missing lead — harmless,
				// since it checks, but it would log a puzzling "lead not found"
				// against an action nobody can explain.
				$this->scheduler->unschedule( $record->id );
				$this->repository->delete( $record->id );

				$this->notice(
					'success',
					sprintf(
						/* translators: %d: lead ID. */
						__( 'Lead #%d deleted.', 'idta-partial' ),
						$record->id
					)
				);
				break;

			case 'remind_on':
			case 'remind_off':
				$enable = ( 'remind_on' === $action );

				$this->repository->set_reminder_enabled( $record->id, $enable );

				$this->notice(
					'success',
					$enable
						? sprintf(
							/* translators: %d: lead ID. */
							__( 'Reminders switched on for lead #%d.', 'idta-partial' ),
							$record->id
						)
						: sprintf(
							/* translators: %d: lead ID. */
							__( 'Reminders switched off for lead #%d. Any queued reminder will run and send nothing.', 'idta-partial' ),
							$record->id
						)
				);
				break;

			case 'send_now':
				$result = $this->scheduler->send_now( $record );

				$this->notice( $result['sent'] ? 'success' : 'error', $result['reason'] );
				break;

			default:
				$this->notice( 'error', __( 'Unknown action.', 'idta-partial' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		$back = isset( $_REQUEST['back'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['back'] ) ) : '';

		// A delete has nowhere to go back to.
		$this->redirect( ( 'detail' === $back && 'delete' !== $action ) ? array( 'lead' => $record->id ) : array() );
	}

	/**
	 * Store the message to show after the redirect.
	 *
	 * In a transient keyed by user rather than in the URL: the text varies (a
	 * mailer failure explains itself) and putting arbitrary text in a query
	 * string invites reflecting it back into the page.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message What to say.
	 */
	private function notice( string $type, string $message ): void {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Show and clear the stored message.
	 */
	private function render_notice(): void {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error',
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Send the browser back to this screen.
	 *
	 * @param array<string,string|int> $args Extra query arguments.
	 */
	private function redirect( array $args = array() ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'page' => self::SLUG ), $args ),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * A nonced URL for one row action.
	 *
	 * @param Record $record Lead.
	 * @param string $action Action key.
	 * @param string $back   'detail' to return to the detail view.
	 *
	 * @return string
	 */
	private function action_url( Record $record, string $action, string $back = '' ): string {
		$args = array(
			'action' => 'idta_partial_lead_action',
			'do'     => $action,
			'lead'   => $record->id,
		);

		if ( '' !== $back ) {
			$args['back'] = $back;
		}

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			'idta-partial-lead-' . $record->id
		);
	}

	// -------------------------------------------------------------------------
	// Screens
	// -------------------------------------------------------------------------

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation on a capability-gated screen.
		$tab  = isset( $_GET['tab'] ) && 'settings' === sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) ? 'settings' : 'leads';
		$lead = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->render_styles();

		echo '<div class="wrap idta-partial">';

		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Partial Applications', 'idta-partial' ) );

		echo '<hr class="wp-header-end">';

		$this->render_notice();

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
		} elseif ( $lead > 0 ) {
			$this->render_detail( $lead );
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

		echo '<table class="widefat striped idta-partial-table"><thead><tr>';

		foreach ( array(
			__( 'ID', 'idta-partial' ),
			__( 'Status', 'idta-partial' ),
			__( 'Customer', 'idta-partial' ),
			__( 'Lead token', 'idta-partial' ),
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

		$this->render_copy_script();
	}

	/**
	 * One lead row.
	 *
	 * @param Record $record Lead.
	 */
	private function render_row( Record $record ): void {
		$detail_url = add_query_arg(
			array(
				'page' => self::SLUG,
				'lead' => $record->id,
			),
			admin_url( 'admin.php' )
		);

		echo '<tr>';

		printf(
			'<td><a href="%1$s"><strong>#%2$d</strong></a><div class="idta-partial-when">%3$s</div></td>',
			esc_url( $detail_url ),
			(int) $record->id,
			esc_html( $this->ago( $record->created_at ) )
		);

		printf( '<td>%s</td>', $this->status_pill( $record ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in status_pill().

		printf(
			'<td><a href="%1$s"><strong>%2$s</strong></a><div class="idta-partial-sub">%3$s</div>%4$s</td>',
			esc_url( $detail_url ),
			esc_html( '' !== $record->full_name() ? $record->full_name() : __( '(no name)', 'idta-partial' ) ),
			esc_html( $record->email ),
			'' !== $record->phone ? '<div class="idta-partial-sub">' . esc_html( $record->phone ) . '</div>' : ''
		);

		printf( '<td>%s</td>', $this->token_cell( $record ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in token_cell().

		printf(
			'<td>%1$s%2$s</td>',
			esc_html( $this->application_label( $record ) ),
			$record->validity_years > 0
				? '<div class="idta-partial-sub">' . esc_html(
					sprintf(
						/* translators: %d: number of years. */
						_n( '%d year', '%d years', $record->validity_years, 'idta-partial' ),
						(int) $record->validity_years
					)
				) . '</div>'
				: ''
		);

		printf( '<td>%s</td>', $this->reminder_cell( $record ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in reminder_cell().

		if ( $record->order_id > 0 ) {
			printf(
				'<td><a href="%1$s">#%2$d</a></td>',
				esc_url( $this->order_url( $record->order_id ) ),
				(int) $record->order_id
			);
		} else {
			echo '<td>—</td>';
		}

		echo '</tr>';
	}

	/**
	 * The lead-token cell, with a copy button.
	 *
	 * Shown truncated: the token is 36 characters and would otherwise be the
	 * widest column on the screen, while nobody reads one — they copy it, to
	 * search an order's meta or to match a support ticket.
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function token_cell( Record $record ): string {
		if ( '' === $record->lead_token ) {
			return '—';
		}

		return sprintf(
			'<code class="idta-partial-token" title="%1$s">%2$s</code>'
			. '<button type="button" class="button-link idta-partial-copy" data-copy="%1$s" aria-label="%3$s" title="%3$s">'
			. '<span class="dashicons dashicons-clipboard"></span></button>',
			esc_attr( $record->lead_token ),
			esc_html( substr( $record->lead_token, 0, 8 ) . '…' ),
			esc_attr__( 'Copy lead token', 'idta-partial' )
		);
	}

	/**
	 * The status pill.
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function status_pill( Record $record ): string {
		$out = sprintf(
			'<span class="idta-pill idta-pill--%1$s">%2$s</span>',
			esc_attr( $record->status ),
			esc_html( $this->status_label( $record->status ) )
		);

		// Two separate reasons a lead will not be emailed, and they need telling
		// apart at a glance: one is the shop's decision and reversible here, the
		// other is the customer's and is not.
		if ( $record->unsubscribed ) {
			$out .= sprintf(
				'<span class="idta-pill idta-pill--muted" title="%1$s">%2$s</span>',
				esc_attr__( 'The customer used the unsubscribe link. Staff cannot override this.', 'idta-partial' ),
				esc_html__( 'Unsubscribed', 'idta-partial' )
			);
		} elseif ( ! $record->reminder_enabled ) {
			$out .= sprintf(
				'<span class="idta-pill idta-pill--off" title="%1$s">%2$s</span>',
				esc_attr__( 'Reminders were switched off for this lead on this screen.', 'idta-partial' ),
				esc_html__( 'Reminders off', 'idta-partial' )
			);
		}

		return $out;
	}

	/**
	 * The reminder cell: when it went, or when it next will.
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function reminder_cell( Record $record ): string {
		$lines = array();

		if ( $record->was_reminded() ) {
			$lines[] = sprintf(
				'<span class="idta-partial-ok dashicons dashicons-yes"></span> %s',
				esc_html( $this->ago( (string) $record->reminder_sent_at ) )
			);

			if ( $record->reminder_count > 1 ) {
				$lines[] = sprintf(
					'<span class="idta-partial-sub">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: number of times. */
							_n( 'sent %d time', 'sent %d times', $record->reminder_count, 'idta-partial' ),
							(int) $record->reminder_count
						)
					)
				);
			}
		}

		/*
		 * The row's own reminder_due_at, not Action Scheduler.
		 *
		 * next_run_at() is the more accurate answer — it knows when the queue
		 * will really pick the job up — but it is one query per lead, and a full
		 * page of 25 open leads meant 25 extra queries to render a column most
		 * people skim. The stored time is already in hand and is within seconds
		 * of it in every ordinary case. The detail view, which is one lead and
		 * where someone is actually asking the question, still consults the
		 * scheduler.
		 */
		$next = $record->is_open() ? $record->reminder_due_at : null;

		if ( null !== $next ) {
			$lines[] = sprintf(
				'<span class="idta-partial-sub" title="%1$s">%2$s</span>',
				esc_attr(
					sprintf(
						/* translators: %s: UTC datetime. */
						__( 'Due at %s UTC. Action Scheduler only runs on site traffic, so a quiet site may run it later — open the lead for the queue\'s own answer.', 'idta-partial' ),
						$next
					)
				),
				esc_html(
					sprintf(
						/* translators: %s: relative time, e.g. "in 9 minutes". */
						__( 'next: %s', 'idta-partial' ),
						$this->ago( $next )
					)
				)
			);
		}

		if ( array() === $lines ) {
			$lines[] = '<span class="idta-partial-sub">' . esc_html__( 'none scheduled', 'idta-partial' ) . '</span>';
		}

		return implode( '', array_map( fn( $line ) => '<div>' . $line . '</div>', $lines ) )
			. $this->row_actions( $record );
	}

	/**
	 * The row's action links.
	 *
	 * @param Record $record Lead.
	 * @param string $back   'detail' to return to the detail view.
	 *
	 * @return string
	 */
	private function row_actions( Record $record, string $back = '' ): string {
		$links = array();

		if ( ! $record->unsubscribed ) {
			$links[] = sprintf(
				'<a href="%1$s" onclick="return confirm(%2$s)">%3$s</a>',
				esc_url( $this->action_url( $record, 'send_now', $back ) ),
				esc_attr( wp_json_encode( __( 'Send this customer a reminder email now?', 'idta-partial' ) ) ),
				esc_html__( 'Send now', 'idta-partial' )
			);
		}

		$links[] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $this->action_url( $record, $record->reminder_enabled ? 'remind_off' : 'remind_on', $back ) ),
			esc_html( $record->reminder_enabled ? __( 'Turn reminders off', 'idta-partial' ) : __( 'Turn reminders on', 'idta-partial' ) )
		);

		$links[] = sprintf(
			'<a href="%1$s" class="idta-partial-danger" onclick="return confirm(%2$s)">%3$s</a>',
			esc_url( $this->action_url( $record, 'delete', $back ) ),
			esc_attr(
				wp_json_encode(
					sprintf(
						/* translators: %d: lead ID. */
						__( 'Delete lead #%d permanently? This cannot be undone.', 'idta-partial' ),
						$record->id
					)
				)
			),
			esc_html__( 'Delete', 'idta-partial' )
		);

		return '<div class="idta-partial-actions row-actions">' . implode( ' <span>|</span> ', $links ) . '</div>';
	}

	/**
	 * Everything stored about one lead.
	 *
	 * @param int $lead_id Lead ID.
	 */
	private function render_detail( int $lead_id ): void {
		$record = $this->repository->find( $lead_id );

		if ( null === $record ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'That lead no longer exists.', 'idta-partial' ) );

			return;
		}

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Back to all leads', 'idta-partial' )
		);

		printf(
			'<h2>%1$s %2$s</h2>',
			esc_html(
				sprintf(
					/* translators: %d: lead ID. */
					__( 'Lead #%d', 'idta-partial' ),
					$record->id
				)
			),
			$this->status_pill( $record ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_pill().
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in row_actions().
		echo '<p>' . $this->row_actions( $record, 'detail' ) . '</p>';

		echo '<div class="idta-partial-panels">';

		$this->render_panel(
			__( 'Application', 'idta-partial' ),
			array(
				__( 'Name', 'idta-partial' )            => $record->full_name(),
				__( 'Email', 'idta-partial' )           => $record->email,
				__( 'Phone', 'idta-partial' )           => $record->phone,
				__( 'Date of birth', 'idta-partial' )   => (string) $record->payload( 'date_of_birth', '' ),
				__( 'Gender', 'idta-partial' )          => (string) $record->payload( 'gender', '' ),
				__( 'Country of birth', 'idta-partial' ) => $this->country( $record, 'country_of_birth' ),
				__( 'Residence', 'idta-partial' )       => $this->country( $record, 'country_of_residence' ),
				__( 'Licence classes', 'idta-partial' ) => $this->categories( $record ),
				__( 'Holds a licence', 'idta-partial' ) => (string) $record->payload( 'has_license', '' ),
			)
		);

		$this->render_panel(
			__( 'Route and plan', 'idta-partial' ),
			array(
				__( 'Licence issued in', 'idta-partial' ) => $this->country( $record, 'license_issued_country' ),
				__( 'Destination', 'idta-partial' )       => $this->country( $record, 'destination_country' ),
				__( 'Package', 'idta-partial' )           => $this->application_label( $record ),
				__( 'Validity', 'idta-partial' )          => (string) $record->payload( 'validity_label', '' ),
				__( 'Plan price', 'idta-partial' )        => (string) $record->payload( 'plan_price', '' ),
				__( 'Product ID', 'idta-partial' )        => $record->product_id > 0 ? (string) $record->product_id : '',
				__( 'Currency', 'idta-partial' )          => $record->currency,
				__( 'Add-ons', 'idta-partial' )           => $this->cart( $record ),
				__( 'Quoted total', 'idta-partial' )      => (string) $record->payload( 'summary_total', '' ),
			)
		);

		$this->render_panel(
			__( 'Tracking', 'idta-partial' ),
			array(
				__( 'Lead token', 'idta-partial' )     => $record->lead_token,
				__( 'Status', 'idta-partial' )         => $this->status_label( $record->status ),
				__( 'Created (UTC)', 'idta-partial' )  => $record->created_at,
				__( 'Updated (UTC)', 'idta-partial' )  => $record->updated_at,
				__( 'Reminder due (UTC)', 'idta-partial' )  => (string) $record->reminder_due_at,
				__( 'Reminder next run', 'idta-partial' )   => (string) $this->scheduler->next_run_at( $record->id ),
				__( 'Reminder sent (UTC)', 'idta-partial' ) => (string) $record->reminder_sent_at,
				__( 'Times sent', 'idta-partial' )     => (string) $record->reminder_count,
				__( 'Converted (UTC)', 'idta-partial' ) => (string) $record->converted_at,
				__( 'Order', 'idta-partial' )          => $record->order_id > 0 ? '#' . $record->order_id : '',
				__( 'Source', 'idta-partial' )         => $record->source,
				__( 'Locale', 'idta-partial' )         => $record->locale,
				__( 'Landing page', 'idta-partial' )   => (string) $record->payload( 'landing_path', '' ),
				__( 'Referrer', 'idta-partial' )       => (string) $record->payload( 'referrer', '' ),
				__( 'Campaign', 'idta-partial' )       => $this->utm( $record ),
			)
		);

		echo '</div>';

		printf(
			'<h3>%s</h3><p class="description">%s</p><p><code class="idta-partial-resume">%s</code></p>',
			esc_html__( 'Resume link', 'idta-partial' ),
			esc_html__( 'The link this lead\'s reminder email carries. It restores the plan and the countries only — never personal data, which must not travel in a URL.', 'idta-partial' ),
			esc_html( $this->resume->for_record( $record ) )
		);

		$this->render_copy_script();
	}

	/**
	 * One labelled panel of name/value rows.
	 *
	 * @param string                $title Panel heading.
	 * @param array<string,string>  $rows  Label => value.
	 */
	private function render_panel( string $title, array $rows ): void {
		echo '<div class="idta-partial-panel">';

		printf( '<h3>%s</h3><table class="idta-partial-kv">', esc_html( $title ) );

		foreach ( $rows as $label => $value ) {
			$value = trim( (string) $value );

			printf(
				'<tr><th>%1$s</th><td>%2$s</td></tr>',
				esc_html( $label ),
				'' === $value
					? '<span class="idta-partial-sub">—</span>'
					: esc_html( $value )
			);
		}

		echo '</table></div>';
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

		$this->sources_row( is_array( $values['sources'] ?? null ) ? $values['sources'] : Settings::DEFAULT_SOURCES );

		$this->number_row( 'reminder_cooldown_days', __( 'Reminder cooldown (days)', 'idta-partial' ), (int) $values['reminder_cooldown_days'], __( 'The same address is not reminded twice inside this window, however many applications it abandons. A manual "Send now" ignores this.', 'idta-partial' ) );

		$this->number_row( 'retention_days', __( 'Keep unconverted leads (days)', 'idta-partial' ), (int) $values['retention_days'], __( 'Deleted after this.', 'idta-partial' ) );

		$this->number_row( 'converted_retention_days', __( 'Keep converted lead details (days)', 'idta-partial' ), (int) $values['converted_retention_days'], __( 'After this the personal data is stripped; the conversion itself is kept for reporting.', 'idta-partial' ) );

		$this->number_row( 'rate_limit', __( 'Submissions per hour', 'idta-partial' ), (int) $values['rate_limit'], __( 'Per IP address and per email address.', 'idta-partial' ) );

		$this->render_api_key_row();

		echo '</table>';

		submit_button();

		echo '</form>';
	}

	/**
	 * The front-end sources row.
	 *
	 * Each row pairs the `source` value a front end sends — the same value
	 * idta-pdf stores as `_idp_order_from` — with the application URL a reminder
	 * for that front end should link back to. Two jobs in one setting, and both
	 * matter: the key is what the endpoint will accept as a source, and the URL
	 * is where that customer gets sent.
	 *
	 * @param array<string,string> $sources Configured sources.
	 */
	private function sources_row( array $sources ): void {
		printf( '<tr><th scope="row">%s</th><td>', esc_html__( 'Front ends', 'idta-partial' ) );

		echo '<table class="widefat idta-partial-sources"><thead><tr>';

		printf(
			'<th style="width:28%%">%1$s</th><th>%2$s</th><th style="width:1%%"></th>',
			esc_html__( 'Order from', 'idta-partial' ),
			esc_html__( 'Application URL', 'idta-partial' )
		);

		echo '</tr></thead><tbody>';

		// An empty row on the end, so adding one needs no JavaScript at all —
		// the screen stays usable if the inline script below fails to run.
		$rows = $sources;

		$rows[''] = '';

		$index = 0;

		foreach ( $rows as $key => $url ) {
			printf(
				'<tr>'
				. '<td><input type="text" name="idta_partial[sources][%1$d][key]" value="%2$s" class="regular-text" placeholder="idta"></td>'
				. '<td><input type="url" name="idta_partial[sources][%1$d][url]" value="%3$s" class="large-text" placeholder="https://example.com/application.html"></td>'
				. '<td><button type="button" class="button-link idta-partial-danger idta-partial-row-remove" aria-label="%4$s">&times;</button></td>'
				. '</tr>',
				$index,
				esc_attr( (string) $key ),
				esc_attr( (string) $url ),
				esc_attr__( 'Remove this front end', 'idta-partial' )
			);

			++$index;
		}

		echo '</tbody></table>';

		printf(
			'<p><button type="button" class="button idta-partial-row-add">%s</button></p>',
			esc_html__( 'Add front end', 'idta-partial' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'The storefront sends this key as "source" when it saves a partial application, and WooCommerce stores the same value as _idp_order_from. A reminder links back to the URL for the source the application was started on, so a customer who began on one site is never sent to another. A key with no URL still works for capture; it just cannot be linked back to.',
				'idta-partial'
			)
		);

		echo '</td></tr>';

		$this->render_sources_script( $index );
	}

	/**
	 * Add/remove behaviour for the sources table.
	 *
	 * @param int $next_index Index the next added row should use.
	 */
	private function render_sources_script( int $next_index ): void {
		?>
		<script>
			( function () {
				var table = document.querySelector( '.idta-partial-sources tbody' );
				var addBtn = document.querySelector( '.idta-partial-row-add' );

				if ( ! table || ! addBtn ) {
					return;
				}

				var next = <?php echo (int) $next_index; ?>;

				addBtn.addEventListener( 'click', function () {
					var row = table.rows[ table.rows.length - 1 ].cloneNode( true );

					row.querySelectorAll( 'input' ).forEach( function ( input ) {
						input.value = '';
						// Re-index, or the new row would overwrite the one it
						// was cloned from when the form posts.
						input.name = input.name.replace( /\[sources\]\[\d+\]/, '[sources][' + next + ']' );
					} );

					next++;
					table.appendChild( row );
				} );

				table.addEventListener( 'click', function ( event ) {
					if ( ! event.target.closest( '.idta-partial-row-remove' ) ) {
						return;
					}

					// Never remove the last row: with none left there is nothing
					// to clone, and the Add button would stop working.
					if ( table.rows.length > 1 ) {
						event.target.closest( 'tr' ).remove();
						return;
					}

					table.rows[ 0 ].querySelectorAll( 'input' ).forEach( function ( input ) {
						input.value = '';
					} );
				} );
			} )();
		</script>
		<?php
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
				'<p><span class="idta-pill idta-pill--converted">%s</span></p><p class="description">%s</p>',
				esc_html__( 'Configured in wp-config.php', 'idta-partial' ),
				esc_html__( 'IDTA_PARTIAL_API_KEY is defined, which is the recommended place for it. Remove the constant to manage the key here instead.', 'idta-partial' )
			);

			echo '</td></tr>';

			return;
		}

		printf(
			'<p><span class="idta-pill idta-pill--%1$s">%2$s</span></p>',
			$configured ? 'converted' : 'off',
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

	// -------------------------------------------------------------------------
	// Formatting helpers
	// -------------------------------------------------------------------------

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
	 * What the customer was buying.
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function application_label( Record $record ): string {
		return 'digital_only' === $record->application_type
			? __( 'Digital only', 'idta-partial' )
			: __( 'Printed + digital', 'idta-partial' );
	}

	/**
	 * A country from the payload, as "Name (CC)".
	 *
	 * @param Record $record Lead.
	 * @param string $key    Payload key.
	 *
	 * @return string
	 */
	private function country( Record $record, string $key ): string {
		$country = $record->payload( $key );

		if ( ! is_array( $country ) ) {
			return '';
		}

		$name = trim( (string) ( $country['name'] ?? '' ) );
		$code = trim( (string) ( $country['code'] ?? '' ) );

		if ( '' === $name ) {
			return $code;
		}

		return '' === $code ? $name : $name . ' (' . $code . ')';
	}

	/**
	 * Licence classes, as "B — Passenger Cars".
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function categories( Record $record ): string {
		$categories = $record->payload( 'license_categories' );

		if ( ! is_array( $categories ) ) {
			return '';
		}

		$out = array();

		foreach ( $categories as $category ) {
			if ( is_array( $category ) ) {
				$value = trim( (string) ( $category['value'] ?? '' ) );
				$label = trim( (string) ( $category['label'] ?? '' ) );

				$out[] = '' === $label ? $value : $value . ' — ' . $label;

				continue;
			}

			$out[] = (string) $category;
		}

		return implode( ', ', array_filter( $out ) );
	}

	/**
	 * Add-ons, as "Faster Processing ×1".
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function cart( Record $record ): string {
		$items = $record->payload( 'cart_items' );

		if ( ! is_array( $items ) ) {
			return '';
		}

		$out = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$name = trim( (string) ( $item['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$qty   = max( 1, (int) ( $item['qty'] ?? 1 ) );
			$price = trim( (string) ( $item['price'] ?? '' ) );

			$out[] = $name . ' ×' . $qty . ( '' !== $price ? ' (' . $price . ')' : '' );
		}

		return implode( ', ', $out );
	}

	/**
	 * Campaign parameters, flattened.
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function utm( Record $record ): string {
		$utm = $record->payload( 'utm' );

		if ( ! is_array( $utm ) || array() === $utm ) {
			return '';
		}

		$out = array();

		foreach ( $utm as $key => $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$out[] = $key . '=' . $value;
			}
		}

		return implode( ' · ', $out );
	}

	/**
	 * A UTC datetime as "9 minutes ago" or "in 9 minutes".
	 *
	 * Relative because that is the question being asked of this screen — how
	 * long ago did they abandon, how soon does the reminder go — and a wall of
	 * identical-looking UTC timestamps answers it slowly. The exact value is in
	 * the title attribute and in the detail view.
	 *
	 * @param string $datetime UTC datetime.
	 *
	 * @return string
	 */
	private function ago( string $datetime ): string {
		$datetime = trim( $datetime );

		if ( '' === $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		if ( false === $timestamp ) {
			return $datetime;
		}

		$now = time();

		if ( $timestamp > $now ) {
			/* translators: %s: human-readable duration, e.g. "9 minutes". */
			return sprintf( __( 'in %s', 'idta-partial' ), human_time_diff( $now, $timestamp ) );
		}

		/* translators: %s: human-readable duration, e.g. "9 minutes". */
		return sprintf( __( '%s ago', 'idta-partial' ), human_time_diff( $timestamp, $now ) );
	}

	/**
	 * The admin URL for an order, HPOS or not.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string
	 */
	private function order_url( int $order_id ): string {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof \WC_Order && method_exists( $order, 'get_edit_order_url' ) ) {
				return (string) $order->get_edit_order_url();
			}
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	// -------------------------------------------------------------------------
	// Form rows
	// -------------------------------------------------------------------------

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
	 * @param string               $key         Setting key.
	 * @param string               $label       Field label.
	 * @param string               $value       Current value.
	 * @param array<string,string> $options     Value => label.
	 * @param string               $description Help text.
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

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Screen styles.
	 *
	 * Inline rather than a stylesheet: it is under 2KB, it is used by exactly
	 * one screen, and a file would be one more thing to cache-bust on every
	 * release for no benefit.
	 */
	private function render_styles(): void {
		?>
		<style>
			.idta-partial .idta-pill {
				display: inline-block;
				padding: 2px 10px;
				margin: 0 4px 2px 0;
				border-radius: 999px;
				font-size: 11px;
				font-weight: 600;
				line-height: 1.7;
				text-transform: uppercase;
				letter-spacing: .3px;
				white-space: nowrap;
			}
			.idta-partial .idta-pill--new       { background: #e5f0fb; color: #12508f; }
			.idta-partial .idta-pill--reminded  { background: #fdf3e0; color: #8a5300; }
			.idta-partial .idta-pill--converted { background: #e3f4e8; color: #14612f; }
			.idta-partial .idta-pill--expired   { background: #eeeeee; color: #555555; }
			.idta-partial .idta-pill--off       { background: #fde8e8; color: #8c1c1c; }
			.idta-partial .idta-pill--muted     { background: #ededed; color: #4a4a4a; }

			.idta-partial-table td { vertical-align: top; }
			.idta-partial .idta-partial-sub,
			.idta-partial .idta-partial-when { color: #646970; font-size: 12px; }
			.idta-partial .idta-partial-ok { color: #14612f; font-size: 16px; line-height: 1.2; }

			.idta-partial .idta-partial-token {
				font-size: 11px;
				padding: 2px 6px;
				background: #f2f2f2;
				border-radius: 3px;
			}
			.idta-partial .idta-partial-copy {
				vertical-align: middle;
				color: #646970;
				text-decoration: none;
				cursor: pointer;
			}
			.idta-partial .idta-partial-copy:hover { color: #135e96; }
			.idta-partial .idta-partial-copy .dashicons { font-size: 16px; width: 16px; height: 16px; }
			.idta-partial .idta-partial-copy.is-copied { color: #14612f; }

			/* Row actions are normally revealed on hover, which hides them from
			   touch entirely and makes them hard to find on a screen where they
			   are the point. Always visible here. */
			.idta-partial .idta-partial-actions { visibility: visible; left: 0; margin-top: 6px; }
			.idta-partial .idta-partial-danger { color: #b32d2e; }

			.idta-partial-panels {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
				gap: 16px;
				margin-top: 16px;
			}
			.idta-partial-panel {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 4px 16px 12px;
			}
			.idta-partial-kv { width: 100%; border-collapse: collapse; }
			.idta-partial-kv th {
				text-align: left;
				font-weight: 500;
				color: #646970;
				padding: 6px 12px 6px 0;
				vertical-align: top;
				width: 42%;
			}
			.idta-partial-kv td { padding: 6px 0; word-break: break-word; }
			.idta-partial-kv tr + tr th,
			.idta-partial-kv tr + tr td { border-top: 1px solid #f0f0f1; }
			.idta-partial-resume { display: inline-block; padding: 8px 12px; word-break: break-all; }
		</style>
		<?php
	}

	/**
	 * The copy-to-clipboard behaviour.
	 *
	 * One delegated listener, so it covers rows added by any later paint, and no
	 * dependency on jQuery or on an enqueued file.
	 */
	private function render_copy_script(): void {
		?>
		<script>
			( function () {
				document.addEventListener( 'click', function ( event ) {
					var button = event.target.closest( '.idta-partial-copy' );

					if ( ! button ) {
						return;
					}

					event.preventDefault();

					var value = button.getAttribute( 'data-copy' ) || '';

					function done() {
						button.classList.add( 'is-copied' );
						setTimeout( function () { button.classList.remove( 'is-copied' ); }, 1200 );
					}

					// navigator.clipboard needs a secure context, and plenty of
					// WordPress admins are still served over plain HTTP on a
					// local or staging host — so the old execCommand path stays
					// as the fallback rather than the button silently failing.
					if ( navigator.clipboard && window.isSecureContext ) {
						navigator.clipboard.writeText( value ).then( done ).catch( fallback );
						return;
					}

					fallback();

					function fallback() {
						var field = document.createElement( 'textarea' );
						field.value = value;
						field.setAttribute( 'readonly', '' );
						field.style.position = 'fixed';
						field.style.opacity = '0';
						document.body.appendChild( field );
						field.select();
						try { document.execCommand( 'copy' ); done(); } catch ( e ) { /* nothing more to try */ }
						document.body.removeChild( field );
					}
				} );
			} )();
		</script>
		<?php
	}
}

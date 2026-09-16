<?php
/**
 * Queues and runs the one reminder a lead may receive.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules the reminder, and decides at the last moment whether to send it.
 *
 * The guard is the lead's own status, not the scheduler's state. A queue can be
 * cleared, a job lost, an action run twice by a worker that timed out and
 * retried; none of that may produce a second email, and a lead that converted
 * after the job was queued must produce none. Reading the row at the moment of
 * sending is the only check that holds under all of those.
 */
final class Reminder_Scheduler {

	/**
	 * Scheduled action that sends the reminder.
	 */
	public const HOOK = 'idta_partial_send_reminder';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'idta-partial';

	/**
	 * How many numbered checks the job reports.
	 */
	private const STEPS = 8;

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
	 * Job log.
	 *
	 * @var Job_Log
	 */
	private Job_Log $log;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Repository $repository Lead storage.
	 * @param Job_Log    $log        Job log.
	 */
	public function __construct( Settings $settings, Repository $repository, Job_Log $log ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->log        = $log;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// Both the Action Scheduler named-argument and WP-Cron positional signatures.
		add_action( self::HOOK, array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Queue the reminder for a freshly inserted lead.
	 *
	 * Called only from the insert branch of the REST endpoint. An update never
	 * reaches here, so editing the form does not push the reminder further out.
	 *
	 * @param Record $record Lead.
	 *
	 * @return int Action ID, or 0 when WP-Cron took it.
	 */
	public function schedule( Record $record ): int {
		$due = time() + $this->settings->reminder_delay();

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			if ( ! wp_next_scheduled( self::HOOK, array( $record->id ) ) ) {
				wp_schedule_single_event( $due, self::HOOK, array( $record->id ) );
			}

			return 0;
		}

		if ( as_has_scheduled_action( self::HOOK, array( 'partial_id' => $record->id ), self::GROUP ) ) {
			return 0;
		}

		$action_id = (int) as_schedule_single_action( $due, self::HOOK, array( 'partial_id' => $record->id ), self::GROUP );

		/*
		 * Say what this queued action is for while it is still pending. Without
		 * it the Scheduled Actions screen lists a row of identical pending
		 * reminders distinguishable only by an opaque partial_id.
		 */
		$this->log->write_to(
			$action_id,
			sprintf(
				/* translators: 1: lead ID, 2: masked email address, 3: due time. */
				__( 'Queued for partial application #%1$d (%2$s). Due %3$s UTC, unless an order arrives first.', 'idta-partial' ),
				$record->id,
				Job_Log::mask_email( $record->email ),
				gmdate( 'Y-m-d H:i:s', $due )
			)
		);

		return $action_id;
	}

	/**
	 * Note on a pending reminder that its lead has converted.
	 *
	 * The action is deliberately left queued rather than cancelled. Cancelling
	 * deletes it, and with it the record that a reminder was ever due — so the
	 * ordinary, successful case would leave no trace at all. Left in place it
	 * runs, finds the lead converted, writes that down and sends nothing, which
	 * is both the required behaviour and a readable audit trail.
	 *
	 * @param int $partial_id Lead ID.
	 * @param int $order_id   Order that converted it.
	 */
	public function note_converted( int $partial_id, int $order_id ): void {
		foreach ( $this->pending_actions( $partial_id ) as $action_id ) {
			$this->log->write_to(
				$action_id,
				sprintf(
					/* translators: 1: lead ID, 2: order ID. */
					__( 'Partial application #%1$d converted to order #%2$d. This reminder will send nothing when it runs.', 'idta-partial' ),
					$partial_id,
					$order_id
				)
			);
		}
	}

	/**
	 * Drop a lead's pending reminder entirely.
	 *
	 * Only used when the lead itself is going away — the cleanup job — where
	 * leaving the action queued would mean a job that wakes up to a missing row.
	 *
	 * @param int $partial_id Lead ID.
	 */
	public function unschedule( int $partial_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( 'partial_id' => $partial_id ), self::GROUP );

			return;
		}

		$timestamp = wp_next_scheduled( self::HOOK, array( $partial_id ) );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK, array( $partial_id ) );
		}
	}

	/**
	 * Run the reminder.
	 *
	 * Every check writes its own line into this action's log, in order, so the
	 * Scheduled Actions screen answers "what happened to this lead?" on its own.
	 *
	 * @param int|array<string,mixed> $args Lead ID, or the Action Scheduler argument bag.
	 */
	public function run( $args ): void {
		$partial_id = is_array( $args ) ? (int) ( $args['partial_id'] ?? 0 ) : (int) $args;

		$this->log->begin(
			sprintf(
				/* translators: %d: lead ID. */
				__( 'Processing the reminder for partial application #%d.', 'idta-partial' ),
				$partial_id
			),
			self::STEPS
		);

		if ( ! $this->settings->enabled() ) {
			$this->log->finish( __( 'Partial applications are switched off in the settings; nothing sent.', 'idta-partial' ) );

			return;
		}

		// 1. The lead still exists.
		$record = $this->repository->find( $partial_id );

		if ( null === $record ) {
			$this->log->step( __( 'Lead not found — it was deleted or cleaned up.', 'idta-partial' ) );
			$this->log->finish( __( 'Nothing sent.', 'idta-partial' ) );

			return;
		}

		$this->log->step(
			sprintf(
				/* translators: 1: masked email address, 2: lead status, 3: creation time. */
				__( 'Lead loaded: %1$s, status "%2$s", created %3$s UTC.', 'idta-partial' ),
				Job_Log::mask_email( $record->email ),
				$record->status,
				$record->created_at
			)
		);

		// 2. It has not already converted or been reminded.
		if ( ! $record->is_open() ) {
			$this->log->step(
				sprintf(
					/* translators: %s: lead status. */
					__( 'Status is "%s", not "new".', 'idta-partial' ),
					$record->status
				)
			);

			$this->log->finish(
				Record::STATUS_CONVERTED === $record->status
					? sprintf(
						/* translators: %d: order ID. */
						__( 'The customer completed order #%d; nothing sent.', 'idta-partial' ),
						$record->order_id
					)
					: __( 'This lead has already been processed; nothing sent.', 'idta-partial' )
			);

			return;
		}

		$this->log->step( __( 'Status is "new", so a reminder is still due.', 'idta-partial' ) );

		// 3. There is somewhere to send it.
		if ( '' === $record->email || ! is_email( $record->email ) ) {
			$this->log->step( __( 'No usable email address on the lead.', 'idta-partial' ) );
			$this->log->finish( __( 'Nothing sent.', 'idta-partial' ) );

			return;
		}

		$this->log->step( __( 'Email address is valid.', 'idta-partial' ) );

		// 4. The customer has not opted out.
		if ( $record->unsubscribed || Unsubscribe::is_suppressed( $record->email ) ) {
			$this->log->step( __( 'This address has opted out of reminders.', 'idta-partial' ) );
			$this->repository->mark_reminded( $record->id, false );
			$this->log->finish( __( 'Nothing sent; the lead is closed.', 'idta-partial' ) );

			return;
		}

		$this->log->step( __( 'Address is not on the suppression list.', 'idta-partial' ) );

		// 5. It has not been nudged recently from another attempt.
		$cooldown = (int) $this->settings->get( 'reminder_cooldown_days', 7 );

		if ( $this->repository->reminded_recently( $record->email, $cooldown, $record->id ) ) {
			$this->log->step(
				sprintf(
					/* translators: %d: cooldown in days. */
					__( 'This address was already reminded within the last %d day(s).', 'idta-partial' ),
					$cooldown
				)
			);

			$this->repository->mark_reminded( $record->id, false );
			$this->log->finish( __( 'Nothing sent; the cooldown applies.', 'idta-partial' ) );

			return;
		}

		$this->log->step( __( 'No reminder has gone to this address recently.', 'idta-partial' ) );

		// 6. WooCommerce itself has no matching order.
		/*
		 * In payment_complete mode an unpaid order is not a conversion, so only
		 * the paid statuses may suppress the reminder here — otherwise this
		 * check would undo the whole point of that mode.
		 */
		$statuses = $this->settings->converts_on_order() || ! function_exists( 'wc_get_is_paid_statuses' )
			? array()
			: (array) wc_get_is_paid_statuses();

		$order_id = $this->repository->find_order_for_email( $record->email, $record->created_at, $statuses );

		if ( $order_id > 0 ) {
			$this->log->step(
				sprintf(
					/* translators: %d: order ID. */
					__( 'WooCommerce already holds order #%d for this address, which the conversion listener had not recorded.', 'idta-partial' ),
					$order_id
				)
			);

			$this->repository->mark_converted( $record->id, $order_id );
			$this->log->finish( __( 'Lead marked converted; nothing sent.', 'idta-partial' ) );

			return;
		}

		$this->log->step( __( 'No WooCommerce order exists for this address; the application really was abandoned.', 'idta-partial' ) );

		// 7. Hand it to WooCommerce's mailer.
		$email = $this->email();

		if ( null === $email ) {
			$this->log->step( __( 'The reminder email is not registered with WooCommerce.', 'idta-partial' ) );
			$this->log->finish( __( 'Nothing sent.', 'idta-partial' ) );

			return;
		}

		$sent = $email->trigger( $record );

		$this->log->step(
			$sent
				? sprintf(
					/* translators: %s: masked email address. */
					__( 'Reminder handed to the mailer for %s.', 'idta-partial' ),
					Job_Log::mask_email( $record->email )
				)
				: __( 'The mailer refused the message — the email may be disabled in WooCommerce → Settings → Emails, or sending failed.', 'idta-partial' )
		);

		// 8. Close the lead either way.
		$this->repository->mark_reminded( $record->id, $sent );

		$this->log->step(
			$sent
				? __( 'Lead marked "reminded".', 'idta-partial' )
				: __( 'Lead marked "reminded" despite the failure — one attempt per lead, by design.', 'idta-partial' )
		);

		$this->log->finish(
			$sent
				? __( 'Reminder sent.', 'idta-partial' )
				: __( 'Reminder could not be sent.', 'idta-partial' )
		);
	}

	/**
	 * The registered reminder email, when WooCommerce has one.
	 *
	 * @return Reminder_Email|null
	 */
	private function email(): ?Reminder_Email {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$mailer = WC()->mailer();

		if ( ! is_object( $mailer ) ) {
			return null;
		}

		$emails = $mailer->get_emails();
		$email  = $emails['IDTA_Partial_Reminder'] ?? null;

		return $email instanceof Reminder_Email ? $email : null;
	}

	/**
	 * Pending action IDs for a lead.
	 *
	 * @param int $partial_id Lead ID.
	 *
	 * @return int[]
	 */
	private function pending_actions( int $partial_id ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => array( 'partial_id' => $partial_id ),
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 5,
			),
			'ids'
		);

		return is_array( $actions ) ? array_map( 'absint', $actions ) : array();
	}
}

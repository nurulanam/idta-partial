<?php
/**
 * Retention.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * The daily job that ages leads out.
 *
 * Two different fates, because the two kinds of row are worth different things.
 * An unconverted lead is personal data the store was never given permission to
 * keep indefinitely, and once it is too old to act on it is pure liability, so
 * it goes. A converted lead is a row in the store's conversion history, and
 * deleting it would quietly rewrite last year's numbers — so its personal data
 * is stripped and the shape of it kept.
 */
final class Cleanup {

	/**
	 * Recurring action.
	 */
	public const HOOK = 'idta_partial_cleanup';

	/**
	 * Rows touched per run, per kind.
	 */
	private const BATCH = 500;

	/**
	 * How many numbered steps the job reports.
	 */
	private const STEPS = 3;

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
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Make sure the daily job is queued.
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
			}

			return;
		}

		if ( as_has_scheduled_action( self::HOOK, array(), Reminder_Scheduler::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + HOUR_IN_SECONDS,
			DAY_IN_SECONDS,
			self::HOOK,
			array(),
			Reminder_Scheduler::GROUP
		);
	}

	/**
	 * Age out old rows.
	 */
	public function run(): void {
		$this->log->begin( __( 'Cleaning up old partial applications.', 'idta-partial' ), self::STEPS );

		if ( ! Table::exists() ) {
			$this->log->finish( __( 'No partial-applications table; nothing to do.', 'idta-partial' ) );

			return;
		}

		$unconverted_days = (int) $this->settings->get( 'retention_days', 90 );
		$converted_days   = (int) $this->settings->get( 'converted_retention_days', 365 );

		$this->log->step(
			sprintf(
				/* translators: 1: days, 2: days. */
				__( 'Retention: unconverted leads %1$d days, converted leads %2$d days.', 'idta-partial' ),
				$unconverted_days,
				$converted_days
			)
		);

		$deleted = $this->repository->purge_unconverted( $unconverted_days, self::BATCH );

		$this->log->step(
			sprintf(
				/* translators: %d: number of rows. */
				__( 'Deleted %d unconverted lead(s) past their retention date.', 'idta-partial' ),
				$deleted
			)
		);

		$anonymised = $this->repository->anonymise_converted( $converted_days, self::BATCH );

		$this->log->step(
			sprintf(
				/* translators: %d: number of rows. */
				__( 'Anonymised %d converted lead(s), keeping their conversion history.', 'idta-partial' ),
				$anonymised
			)
		);

		$this->log->finish(
			0 === $deleted && 0 === $anonymised
				? __( 'Nothing was old enough to clean up.', 'idta-partial' )
				: sprintf(
					/* translators: 1: rows deleted, 2: rows anonymised. */
					__( 'Done: %1$d deleted, %2$d anonymised.', 'idta-partial' ),
					$deleted,
					$anonymised
				)
		);

		/*
		 * A full batch means there is more waiting. Rather than loop here — and
		 * risk a job that runs until the host kills it, leaving the work half
		 * done and the log misleading — queue one more pass shortly.
		 */
		if ( ( $deleted >= self::BATCH || $anonymised >= self::BATCH ) && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::HOOK, array(), Reminder_Scheduler::GROUP );
		}
	}
}

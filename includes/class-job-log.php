<?php
/**
 * Step-by-step logging into Action Scheduler.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a running job's progress into the action's own log.
 *
 * WooCommerce → Status → Scheduled Actions shows a Log column per action, and by
 * default it holds two lines: created, and completed. That is equally true of a
 * reminder that went out, one that was skipped because the lead had converted,
 * and one that found no usable address — which makes the screen useless for the
 * question anyone actually opens it with, namely "what happened to this lead?".
 *
 * So each job narrates itself: every check it makes is a numbered line against
 * the action that made it. A support request about one customer is then answered
 * by reading one action's log, rather than by inferring from the database what
 * the job must have decided.
 *
 * What is never written here: address, phone, date of birth, licence number,
 * payload, tokens. Action Scheduler logs are long-lived, world-readable to any
 * shop manager, and survive the retention job that cleans the leads themselves.
 * Identifiers and outcomes only — with the address masked where naming it is the
 * difference between a usable log line and a riddle.
 */
final class Job_Log {

	/**
	 * Sole instance.
	 *
	 * @var Job_Log|null
	 */
	private static ?Job_Log $instance = null;

	/**
	 * The action currently being executed, when there is one.
	 *
	 * @var int
	 */
	private int $running = 0;

	/**
	 * How many steps the running job announced.
	 *
	 * @var int
	 */
	private int $total = 0;

	/**
	 * How many it has reported so far.
	 *
	 * @var int
	 */
	private int $done = 0;

	/**
	 * Retrieve the sole instance.
	 *
	 * @return Job_Log
	 */
	public static function instance(): Job_Log {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		/*
		 * Noted for every action, not only this plugin's: the id is read back
		 * only from inside this plugin's own jobs, and filtering by hook here
		 * would mean maintaining a list of them.
		 */
		add_action( 'action_scheduler_before_execute', array( $this, 'note_running' ), 10, 1 );
		add_action( 'action_scheduler_after_execute', array( $this, 'forget_running' ), 10, 1 );
	}

	/**
	 * Remember the action being executed.
	 *
	 * @param int|string $action_id Action ID.
	 */
	public function note_running( $action_id ): void {
		$this->running = absint( $action_id );
		$this->total   = 0;
		$this->done    = 0;
	}

	/**
	 * Forget it again.
	 *
	 * @param int|string $action_id Action ID.
	 */
	public function forget_running( $action_id ): void {
		unset( $action_id );

		$this->running = 0;
		$this->total   = 0;
		$this->done    = 0;
	}

	/**
	 * Open a run, naming how many steps it intends to take.
	 *
	 * @param string $title What this job is doing.
	 * @param int    $steps How many numbered steps follow.
	 */
	public function begin( string $title, int $steps ): void {
		$this->total = max( 0, $steps );
		$this->done  = 0;

		$this->write( $title );
	}

	/**
	 * Report one step.
	 *
	 * @param string $message What the step found or did.
	 */
	public function step( string $message ): void {
		++$this->done;

		$this->write(
			$this->total > 0
				? sprintf( '[%d/%d] %s', $this->done, $this->total, $message )
				: sprintf( '[%d] %s', $this->done, $message )
		);
	}

	/**
	 * Report the outcome and close the run.
	 *
	 * @param string $message What happened in the end.
	 */
	public function finish( string $message ): void {
		$this->write( '→ ' . $message );

		$this->total = 0;
		$this->done  = 0;
	}

	/**
	 * Write a line against an action other than the running one.
	 *
	 * Used at scheduling time, so a queued reminder says what it is for before
	 * it ever runs, and at conversion time, so a reminder that is about to
	 * become a no-op says why while it is still pending.
	 *
	 * @param int    $action_id Action ID.
	 * @param string $message   Line to write.
	 */
	public function write_to( int $action_id, string $message ): void {
		if ( $action_id <= 0 || ! class_exists( '\ActionScheduler' ) ) {
			return;
		}

		try {
			\ActionScheduler::logger()->log( $action_id, $message );
		} catch ( \Throwable $e ) {
			// Logging must never be the thing that fails a job.
			$this->to_error_log( $message );
		}
	}

	/**
	 * Mask an address for a log line.
	 *
	 * Enough to recognise the customer a support ticket is about, not enough to
	 * be a leak of the address itself.
	 *
	 * @param string $email Address.
	 *
	 * @return string
	 */
	public static function mask_email( string $email ): string {
		$at = strpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return '(no address)';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$dot    = strrpos( $domain, '.' );
		$tld    = false === $dot ? '' : substr( $domain, $dot );

		return substr( $local, 0, 1 ) . str_repeat( '*', max( 1, strlen( $local ) - 1 ) )
			. '@' . substr( $domain, 0, 1 ) . str_repeat( '*', max( 1, ( false === $dot ? strlen( $domain ) : $dot ) - 1 ) ) . $tld;
	}

	/**
	 * Write to the running action, or to the debug log when there is none.
	 *
	 * @param string $message Line to write.
	 */
	private function write( string $message ): void {
		if ( $this->running > 0 ) {
			$this->write_to( $this->running, $message );

			return;
		}

		$this->to_error_log( $message );
	}

	/**
	 * Last resort: the debug log, and only when debugging is on.
	 *
	 * @param string $message Line to write.
	 */
	private function to_error_log( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[idta-partial] ' . $message );
	}
}

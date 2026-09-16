<?php
/**
 * Opting out of reminders.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * The unsubscribe link, and the suppression list it feeds.
 *
 * Handled on a plain front-end query argument rather than a REST route, because
 * the link is opened by a person in a mail client: it has to answer with a page
 * they can read, and it has to work with no login, no Origin header and no
 * JavaScript.
 */
final class Unsubscribe {

	/**
	 * Query argument carrying the resume token.
	 */
	public const QUERY_ARG = 'idta_partial_unsubscribe';

	/**
	 * Option holding suppressed addresses, hashed.
	 */
	private const OPTION = Settings::SUPPRESSION_KEY;

	/**
	 * Lead storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Lead storage.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
	}

	/**
	 * The link for one lead.
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	public static function url_for( Record $record ): string {
		return add_query_arg( self::QUERY_ARG, rawurlencode( $record->resume_token ), home_url( '/' ) );
	}

	/**
	 * Act on the link when it is opened.
	 */
	public function maybe_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A token from an email; there is no session to nonce against.
		$token = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_ARG ] ) ) : '';

		if ( '' === $token ) {
			return;
		}

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			$this->render( __( 'That unsubscribe link is not valid.', 'idta-partial' ) );
		}

		if ( ! $this->rate_limit_allows() ) {
			$this->render( __( 'Too many requests. Please try again in a few minutes.', 'idta-partial' ) );
		}

		$record = $this->repository->find_by_resume_token( $token );

		/*
		 * The same page either way. A link that reports "unknown token"
		 * differently from "done" is an oracle for testing tokens, and the
		 * person holding the link cannot act on the difference anyway.
		 */
		if ( null !== $record && '' !== $record->email ) {
			$this->repository->unsubscribe_email( $record->email );

			self::suppress( $record->email );
		}

		$this->render( __( 'You will not receive any further reminders about an unfinished application.', 'idta-partial' ) );
	}

	/**
	 * Add an address to the global suppression list.
	 *
	 * Stored hashed: the list outlives the leads it came from, and a plain list
	 * of addresses in an option is a list of people who once abandoned an
	 * application — worth nothing to the store and worth something to anyone who
	 * gets hold of the database.
	 *
	 * @param string $email Address.
	 */
	public static function suppress( string $email ): void {
		$hash = self::hash( $email );

		if ( '' === $hash ) {
			return;
		}

		$list = get_option( self::OPTION, array() );
		$list = is_array( $list ) ? $list : array();

		if ( in_array( $hash, $list, true ) ) {
			return;
		}

		$list[] = $hash;

		update_option( self::OPTION, $list, false );
	}

	/**
	 * Whether an address has opted out.
	 *
	 * @param string $email Address.
	 *
	 * @return bool
	 */
	public static function is_suppressed( string $email ): bool {
		$hash = self::hash( $email );

		if ( '' === $hash ) {
			return false;
		}

		$list = get_option( self::OPTION, array() );

		return is_array( $list ) && in_array( $hash, $list, true );
	}

	/**
	 * Hash an address for the suppression list.
	 *
	 * @param string $email Address.
	 *
	 * @return string
	 */
	private static function hash( string $email ): string {
		$email = strtolower( trim( $email ) );

		if ( '' === $email ) {
			return '';
		}

		return hash( 'sha256', $email . wp_salt( 'auth' ) );
	}

	/**
	 * Cheap per-IP throttle on the link.
	 *
	 * @return bool
	 */
	private function rate_limit_allows(): bool {
		$key = 'idta_partial_unsub_' . substr( Request_Context::ip_hash(), 0, 32 );

		$hits = (int) get_transient( $key );

		if ( $hits >= 60 ) {
			return false;
		}

		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Print a plain confirmation page and stop.
	 *
	 * @param string $message What to say.
	 */
	private function render( string $message ): void {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Application reminders', 'idta-partial' ),
			array(
				'response'  => 200,
				'back_link' => false,
			)
		);
	}
}

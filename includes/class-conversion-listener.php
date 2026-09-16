<?php
/**
 * Marks a lead converted when its order arrives.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * Watches WooCommerce for the order a lead turned into.
 *
 * This is the only part of the plugin that runs inside somebody else's critical
 * path — specifically inside the REST request that creates the order the
 * customer is waiting on. So every method here is wrapped: a broken table, a
 * missing column, a fatal anywhere in this file must cost the store a missing
 * lead statistic and nothing else. Lead tracking is never worth a failed
 * checkout.
 */
final class Conversion_Listener {

	/**
	 * Order meta carrying the lead token.
	 *
	 * Written by the Cloudflare Worker, which copies it out of the storefront's
	 * order payload alongside the rest of the `_idp_*` meta.
	 */
	public const TOKEN_META = '_idp_lead_token';

	/**
	 * Order meta recording which lead an order was matched to.
	 *
	 * Stamped so an order screen can show the link, and so a second pass over
	 * the same order can tell "already matched" from "no lead".
	 */
	public const PARTIAL_META = '_idta_partial_id';

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
	 * Constructor.
	 *
	 * @param Settings           $settings   Settings.
	 * @param Repository         $repository Lead storage.
	 * @param Reminder_Scheduler $scheduler  Reminder scheduling.
	 */
	public function __construct( Settings $settings, Repository $repository, Reminder_Scheduler $scheduler ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->scheduler  = $scheduler;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		/*
		 * Fires for REST-created orders, which is how every order from the
		 * storefront is created: the Worker posts to wc/v3/orders. 20, so the
		 * order's meta has certainly been written by the time this reads it.
		 */
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 1 );

		/*
		 * The backstop. Covers an order whose meta arrived after creation, an
		 * order taken by hand in the admin, and — in payment_complete mode — the
		 * moment that actually counts as a conversion.
		 */
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 5, 1 );
	}

	/**
	 * An order has been created.
	 *
	 * @param int|mixed $order_id Order ID.
	 */
	public function on_new_order( $order_id ): void {
		$this->guard(
			function () use ( $order_id ): void {
				$order = $this->order( $order_id );

				if ( null === $order ) {
					return;
				}

				$record = $this->match( $order );

				if ( null === $record ) {
					return;
				}

				if ( $this->settings->converts_on_order() ) {
					$this->convert( $record, $order );

					return;
				}

				/*
				 * payment_complete mode: reaching the pay page is worth
				 * recording but is not a conversion, so the lead stays open and
				 * its reminder stays due. A customer who abandons on the payment
				 * page is exactly who that mode exists to catch.
				 */
				$this->repository->attach_order( $record->id, $order->get_id() );

				$this->stamp( $order, $record );
			}
		);
	}

	/**
	 * An order has been paid.
	 *
	 * @param int|mixed $order_id Order ID.
	 */
	public function on_payment_complete( $order_id ): void {
		$this->guard(
			function () use ( $order_id ): void {
				$order = $this->order( $order_id );

				if ( null === $order ) {
					return;
				}

				$record = $this->match( $order );

				if ( null !== $record ) {
					$this->convert( $record, $order );
				}
			}
		);
	}

	/**
	 * Convert a lead and note it on its pending reminder.
	 *
	 * @param Record    $record Lead.
	 * @param \WC_Order $order  Order.
	 */
	private function convert( Record $record, \WC_Order $order ): void {
		if ( ! $this->repository->mark_converted( $record->id, $order->get_id() ) ) {
			// Already converted — both hooks firing for one order, or a retry.
			return;
		}

		$this->stamp( $order, $record );

		/*
		 * The queued reminder is annotated, not cancelled. Cancelling deletes
		 * the action and with it every trace that a reminder was ever due, so
		 * the ordinary successful case would leave nothing to read. Left in
		 * place it wakes up, finds the lead converted, writes that down and
		 * sends nothing.
		 */
		$this->scheduler->note_converted( $record->id, $order->get_id() );
	}

	/**
	 * Find the lead an order belongs to.
	 *
	 * @param \WC_Order $order Order.
	 *
	 * @return Record|null
	 */
	private function match( \WC_Order $order ): ?Record {
		$token = trim( (string) $order->get_meta( self::TOKEN_META, true ) );

		if ( '' !== $token ) {
			$record = $this->repository->find_by_lead_token( $token );

			if ( null !== $record ) {
				return $record;
			}
		}

		/*
		 * No token, or a token we have never seen — an order placed from a
		 * cached copy of the form from before this feature shipped, or one
		 * created by hand. Fall back to the address, narrowly: a 24-hour window
		 * and unconverted leads only, so a returning customer's fresh lead is
		 * not converted by an order that belongs to an older one.
		 */
		return $this->repository->find_recent_open_by_email( (string) $order->get_billing_email(), 24 );
	}

	/**
	 * Record the lead on the order.
	 *
	 * @param \WC_Order $order  Order.
	 * @param Record    $record Lead.
	 */
	private function stamp( \WC_Order $order, Record $record ): void {
		if ( (int) $order->get_meta( self::PARTIAL_META, true ) === $record->id ) {
			return;
		}

		$order->update_meta_data( self::PARTIAL_META, $record->id );

		/*
		 * save_meta_data(), not save(): woocommerce_new_order can fire from
		 * inside a save, and a full save() from there would recurse.
		 */
		$order->save_meta_data();
	}

	/**
	 * Load an order, or nothing.
	 *
	 * @param mixed $order_id Order ID.
	 *
	 * @return \WC_Order|null
	 */
	private function order( $order_id ): ?\WC_Order {
		$order = wc_get_order( absint( $order_id ) );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Run a callback, swallowing anything it throws.
	 *
	 * @param callable $callback Work to do.
	 */
	private function guard( callable $callback ): void {
		if ( ! $this->settings->enabled() || ! Table::exists() ) {
			return;
		}

		try {
			$callback();
		} catch ( \Throwable $e ) {
			/*
			 * Deliberately swallowed. This runs inside order creation; letting
			 * it escape would fail the REST request the storefront is waiting
			 * on and leave the customer staring at "we could not start
			 * checkout" — over a lead statistic.
			 */
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[idta-partial] conversion listener failed: ' . $e->getMessage() );
			}
		}
	}
}

<?php
/**
 * The REST endpoint the Worker posts to.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/idta/v1/partial
 *
 * Reachable only by the Cloudflare Worker, which holds the shared key. The
 * browser never calls this: the Worker is the storefront's only gateway to
 * WordPress, and keeping it that way means the origin allowlist and the
 * WooCommerce credentials stay in one place.
 */
final class REST_Controller {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'idta/v1';

	/**
	 * Header carrying the shared secret.
	 */
	private const AUTH_HEADER = 'X-IDTA-Key';

	/**
	 * Largest request body accepted, in bytes.
	 */
	private const MAX_BODY = 32768;

	/**
	 * Payload keys kept, and how each is cleaned.
	 *
	 * An allowlist rather than "store what arrives": the frontend can be changed
	 * by anyone who can edit a static file, and this column must never quietly
	 * start holding a licence image because a future form added one.
	 *
	 * @var array<string,string>
	 */
	private const PAYLOAD_FIELDS = array(
		// Step 1.
		'has_license'            => 'text',

		// Step 2.
		'license_issued_country' => 'country',
		'destination_country'    => 'country',

		// Step 3 — the applicant.
		'gender'                 => 'text',
		'date_of_birth'          => 'text',
		'dial_code'              => 'text',
		'phone_national'         => 'text',
		'country_of_birth'       => 'country',
		'country_of_residence'   => 'country',
		'driver_license_number'  => 'text',
		'license_categories'     => 'categories',

		// Step 3 — the plan.
		'package'                => 'text',
		'validity_label'         => 'text',
		'plan_price'             => 'text',
		'cart_items'             => 'cart',
		'summary_package'        => 'text',
		'summary_validity'       => 'text',
		'summary_route'          => 'text',
		'summary_total'          => 'text',

		// Provenance.
		'step_reached'           => 'int',
		'referrer'               => 'url',
		'landing_path'           => 'path',
		'utm'                    => 'utm',
	);

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/partial',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorise' ),
			)
		);
	}

	/**
	 * Check the shared secret.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function authorise( \WP_REST_Request $request ) {
		$expected = $this->settings->api_key();

		if ( '' === $expected ) {
			return new \WP_Error(
				'idta_partial_not_configured',
				__( 'Partial application capture has no API key configured.', 'idta-partial' ),
				array( 'status' => 401 )
			);
		}

		$provided = (string) $request->get_header( self::AUTH_HEADER );

		// hash_equals, not ===, so a wrong key cannot be found one byte at a
		// time by measuring how long the comparison took.
		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new \WP_Error(
				'idta_partial_forbidden',
				__( 'Invalid API key.', 'idta-partial' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Store a partial application.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		if ( ! $this->settings->enabled() ) {
			// 200, not an error: the storefront has nothing to do differently,
			// and a failure here must never look like a checkout problem.
			return new \WP_REST_Response(
				array(
					'ok'      => true,
					'enabled' => false,
				),
				200
			);
		}

		if ( ! Table::exists() ) {
			return new \WP_Error(
				'idta_partial_no_table',
				__( 'Partial application storage is unavailable.', 'idta-partial' ),
				array( 'status' => 500 )
			);
		}

		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return new \WP_Error(
				'idta_partial_too_large',
				__( 'Request body is too large.', 'idta-partial' ),
				array( 'status' => 400 )
			);
		}

		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'idta_partial_bad_request',
				__( 'Expected a JSON object.', 'idta-partial' ),
				array( 'status' => 400 )
			);
		}

		$lead_token = sanitize_text_field( (string) ( $body['lead_token'] ?? '' ) );

		if ( ! preg_match( '/^[A-Za-z0-9-]{16,64}$/', $lead_token ) ) {
			return new \WP_Error(
				'idta_partial_bad_token',
				__( 'A valid lead token is required.', 'idta-partial' ),
				array( 'status' => 400 )
			);
		}

		$email = sanitize_email( (string) ( $body['email'] ?? '' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error(
				'idta_partial_bad_email',
				__( 'A valid email address is required.', 'idta-partial' ),
				array( 'status' => 400 )
			);
		}

		if ( ! Request_Context::rate_limit_allows( $email, (int) $this->settings->get( 'rate_limit', 20 ) ) ) {
			return new \WP_Error(
				'idta_partial_rate_limited',
				__( 'Too many submissions. Try again later.', 'idta-partial' ),
				array( 'status' => 429 )
			);
		}

		$payload = $this->clean_payload( $body['payload'] ?? array() );

		$result = $this->repository->upsert(
			array(
				'lead_token'       => $lead_token,
				'email'            => $email,
				'first_name'       => $this->text( $body['first_name'] ?? '', 100 ),
				'last_name'        => $this->text( $body['last_name'] ?? '', 100 ),
				'phone'            => $this->text( $body['phone'] ?? '', 40 ),
				'application_type' => $this->enum( $body['application_type'] ?? '', array( 'print_digital', 'digital_only' ) ),
				'validity_years'   => max( 0, min( 255, (int) ( $body['validity_years'] ?? 0 ) ) ),
				'product_id'       => max( 0, (int) ( $body['product_id'] ?? 0 ) ),
				'currency'         => $this->currency( $body['currency'] ?? '' ),
				'locale'           => $this->text( $body['locale'] ?? '', 10 ),
				'source'           => $this->enum( $body['source'] ?? 'idta', array( 'idta', 'idpa' ), 'idta' ),
				'payload'          => array() === $payload ? '' : (string) wp_json_encode( $payload ),
				'ip_hash'          => Request_Context::ip_hash(),
				'reminder_due_at'  => Repository::now( $this->settings->reminder_delay() ),
			)
		);

		$record = $result['record'];

		if ( ! $record instanceof Record ) {
			return new \WP_Error(
				'idta_partial_storage_failed',
				__( 'The partial application could not be stored.', 'idta-partial' ),
				array( 'status' => 500 )
			);
		}

		/*
		 * Scheduled on insert only. An update means the customer came back to
		 * step 3 and changed something; pushing the reminder out every time
		 * they did would mean a customer who deliberates never gets one.
		 */
		if ( $result['created'] ) {
			$this->scheduler->schedule( $record );
		}

		return new \WP_REST_Response(
			array(
				'ok'              => true,
				'status'          => $record->status,
				'created'         => $result['created'],
				'reminder_due_at' => $record->reminder_due_at,
			),
			200
		);
	}

	/**
	 * Clean the free-form payload against the allowlist.
	 *
	 * @param mixed $raw Submitted payload.
	 *
	 * @return array<string,mixed>
	 */
	private function clean_payload( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( self::PAYLOAD_FIELDS as $key => $type ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}

			$value = $this->clean_value( $raw[ $key ], $type );

			if ( null !== $value && array() !== $value && '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Clean one payload value by its declared type.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $type  Type from PAYLOAD_FIELDS.
	 *
	 * @return mixed
	 */
	private function clean_value( $value, string $type ) {
		switch ( $type ) {
			case 'int':
				return is_scalar( $value ) ? (int) $value : null;

			case 'country':
				if ( ! is_array( $value ) ) {
					return null;
				}

				return array_filter(
					array(
						'code' => strtoupper( $this->text( $value['code'] ?? '', 2 ) ),
						'name' => $this->text( $value['name'] ?? '', 80 ),
					)
				);

			case 'list':
				if ( ! is_array( $value ) ) {
					return null;
				}

				return array_values(
					array_filter(
						array_map(
							fn( $item ) => $this->text( $item, 20 ),
							array_slice( $value, 0, 20 )
						)
					)
				);

			case 'categories':
				if ( ! is_array( $value ) ) {
					return null;
				}

				$categories = array();

				foreach ( array_slice( $value, 0, 20 ) as $item ) {
					// Accepts both shapes: the {value,label} pairs the form now
					// sends, and the bare strings it sent before, so leads
					// already in the table keep rendering.
					if ( is_array( $item ) ) {
						$code = $this->text( $item['value'] ?? '', 20 );

						if ( '' === $code ) {
							continue;
						}

						$categories[] = array(
							'value' => $code,
							'label' => $this->text( $item['label'] ?? '', 80 ),
						);

						continue;
					}

					$code = $this->text( $item, 20 );

					if ( '' !== $code ) {
						$categories[] = array(
							'value' => $code,
							'label' => '',
						);
					}
				}

				return $categories;

			case 'path':
				// A path off our own site, so no host and no query string: the
				// query is where campaign junk and stray identifiers live, and
				// utm carries the parts worth keeping.
				$path = $this->text( $value, 190 );

				return preg_match( '#^/[A-Za-z0-9._~/-]*$#', $path ) ? $path : null;

			case 'cart':
				if ( ! is_array( $value ) ) {
					return null;
				}

				$items = array();

				foreach ( array_slice( $value, 0, 20 ) as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}

					$items[] = array_filter(
						array(
							'id'    => $this->text( $item['id'] ?? '', 20 ),
							'name'  => $this->text( $item['name'] ?? '', 120 ),
							'price' => $this->text( $item['price'] ?? '', 20 ),
							'qty'   => max( 1, (int) ( $item['qty'] ?? 1 ) ),
						)
					);
				}

				return $items;

			case 'url':
				/*
				 * Reduced to host and path. A referrer can carry a full query
				 * string, and a query string on someone else's site can carry
				 * anything at all — including their own customers' identifiers,
				 * which have no business being in this table.
				 */
				$url = esc_url_raw( (string) ( is_scalar( $value ) ? $value : '' ) );

				if ( '' === $url ) {
					return null;
				}

				$parts = wp_parse_url( $url );

				if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
					return null;
				}

				return substr( (string) $parts['host'] . (string) ( $parts['path'] ?? '' ), 0, 190 );

			case 'utm':
				if ( ! is_array( $value ) ) {
					return null;
				}

				return array_filter(
					array(
						'source'   => $this->text( $value['source'] ?? '', 60 ),
						'medium'   => $this->text( $value['medium'] ?? '', 60 ),
						'campaign' => $this->text( $value['campaign'] ?? '', 80 ),
					)
				);

			default:
				return $this->text( $value, 190 );
		}
	}

	/**
	 * Sanitise a scalar to a bounded string.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $length Maximum length.
	 *
	 * @return string
	 */
	private function text( $value, int $length ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return substr( sanitize_text_field( (string) $value ), 0, $length );
	}

	/**
	 * Accept a value only from a known set.
	 *
	 * @param mixed    $value    Raw value.
	 * @param string[] $allowed  Accepted values.
	 * @param string   $fallback Used when the value is not one of them.
	 *
	 * @return string
	 */
	private function enum( $value, array $allowed, string $fallback = '' ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * A three-letter currency code, or nothing.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	private function currency( $value ): string {
		$value = strtoupper( $this->text( $value, 3 ) );

		return preg_match( '/^[A-Z]{3}$/', $value ) ? $value : '';
	}
}

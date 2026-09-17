<?php
/**
 * The reminder sent to a customer who did not finish.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

namespace IDTA\Partial;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce email carrying a link back into the application.
 *
 * A WC_Email rather than a wp_mail() call, so it arrives inside the store's own
 * header and footer, and so its subject, heading and on/off switch live where a
 * shop manager already looks for them: WooCommerce → Settings → Emails. It is
 * also the switch that turns reminders off in a hurry without touching this
 * plugin's settings.
 *
 * Unlike every other WC_Email on the store, its subject is not an order. The
 * base class only ever passes $this->object to the template, so a lead works
 * just as well — the one consequence is that nothing here may call an order
 * method, which is why the template reads a Record and not a WC_Order.
 */
final class Reminder_Email extends \WC_Email {

	/**
	 * The lead being reminded.
	 *
	 * @var Record|null
	 */
	public ?Record $record = null;

	/**
	 * Resume link builder.
	 *
	 * @var Resume_URL
	 */
	private Resume_URL $resume;

	/**
	 * Constructor.
	 *
	 * @param Resume_URL $resume Resume link builder.
	 */
	public function __construct( Resume_URL $resume ) {
		$this->resume = $resume;

		$this->id             = 'idta_partial_reminder';
		$this->customer_email = true;
		$this->title          = __( 'Unfinished application reminder', 'idta-partial' );
		$this->description    = __( 'Sent once, a few minutes after a visitor completes step 3 of the application without placing an order.', 'idta-partial' );

		$this->template_html  = 'emails/partial-reminder.php';
		$this->template_plain = 'emails/plain/partial-reminder.php';
		$this->template_base  = plugin_dir_path( PLUGIN_FILE ) . 'templates/';

		$this->placeholders = array(
			'{first_name}'     => '',
			'{validity_years}' => '',
		);

		parent::__construct();
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject(): string {
		return __( 'Your International Driving Permit is one step away', 'idta-partial' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading(): string {
		return __( 'You are one step away', 'idta-partial' );
	}

	/**
	 * Default wording shown under the message.
	 *
	 * @return string
	 */
	public function get_default_additional_content(): string {
		return __( 'If you have already completed your application, please ignore this message.', 'idta-partial' );
	}

	/**
	 * Send the reminder for one lead.
	 *
	 * @param Record $record Lead.
	 *
	 * @return bool Whether the mail was handed to the mailer.
	 */
	public function trigger( Record $record ): bool {
		$this->setup_locale();

		$this->record    = $record;
		$this->object    = $record;
		$this->recipient = $record->email;

		$this->placeholders['{first_name}']     = $record->greeting_name();
		$this->placeholders['{validity_years}'] = (string) $record->validity_years;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			$this->restore_locale();

			return false;
		}

		$content = $this->get_content();

		/*
		 * Never send an empty message. wc_get_template_html() returns an empty
		 * string when it cannot find the template, and wp_mail() reports success
		 * for a blank email just as readily as for a real one — so a half-copied
		 * plugin folder would otherwise send every abandoning customer a message
		 * with nothing in it, and report that as a success.
		 */
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			$this->restore_locale();

			return false;
		}

		$sent = $this->send(
			$this->get_recipient(),
			$this->get_subject(),
			$content,
			$this->get_headers(),
			$this->get_attachments()
		);

		$this->restore_locale();

		return (bool) $sent;
	}

	/**
	 * HTML body.
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html( $this->template_html, $this->template_args(), '', $this->template_base );
	}

	/**
	 * Plain-text body.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return wc_get_template_html( $this->template_plain, $this->template_args(), '', $this->template_base );
	}

	/**
	 * Values both templates read.
	 *
	 * @return array<string,mixed>
	 */
	private function template_args(): array {
		$record = $this->record instanceof Record ? $this->record : new Record();

		return array(
			'record'             => $record,
			'resume_url'         => $this->resume->for_record( $record ),
			'unsubscribe_url'    => Unsubscribe::url_for( $record ),
			'plan_label'         => $this->plan_label( $record ),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => false,
			'email'              => $this,
		);
	}

	/**
	 * A human description of what the customer was buying.
	 *
	 * @param Record $record Lead.
	 *
	 * @return string
	 */
	private function plan_label( Record $record ): string {
		return 'digital_only' === $record->application_type
			? __( 'Digital copy only', 'idta-partial' )
			: __( 'Printed permit and digital copy', 'idta-partial' );
	}
}

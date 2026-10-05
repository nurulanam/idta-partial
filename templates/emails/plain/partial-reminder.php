<?php
/**
 * Unfinished-application reminder, plain text.
 *
 * @var \IDTA\Partial\Record $record             The lead.
 * @var string               $resume_url         Link back into the application.
 * @var string               $unsubscribe_url    Opt-out link.
 * @var string               $plan_label         What they were buying.
 * @var string               $body_text          Message above the link, from settings.
 * @var string               $button_text        Link label, from settings.
 * @var string               $footer_text        Message under the link, from settings.
 * @var string               $email_heading      Heading.
 * @var string               $additional_content Wording from the email's own settings.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

echo esc_html( $body_text ) . "\n\n";

if ( '' !== $resume_url ) {
	echo esc_html( $button_text ) . ":\n" . esc_url_raw( $resume_url ) . "\n\n";
}

echo esc_html( $footer_text ) . "\n\n";

if ( '' !== trim( (string) $additional_content ) ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

if ( '' !== $unsubscribe_url ) {
	esc_html_e( 'To stop receiving these reminders, open:', 'idta-partial' );

	echo "\n" . esc_url_raw( $unsubscribe_url ) . "\n\n";
}

echo esc_html( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

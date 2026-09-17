<?php
/**
 * Unfinished-application reminder, plain text.
 *
 * @var \IDTA\Partial\Record $record             The lead.
 * @var string               $resume_url         Link back into the application.
 * @var string               $unsubscribe_url    Opt-out link.
 * @var string               $plan_label         What they were buying.
 * @var string               $email_heading      Heading.
 * @var string               $additional_content Wording from the email's own settings.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

printf(
	/* translators: %s: customer's first name. */
	esc_html__( 'Hi %s,', 'idta-partial' ),
	esc_html( $record->greeting_name() )
);

echo "\n\n";

esc_html_e( 'Your International Driving Permit is almost ready - you stopped just before the last step.', 'idta-partial' );

echo "\n\n";

esc_html_e( 'The good news: everything you typed is still saved. One click brings it all back, filled in and waiting, so finishing takes about a minute - and your permit is issued digitally as soon as your application is approved.', 'idta-partial' );

echo "\n\n";

esc_html_e( 'Don\'t let an unfinished form hold up your trip.', 'idta-partial' );

echo "\n\n";

if ( '' !== $resume_url ) {
	esc_html_e( 'Continue your application:', 'idta-partial' );

	echo "\n" . esc_url_raw( $resume_url ) . "\n\n";
}

printf(
	/* translators: %s: what the customer was buying. */
	esc_html__( 'Application: %s', 'idta-partial' ),
	esc_html( $plan_label )
);

echo "\n";

if ( $record->validity_years > 0 ) {
	printf(
		/* translators: %d: number of years. */
		esc_html( _n( 'Validity: %d year', 'Validity: %d years', $record->validity_years, 'idta-partial' ) ),
		(int) $record->validity_years
	);

	echo "\n";
}

echo "\n";

esc_html_e( 'For your security we do not store the photos you upload. When you continue, please attach your portrait, both sides of your licence and your signature again.', 'idta-partial' );

echo "\n\n";

if ( '' !== trim( (string) $additional_content ) ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

if ( '' !== $unsubscribe_url ) {
	esc_html_e( 'To stop receiving these reminders, open:', 'idta-partial' );

	echo "\n" . esc_url_raw( $unsubscribe_url ) . "\n\n";
}

echo esc_html( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

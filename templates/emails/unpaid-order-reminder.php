<?php
/**
 * Unpaid-order reminder, HTML.
 *
 * @var \IDTA\Partial\Record $record             The lead.
 * @var string               $payment_url        Pay-for-order URL.
 * @var string               $body_text          Message above the button.
 * @var string               $button_text        Button label.
 * @var string               $footer_text        Message under the button.
 * @var string               $unsubscribe_url    Opt-out link.
 * @var string               $email_heading      Heading.
 * @var string               $additional_content Wording from the email's own settings.
 * @var \WC_Email            $email              Email instance.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php echo wp_kses_post( wpautop( esc_html( $body_text ) ) ); ?>

<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0;">
	<tr>
		<td style="border-radius: 4px; background: #1434cb;">
			<?php
			printf(
				'<a href="%1$s" style="%2$s">%3$s</a>',
				esc_url( $payment_url ),
				'display: inline-block; padding: 12px 24px; color: #ffffff; font-weight: bold; text-decoration: none; font-size: 16px;',
				esc_html( $button_text )
			);
			?>
		</td>
	</tr>
</table>

<?php echo wp_kses_post( wpautop( esc_html( $footer_text ) ) ); ?>

<?php
if ( '' !== trim( (string) $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
?>

<?php if ( '' !== $unsubscribe_url ) : ?>
	<p style="font-size: 12px; color: #718096;">
		<?php
		printf(
			/* translators: %s: unsubscribe link. */
			esc_html__( 'Do not want these reminders? %s', 'idta-partial' ),
			sprintf(
				'<a href="%1$s" style="color: #718096;">%2$s</a>',
				esc_url( $unsubscribe_url ),
				esc_html__( 'Unsubscribe', 'idta-partial' )
			)
		);
		?>
	</p>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
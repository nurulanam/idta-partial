<?php
/**
 * Unfinished-application reminder, HTML.
 *
 * Override by copying to `woocommerce/emails/partial-reminder.php` in your theme.
 *
 * @var \IDTA\Partial\Record $record             The lead.
 * @var string               $resume_url         Link back into the application.
 * @var string               $unsubscribe_url    Opt-out link.
 * @var string               $plan_label         What they were buying.
 * @var string               $email_heading      Heading.
 * @var string               $additional_content Wording from the email's own settings.
 * @var \WC_Email            $email              Email instance.
 *
 * Laid out with tables and inline styles, not flexbox and a stylesheet: Outlook
 * renders neither, and several clients strip a <style> block entirely.
 *
 * @package IDTA\Partial
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	printf(
		/* translators: %s: customer's first name. */
		esc_html__( 'Hi %s,', 'idta-partial' ),
		esc_html( $record->greeting_name() )
	);
	?>
</p>

<p>
	<?php esc_html_e( 'Your International Driving Permit is almost ready — you stopped just before the last step.', 'idta-partial' ); ?>
</p>

<p>
	<?php esc_html_e( 'The good news: everything you typed is still saved. One click below brings it all back, filled in and waiting, so finishing takes about a minute — and your permit is issued digitally as soon as your application is approved.', 'idta-partial' ); ?>
</p>

<p>
	<strong><?php esc_html_e( 'Don\'t let an unfinished form hold up your trip.', 'idta-partial' ); ?></strong>
</p>

<?php if ( '' !== $resume_url ) : ?>
	<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0;">
		<tr>
			<td style="border-radius: 4px; background: #1434cb;">
				<?php
				printf(
					'<a href="%1$s" style="%2$s">%3$s</a>',
					esc_url( $resume_url ),
					'display: inline-block; padding: 12px 24px; color: #ffffff; font-weight: bold; text-decoration: none; font-size: 16px;',
					esc_html__( 'Continue my application', 'idta-partial' )
				);
				?>
			</td>
		</tr>
	</table>
<?php endif; ?>

<table border="0" cellpadding="6" cellspacing="0" role="presentation" style="margin: 24px 0; border-collapse: collapse;">
	<tr>
		<th align="left" style="border-bottom: 1px solid #e2e8f0;"><?php esc_html_e( 'Application', 'idta-partial' ); ?></th>
		<td style="border-bottom: 1px solid #e2e8f0;"><?php echo esc_html( $plan_label ); ?></td>
	</tr>
	<?php if ( $record->validity_years > 0 ) : ?>
		<tr>
			<th align="left" style="border-bottom: 1px solid #e2e8f0;"><?php esc_html_e( 'Validity', 'idta-partial' ); ?></th>
			<td style="border-bottom: 1px solid #e2e8f0;">
				<?php
				printf(
					/* translators: %d: number of years. */
					esc_html( _n( '%d year', '%d years', $record->validity_years, 'idta-partial' ) ),
					(int) $record->validity_years
				);
				?>
			</td>
		</tr>
	<?php endif; ?>
</table>

<p style="padding: 12px; background: #fffaf0; border-left: 4px solid #dd6b20;">
	<?php esc_html_e( 'For your security we do not store the photos you upload. When you continue, please attach your portrait, both sides of your licence and your signature again.', 'idta-partial' ); ?>
</p>

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

<?php
do_action( 'woocommerce_email_footer', $email );

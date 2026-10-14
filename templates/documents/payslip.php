<?php
/**
 * Payslip (M15 T7a): one employee, one pay run — earnings, deductions,
 * statutory contributions with their base and rate, net pay, the hotel's
 * contributions, year to date. Printed from the browser; Pro renders the
 * same HTML to PDF. Shares the free plugin's document style.
 *
 * Override from a theme at `radius-hotel-booking/documents/payslip.php`.
 *
 * @var array $doc Payslip data (PayslipService::data()): `number, status, voided, schedule, period_start,
 *                 period_end, pay_date, employee, breakdown, payment, year_to_date, hotel, logo_url, footer, pdf?`.
 *
 * @package RadiusTheme\RadiusHotelBookingPro
 */

use RadiusTheme\RadiusHotelBooking\Support\Dates;
use RadiusTheme\RadiusHotelBooking\Support\Money;

defined( 'ABSPATH' ) || exit;

$rtbp_pro_hotel = (array) ( $doc['hotel'] ?? array() );
$rtbp_pro_emp   = (array) ( $doc['employee'] ?? array() );
$rtbp_pro_b     = (array) ( $doc['breakdown'] ?? array() );
$rtbp_pro_stat  = (array) ( $rtbp_pro_b['statutory']['lines'] ?? array() );
$rtbp_pro_ytd   = (array) ( $doc['year_to_date'] ?? array() );
$rtbp_pro_money = static fn( $value ) => Money::format( (float) $value );
$rtbp_pro_date  = static fn( $ymd ) => $ymd ? Dates::format( Dates::local( $ymd . ' 00:00:00' ), 'date' ) : '';
// An unknown rate (a payslip imported from the old system) prints nothing.
$rtbp_pro_rate  = static fn( $rate ) => null === $rate || '' === (string) $rate ? '' : rtrim( rtrim( number_format( (float) $rate, 3, '.', '' ), '0' ), '.' ) . ' %';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title>
	<?php
	/* translators: %s: payslip number. */
	echo esc_html( sprintf( __( 'Payslip %s', 'radius-hotel-booking-pro' ), $doc['number'] ) );
	?>
	</title>
	<?php rtbp_get_template( 'documents/document-style.php' ); ?>
</head>
<body>
<?php if ( empty( $doc['pdf'] ) ) : ?>
<div class="actions"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'radius-hotel-booking-pro' ); ?></button></div>
<?php endif; ?>
<div class="sheet">
	<?php if ( ! empty( $doc['voided'] ) ) : ?>
		<div class="stamp"><?php esc_html_e( 'Voided', 'radius-hotel-booking-pro' ); ?></div>
	<?php endif; ?>

	<table>
		<tr>
			<td style="width: 55%;">
				<?php if ( ! empty( $doc['logo_url'] ) ) : ?>
					<img class="logo" src="<?php echo esc_url( $doc['logo_url'] ); ?>" alt="" /><br />
				<?php endif; ?>
				<p class="hotel-name"><?php echo esc_html( $rtbp_pro_hotel['name'] ?? '' ); ?></p>
				<?php if ( ! empty( $rtbp_pro_hotel['address'] ) ) : ?>
					<div class="muted" style="white-space: pre-line;"><?php echo esc_html( $rtbp_pro_hotel['address'] ); ?></div>
				<?php endif; ?>
				<div class="muted">
					<?php
					echo esc_html(
						implode(
							' · ',
							array_filter(
								array(
									! empty( $rtbp_pro_hotel['cnps_number'] ) ? sprintf( /* translators: %s: social security number. */ __( 'Social security %s', 'radius-hotel-booking-pro' ), $rtbp_pro_hotel['cnps_number'] ) : '',
									! empty( $rtbp_pro_hotel['tax_number'] ) ? sprintf( /* translators: %s: tax number. */ __( 'Tax no. %s', 'radius-hotel-booking-pro' ), $rtbp_pro_hotel['tax_number'] ) : '',
								)
							)
						)
					);
					?>
				</div>
			</td>
			<td>
				<p class="doc-title"><?php esc_html_e( 'Payslip', 'radius-hotel-booking-pro' ); ?></p>
				<table class="meta">
					<tr><td class="label"><?php esc_html_e( 'Number', 'radius-hotel-booking-pro' ); ?></td><td class="value"><?php echo esc_html( $doc['number'] ); ?></td></tr>
					<tr><td class="label"><?php esc_html_e( 'Pay period', 'radius-hotel-booking-pro' ); ?></td><td class="value"><?php echo esc_html( $rtbp_pro_date( $doc['period_start'] ) . ' – ' . $rtbp_pro_date( $doc['period_end'] ) ); ?></td></tr>
					<tr><td class="label"><?php esc_html_e( 'Pay day', 'radius-hotel-booking-pro' ); ?></td><td class="value"><?php echo esc_html( $rtbp_pro_date( $doc['pay_date'] ) ); ?></td></tr>
				</table>
			</td>
		</tr>
	</table>

	<div class="box">
		<p class="box-title"><?php esc_html_e( 'Employee', 'radius-hotel-booking-pro' ); ?></p>
		<strong><?php echo esc_html( $rtbp_pro_emp['name'] ?? '' ); ?></strong>
		<div class="muted">
			<?php
			echo esc_html(
				implode(
					' · ',
					array_filter(
						array(
							$rtbp_pro_emp['number'] ?? '',
							$rtbp_pro_emp['job_title'] ?? '',
							$rtbp_pro_emp['department'] ?? '',
							! empty( $rtbp_pro_emp['cnps_number'] ) ? sprintf( /* translators: %s: social security number. */ __( 'Social security %s', 'radius-hotel-booking-pro' ), $rtbp_pro_emp['cnps_number'] ) : '',
							! empty( $rtbp_pro_emp['tax_number'] ) ? sprintf( /* translators: %s: tax number. */ __( 'Tax no. %s', 'radius-hotel-booking-pro' ), $rtbp_pro_emp['tax_number'] ) : '',
						)
					)
				)
			);
			?>
		</div>
	</div>

	<table class="lines">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Earnings', 'radius-hotel-booking-pro' ); ?></th>
				<th class="num"><?php esc_html_e( 'Quantity', 'radius-hotel-booking-pro' ); ?></th>
				<th class="num"><?php esc_html_e( 'Amount', 'radius-hotel-booking-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Base pay', 'radius-hotel-booking-pro' ); ?></td>
				<td class="num"><?php echo (float) ( $rtbp_pro_b['hours'] ?? 0 ) > 0 ? esc_html( sprintf( /* translators: %s: hours. */ __( '%s h', 'radius-hotel-booking-pro' ), number_format_i18n( (float) $rtbp_pro_b['hours'], 2 ) ) ) : ''; ?></td>
				<td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_b['base'] ?? 0 ) ); ?></td>
			</tr>
			<?php foreach ( (array) ( $rtbp_pro_b['earnings'] ?? array() ) as $rtbp_pro_e ) : ?>
				<tr>
					<td><?php echo esc_html( $rtbp_pro_e['label'] ); ?></td>
					<td class="num"><?php echo null !== $rtbp_pro_e['quantity'] ? esc_html( sprintf( /* translators: %s: hours. */ __( '%s h', 'radius-hotel-booking-pro' ), number_format_i18n( (float) $rtbp_pro_e['quantity'], 2 ) ) ) : ''; ?></td>
					<td class="num"><?php echo esc_html( ( 'deduct' === $rtbp_pro_e['effect'] ? '− ' : '' ) . $rtbp_pro_money( $rtbp_pro_e['amount'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! empty( $rtbp_pro_b['vacation'] ) && 'pay' === $rtbp_pro_b['vacation']['method'] ) : ?>
				<tr>
					<td><?php esc_html_e( 'Vacation pay', 'radius-hotel-booking-pro' ); ?></td>
					<td class="num"><?php echo esc_html( $rtbp_pro_rate( $rtbp_pro_b['vacation']['percent'] ) ); ?></td>
					<td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_b['vacation']['amount'] ) ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
	<table class="totals">
		<tr class="grand"><td><?php esc_html_e( 'Gross pay', 'radius-hotel-booking-pro' ); ?></td><td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_b['gross'] ?? 0 ) ); ?></td></tr>
	</table>

	<table class="lines">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Deductions', 'radius-hotel-booking-pro' ); ?></th>
				<th class="num"><?php esc_html_e( 'Base', 'radius-hotel-booking-pro' ); ?></th>
				<th class="num"><?php esc_html_e( 'Rate', 'radius-hotel-booking-pro' ); ?></th>
				<th class="num"><?php esc_html_e( 'Amount', 'radius-hotel-booking-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( (array) ( $rtbp_pro_b['deductions'] ?? array() ) as $rtbp_pro_d ) : ?>
				<tr><td><?php echo esc_html( $rtbp_pro_d['label'] ); ?></td><td class="num"></td><td class="num"></td><td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_d['amount'] ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php foreach ( $rtbp_pro_stat as $rtbp_pro_l ) : ?>
				<?php
				if ( 'employee' !== $rtbp_pro_l['side'] ) {
					continue;
				}
				?>
				<tr>
					<td><?php echo esc_html( $rtbp_pro_l['label'] . ' · ' . $rtbp_pro_l['authority'] ); ?></td>
					<td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_l['base'] ) ); ?></td>
					<td class="num"><?php echo esc_html( $rtbp_pro_rate( $rtbp_pro_l['rate'] ) ); ?></td>
					<td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_l['amount'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php foreach ( (array) ( $rtbp_pro_b['garnishments'] ?? array() ) as $rtbp_pro_g ) : ?>
				<tr><td><?php echo esc_html( $rtbp_pro_g['label'] ); ?></td><td class="num"></td><td class="num"></td><td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_g['amount'] ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php foreach ( (array) ( $rtbp_pro_b['custom'] ?? array() ) as $rtbp_pro_c ) : ?>
				<tr>
					<td><?php echo esc_html( $rtbp_pro_c['name'] . ( (float) $rtbp_pro_c['tax'] > 0 ? ' — ' . sprintf( /* translators: %s: amount. */ __( 'taxed %s', 'radius-hotel-booking-pro' ), $rtbp_pro_money( $rtbp_pro_c['tax'] ) ) : '' ) ); ?></td>
					<td class="num"></td><td class="num"></td>
					<td class="num"><?php echo esc_html( ( 'deduction' === $rtbp_pro_c['type'] ? '' : '+ ' ) . $rtbp_pro_money( abs( (float) $rtbp_pro_c['net'] ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<table class="totals">
		<tr class="grand"><td><?php esc_html_e( 'Net pay', 'radius-hotel-booking-pro' ); ?></td><td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_b['net'] ?? 0 ) ); ?></td></tr>
		<?php if ( ! empty( $doc['payment']['method'] ) ) : ?>
			<tr><td class="muted"><?php esc_html_e( 'Paid by', 'radius-hotel-booking-pro' ); ?></td><td class="num muted"><?php echo esc_html( implode( ' · ', array_filter( array( $doc['payment']['method'], $doc['payment']['reference'] ?? '' ) ) ) ); ?></td></tr>
		<?php endif; ?>
	</table>

	<table style="margin-top: 24px;">
		<tr>
			<td style="width: 50%; padding-right: 12px;">
				<p class="box-title"><?php esc_html_e( 'Paid by the hotel', 'radius-hotel-booking-pro' ); ?></p>
				<table class="meta">
					<?php foreach ( $rtbp_pro_stat as $rtbp_pro_l ) : ?>
						<?php
						if ( 'employer' !== $rtbp_pro_l['side'] ) {
							continue;
						}
						?>
						<tr><td class="muted"><?php echo esc_html( implode( ' · ', array_filter( array( $rtbp_pro_l['label'], $rtbp_pro_rate( $rtbp_pro_l['rate'] ) ) ) ) ); ?></td><td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_l['amount'] ) ); ?></td></tr>
					<?php endforeach; ?>
				</table>
			</td>
			<td>
				<p class="box-title"><?php esc_html_e( 'Year to date', 'radius-hotel-booking-pro' ); ?></p>
				<table class="meta">
					<tr><td class="muted"><?php esc_html_e( 'Gross pay', 'radius-hotel-booking-pro' ); ?></td><td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_ytd['gross'] ?? 0 ) ); ?></td></tr>
					<tr><td class="muted"><?php esc_html_e( 'Taxes and contributions', 'radius-hotel-booking-pro' ); ?></td><td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_ytd['employee_tax'] ?? 0 ) ); ?></td></tr>
					<tr><td class="muted"><?php esc_html_e( 'Net pay', 'radius-hotel-booking-pro' ); ?></td><td class="num"><?php echo esc_html( $rtbp_pro_money( $rtbp_pro_ytd['net'] ?? 0 ) ); ?></td></tr>
				</table>
			</td>
		</tr>
	</table>

	<?php if ( ! empty( $doc['footer'] ) ) : ?>
		<div class="footer"><?php echo esc_html( $doc['footer'] ); ?></div>
	<?php endif; ?>
</div>
</body>
</html>

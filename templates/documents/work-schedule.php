<?php
/**
 * Work schedule (R2-T9): every active employee against the days of a
 * period, with their shift hours, days off and approved time off. Printed
 * from the browser; Pro renders the same HTML to PDF. Shares the free
 * plugin's document style, on a landscape page.
 *
 * Override from a theme at `radius-hotel-booking/documents/work-schedule.php`.
 *
 * @var array $doc Schedule data (WorkScheduleService::data()): `number, from, to, days, rows, hotel, logo_url, pdf?`.
 *
 * @package RadiusTheme\RadiusHotelBookingPro
 */

defined( 'ABSPATH' ) || exit;

$rtbp_pro_hotel = (array) ( $doc['hotel'] ?? array() );
$rtbp_pro_days  = (array) ( $doc['days'] ?? array() );
$rtbp_pro_rows  = (array) ( $doc['rows'] ?? array() );
$rtbp_pro_brand = '' !== (string) ( $rtbp_pro_hotel['trade_name'] ?? '' ) ? (string) $rtbp_pro_hotel['trade_name'] : (string) ( $rtbp_pro_hotel['name'] ?? '' );
$rtbp_pro_day   = static fn( $ymd, $format ) => wp_date( $format, ( new DateTimeImmutable( $ymd . ' 12:00:00', wp_timezone() ) )->getTimestamp() );
// A month of columns needs smaller print than a week.
$rtbp_pro_dense = count( $rtbp_pro_days ) > 10;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php esc_html_e( 'Work schedule', 'radius-hotel-booking-pro' ); ?></title>
	<?php rtbp_get_template( 'documents/document-style.php' ); ?>
	<style>
		@page { size: A4 landscape; margin: 10mm; }
		.sheet { max-width: 1400px; }
		.schedule { margin-top: 16px; table-layout: fixed; }
		.schedule th, .schedule td { border: 1px solid #d1d5db; padding: <?php echo $rtbp_pro_dense ? '3px 1px' : '4px 3px'; ?>; text-align: center; font-size: <?php echo $rtbp_pro_dense ? '7px' : '11px'; ?>; line-height: 1.3; overflow: hidden; }
		.schedule th { background: #f3f4f6; color: #374151; font-weight: bold; }
		.schedule th.who, .schedule td.who { text-align: left; width: <?php echo $rtbp_pro_dense ? '18%' : '22%'; ?>; }
		.schedule td.who .meta { color: #6b7280; font-size: <?php echo $rtbp_pro_dense ? '7px' : '10px'; ?>; }
		.schedule th.weekend { background: #e5e7eb; }
		.schedule td.off { background: #f3f4f6; color: #6b7280; }
		.schedule td.time_off { background: #fef3c7; color: #92400e; font-weight: bold; }
		.schedule td.none { color: #9ca3af; }
		.legend { margin-top: 10px; font-size: 10px; color: #6b7280; }
		.legend span { display: inline-block; margin-right: 14px; }
		.swatch { display: inline-block; width: 10px; height: 10px; border: 1px solid #d1d5db; vertical-align: middle; margin-right: 4px; }
	</style>
</head>
<body>
<?php if ( empty( $doc['pdf'] ) ) : ?>
<div class="actions"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'radius-hotel-booking-pro' ); ?></button></div>
<?php endif; ?>
<div class="sheet">
	<table>
		<tr>
			<td style="width: 55%;">
				<table class="brand">
					<tr>
						<?php if ( ! empty( $doc['logo_url'] ) ) : ?>
							<td class="brand-logo"><img class="logo" src="<?php echo esc_url( $doc['logo_url'] ); ?>" alt="" /></td>
						<?php endif; ?>
						<td><p class="hotel-name"><?php echo esc_html( $rtbp_pro_brand ); ?></p></td>
					</tr>
				</table>
			</td>
			<td>
				<p class="doc-title"><?php esc_html_e( 'Work schedule', 'radius-hotel-booking-pro' ); ?></p>
				<p style="text-align: right; margin: 4px 0 0;" class="muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: first day, 2: last day. */
							__( '%1$s – %2$s', 'radius-hotel-booking-pro' ),
							$rtbp_pro_day( $doc['from'], get_option( 'date_format' ) ),
							$rtbp_pro_day( $doc['to'], get_option( 'date_format' ) )
						)
					);
					?>
				</p>
			</td>
		</tr>
	</table>

	<table class="schedule">
		<thead>
			<tr>
				<th class="who"><?php esc_html_e( 'Employee', 'radius-hotel-booking-pro' ); ?></th>
				<?php foreach ( $rtbp_pro_days as $rtbp_pro_d ) : ?>
					<th class="<?php echo $rtbp_pro_d['weekday'] >= 6 ? 'weekend' : ''; ?>">
						<?php echo esc_html( $rtbp_pro_day( $rtbp_pro_d['date'], 'D' ) ); ?><br />
						<?php echo esc_html( $rtbp_pro_day( $rtbp_pro_d['date'], $rtbp_pro_dense ? 'j' : 'j M' ) ); ?>
					</th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $rtbp_pro_rows ) : ?>
				<tr><td colspan="<?php echo (int) count( $rtbp_pro_days ) + 1; ?>"><?php esc_html_e( 'No active employees.', 'radius-hotel-booking-pro' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rtbp_pro_rows as $rtbp_pro_r ) : ?>
				<tr>
					<td class="who">
						<strong><?php echo esc_html( $rtbp_pro_r['name'] ); ?></strong>
						<div class="meta">
							<?php
							echo esc_html(
								implode(
									' · ',
									array_filter(
										array(
											$rtbp_pro_r['job_title'],
											'' !== $rtbp_pro_r['shift'] ? $rtbp_pro_r['shift'] : __( 'No work shift', 'radius-hotel-booking-pro' ),
										)
									)
								)
							);
							?>
						</div>
					</td>
					<?php foreach ( $rtbp_pro_r['cells'] as $rtbp_pro_c ) : ?>
						<td class="<?php echo esc_attr( $rtbp_pro_c['kind'] ); ?>">
							<?php if ( 'work' === $rtbp_pro_c['kind'] && isset( $rtbp_pro_c['start'] ) ) : ?>
								<?php // Start and end on their own lines: the PDF engine does not wrap at the dash. ?>
								<?php echo esc_html( $rtbp_pro_c['start'] ); ?><br /><?php echo esc_html( $rtbp_pro_c['end'] ); ?>
							<?php else : ?>
								<?php echo esc_html( 'none' === $rtbp_pro_c['kind'] ? '—' : $rtbp_pro_c['text'] ); ?>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="legend">
		<span><i class="swatch" style="background: #fff;"></i><?php esc_html_e( 'Working hours', 'radius-hotel-booking-pro' ); ?></span>
		<span><i class="swatch" style="background: #f3f4f6;"></i><?php esc_html_e( 'Day off', 'radius-hotel-booking-pro' ); ?></span>
		<span><i class="swatch" style="background: #fef3c7;"></i><?php esc_html_e( 'Approved time off', 'radius-hotel-booking-pro' ); ?></span>
		<span>— <?php esc_html_e( 'No work shift assigned (Pay tab)', 'radius-hotel-booking-pro' ); ?></span>
	</p>
</div>
</body>
</html>

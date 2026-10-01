<?php
/**
 * The automatic release of unpaid bookings (M05 T8, 5.14).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payments
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payments;

use DateInterval;
use DateTimeImmutable;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Repositories\BookingRepository;
use RadiusTheme\RadiusHotelBooking\Services\Booking\BookingStatusService;
use RadiusTheme\RadiusHotelBooking\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Every 15 minutes: release each booking whose payment deadline passed more
 * than the grace period ago and is still unpaid or partly paid — the free
 * `BookingStatusService::release()`, the same move as the desk's *Release*
 * (rooms freed, guest e-mailed, invoice cancelled, logged
 * `bookings.release_overdue`). Runs with nobody signed in, so the log shows
 * the system. Bookings on hold, and stays that have already begun (the guest
 * may be at the desk), are skipped: the desk decides.
 *
 * Idempotent: every condition is checked again under the booking's lock, so
 * a booking paid, put on hold or already released meanwhile is left alone
 * (409, skipped), and the free Scheduler never runs two jobs at once.
 */
final class AutoReleaseJob {

	/**
	 * Bookings read per page (the oldest deadlines first).
	 */
	const BATCH = 100;

	/**
	 * Pages read per run (up to 1 000 overdue bookings).
	 */
	const PAGES = 10;

	/**
	 * Run once.
	 *
	 * @param DateTimeImmutable|null $now Now (checks).
	 * @return array{released: int[], skipped: array<int, string>} Booking ids.
	 */
	public static function run( ?DateTimeImmutable $now = null ): array {
		$out = array(
			'released' => array(),
			'skipped'  => array(),
		);
		if ( ! method_exists( BookingRepository::class, 'overdue' ) || ! method_exists( BookingStatusService::class, 'release' ) ) {
			return $out;
		}

		$now   = $now ?? Dates::now();
		$grace = max( 0, (int) AutoReleaseSettings::get( 'graceHours', 0 ) );
		$as_of = $now->sub( new DateInterval( 'PT' . $grace . 'H' ) );

		// Read the candidates first, page by page, so bookings that are always
		// skipped (on hold, stay begun) cannot hide newer ones (critical review).
		$candidates = array();
		$repository = new BookingRepository();
		for ( $page = 1; $page <= self::PAGES; $page++ ) {
			$rows = $repository->overdue( Dates::to_gmt_db( $as_of ), $page, self::BATCH )['rows'];
			foreach ( $rows as $row ) {
				$booking = $row['booking'];
				if ( $booking->on_hold ) {
					$out['skipped'][ (int) $booking->id ] = 'on_hold';
				} elseif ( $row['first_start'] && strtotime( (string) $row['first_start'] . ' UTC' ) <= $now->getTimestamp() ) {
					// The stay has begun: the guest may be at the desk. Never released
					// automatically; the desk decides (critical review).
					$out['skipped'][ (int) $booking->id ] = 'started';
				} else {
					$candidates[] = $booking;
				}
			}
			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}

		// The system acts, not whoever happens to trigger WP-Cron.
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$service = new BookingStatusService();
			foreach ( $candidates as $booking ) {
				try {
					$service->release(
						(int) $booking->id,
						__( 'Released automatically: not paid by the deadline.', 'radius-hotel-booking-pro' ),
						array(
							'as_of'       => $as_of,
							'unless_held' => true,
						)
					);
					$out['released'][] = (int) $booking->id;
				} catch ( DomainException $e ) {
					// Paid, put on hold or changed meanwhile: leave it.
					$out['skipped'][ (int) $booking->id ] = $e->getErrorCode();
				} catch ( \Throwable $e ) {
					$out['skipped'][ (int) $booking->id ] = 'error';
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- one booking failed; the others still run.
					error_log( '[radius-hotel-booking-pro] Automatic release of booking ' . (int) $booking->id . ' failed: ' . $e->getMessage() );
				}
			}
		} finally {
			wp_set_current_user( $user );
		}
		return $out;
	}
}

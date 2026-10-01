<?php
/**
 * Scheduled exports (M11 T4, 11.8).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Export
 */

namespace RadiusTheme\RadiusHotelBookingPro\Export;

use DateTimeImmutable;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Services\Export\ExportService;
use RadiusTheme\RadiusHotelBooking\Services\Export\ExportWriter;
use RadiusTheme\RadiusHotelBooking\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Export files made on their own, weekly or monthly, into the file library.
 *
 * A schedule: `{ id, kind, frequency weekly|monthly, weekday 1–7 (Monday = 1)
 * or day 1–28, time HH:MM, mode, format, enabled, next_run, last_run,
 * last_status, last_error, last_export }` — stored in the option
 * `rtbp_pro_export_schedules` (at most 10).
 *
 * **What a run exports:** a weekly run, the previous full week (Monday to
 * Sunday); a monthly run, the previous calendar month; the guest list is
 * always whole. Times are the hotel's (site time zone).
 *
 * The 15-minute job (`rtbp_pro_scheduled_exports`) runs every schedule whose
 * `next_run` has passed — signed out (the log's actor is the system, the
 * library says *Scheduled*) — then moves `next_run` to the next occurrence. A
 * failure is recorded on the schedule and the next run still comes. Changes
 * to schedules are logged (`exports.schedule_*`).
 */
class ScheduleService {

	/**
	 * Option holding the schedules.
	 */
	public const OPTION = 'rtbp_pro_export_schedules';

	/**
	 * Option held while due schedules run.
	 */
	public const LOCK = 'rtbp_pro_export_schedules_lock';

	/**
	 * Seconds after which a lock is from a run that died.
	 */
	public const LOCK_TTL = 1800;

	/**
	 * Most schedules.
	 */
	public const MAX = 10;

	/**
	 * Every schedule, with its next run.
	 *
	 * @return array[]
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$list   = is_array( $stored['items'] ?? null ) ? $stored['items'] : array();
		return array_values( array_map( array( $this, 'shape' ), $list ) );
	}

	/**
	 * Add a schedule.
	 *
	 * @param array                  $input Fields.
	 * @param DateTimeImmutable|null $now   Now.
	 * @return array The schedule.
	 * @throws DomainException 422.
	 */
	public function create( array $input, ?DateTimeImmutable $now = null ): array {
		$stored = $this->stored();
		if ( count( $stored['items'] ) >= self::MAX ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::invalid( array( 'kind' => sprintf( /* translators: %d: most schedules. */ __( 'At most %d scheduled exports.', 'radius-hotel-booking-pro' ), self::MAX ) ) );
		}
		$schedule             = $this->validate( $input );
		$schedule['id']       = ++$stored['next_id'];
		$schedule['next_run'] = $this->nextRun( $schedule, $now ?? Dates::now() );
		$schedule            += array(
			'last_run'    => null,
			'last_status' => '',
			'last_error'  => '',
			'last_export' => 0,
		);
		$stored['items'][ $schedule['id'] ] = $schedule;
		$this->save( $stored );
		/* translators: %s: the schedule in words. */
		$this->log( 'exports.schedule_create', $schedule, __( 'Added a scheduled export: %s', 'radius-hotel-booking-pro' ) );
		return $this->shape( $schedule );
	}

	/**
	 * Change a schedule.
	 *
	 * @param int                    $id    Schedule id.
	 * @param array                  $input Fields.
	 * @param DateTimeImmutable|null $now   Now.
	 * @return array The schedule.
	 * @throws DomainException 404 / 422.
	 */
	public function update( int $id, array $input, ?DateTimeImmutable $now = null ): array {
		$stored = $this->stored();
		$before = $this->find( $stored, $id );
		$after  = array_merge( $before, $this->validate( array_merge( $before, $input ) ) );
		// A new rhythm restarts from now.
		$after['next_run'] = $this->nextRun( $after, $now ?? Dates::now() );

		$stored['items'][ $id ] = $after;
		$this->save( $stored );
		/* translators: %s: the schedule in words. */
		$this->log( 'exports.schedule_update', $after, __( 'Changed a scheduled export: %s', 'radius-hotel-booking-pro' ) );
		return $this->shape( $after );
	}

	/**
	 * Delete a schedule (files it made stay in the library).
	 *
	 * @param int $id Schedule id.
	 * @return void
	 * @throws DomainException 404.
	 */
	public function delete( int $id ): void {
		$stored   = $this->stored();
		$schedule = $this->find( $stored, $id );
		unset( $stored['items'][ $id ] );
		$this->save( $stored );
		/* translators: %s: the schedule in words. */
		$this->log( 'exports.schedule_delete', $schedule, __( 'Removed a scheduled export: %s', 'radius-hotel-booking-pro' ) );
	}

	/**
	 * The job: run every due schedule.
	 *
	 * @param DateTimeImmutable|null $now Now (tests).
	 * @return array Schedule id => `done` | error message.
	 */
	public function runDue( ?DateTimeImmutable $now = null ): array {
		$now = $now ?? Dates::now();
		// One run at a time: overlapping cron requests would export twice. A lock
		// older than LOCK_TTL is from a run that died and is taken over.
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			$this->forget( self::LOCK );
			if ( time() - (int) get_option( self::LOCK, 0 ) < self::LOCK_TTL ) {
				return array();
			}
			update_option( self::LOCK, time(), false );
		}
		$results = array();
		$user    = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			foreach ( array_keys( $this->fresh()['items'] ) as $id ) {
				// Re-read for each schedule: an edit made meanwhile is never overwritten.
				$stored   = $this->fresh();
				$schedule = $stored['items'][ $id ] ?? null;
				if ( ! $schedule || empty( $schedule['enabled'] ) || '' === (string) $schedule['next_run'] || Dates::to_gmt_db( $now ) < (string) $schedule['next_run'] ) {
					continue;
				}
				// Claimed before the export runs: a run that dies is not retried
				// every quarter hour, it waits for its next occurrence (a missed
				// slot — site down — is not replayed twice either).
				$schedule['next_run']    = $this->nextRun( $schedule, $now->modify( '+1 minute' ) );
				$schedule['last_run']    = Dates::to_gmt_db( $now );
				$schedule['last_status'] = 'running';
				$stored['items'][ $id ]  = $schedule;
				$this->save( $stored );

				$input = array(
					'format' => $schedule['format'],
					'mode'   => $schedule['mode'],
				) + $this->period( $schedule, $now );
				$outcome = array(
					'last_status' => 'done',
					'last_error'  => '',
				);
				try {
					$export                 = ( new ExportService() )->generate( $schedule['kind'], $input, 'scheduled' );
					$outcome['last_export'] = (int) $export['id'];
					$results[ $id ]         = 'done';
				} catch ( \Throwable $e ) {
					$outcome['last_status'] = 'failed';
					$outcome['last_error']  = $e->getMessage();
					$results[ $id ]         = $e->getMessage();
				}
				// Only the outcome is written back, onto the schedule as it is now.
				$stored = $this->fresh();
				if ( isset( $stored['items'][ $id ] ) ) {
					$stored['items'][ $id ] = $outcome + $stored['items'][ $id ];
					$this->save( $stored );
				}
			}
		} finally {
			wp_set_current_user( $user );
			delete_option( self::LOCK );
		}
		return $results;
	}

	/**
	 * The stored schedules, read from the database rather than this request's
	 * cache (another request may have saved since).
	 *
	 * @return array `{ next_id, items }`.
	 */
	private function fresh(): array {
		$this->forget( self::OPTION );
		return $this->stored();
	}

	/**
	 * Drop an option from the object cache.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	private function forget( string $option ): void {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * The period a run at `$at` exports: the previous full week (Monday to
	 * Sunday) or the previous calendar month; none for the guest list.
	 *
	 * @param array             $schedule Schedule.
	 * @param DateTimeImmutable $at       The run's moment.
	 * @return array `{ from, to }` (`Y-m-d`), or empty.
	 */
	public function period( array $schedule, DateTimeImmutable $at ): array {
		if ( 'guests' === $schedule['kind'] ) {
			return array();
		}
		$local = $at->setTimezone( Dates::timezone() );
		if ( 'monthly' === $schedule['frequency'] ) {
			$first = $local->modify( 'first day of last month' );
			return array(
				'from' => $first->format( 'Y-m-01' ),
				'to'   => $first->format( 'Y-m-t' ),
			);
		}
		$monday = $local->modify( 'monday this week' )->modify( '-7 days' );
		return array(
			'from' => $monday->format( 'Y-m-d' ),
			'to'   => $monday->modify( '+6 days' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * The schedule's next occurrence strictly after `$after` (GMT, DB format).
	 *
	 * @param array             $schedule Schedule.
	 * @param DateTimeImmutable $after    From when.
	 * @return string
	 */
	public function nextRun( array $schedule, DateTimeImmutable $after ): string {
		$local = $after->setTimezone( Dates::timezone() );
		list( $hour, $minute ) = array_map( 'intval', explode( ':', (string) $schedule['time'] ) );
		if ( 'monthly' === $schedule['frequency'] ) {
			$day       = (int) $schedule['day'];
			$candidate = $local->setDate( (int) $local->format( 'Y' ), (int) $local->format( 'n' ), $day )->setTime( $hour, $minute );
			if ( $candidate <= $local ) {
				$next      = $local->modify( 'first day of next month' );
				$candidate = $next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), $day )->setTime( $hour, $minute );
			}
		} else {
			$weekday   = (int) $schedule['weekday'];
			$candidate = $local->setTime( $hour, $minute );
			$shift     = ( $weekday - (int) $local->format( 'N' ) + 7 ) % 7;
			$candidate = $candidate->modify( '+' . $shift . ' days' );
			if ( $candidate <= $local ) {
				$candidate = $candidate->modify( '+7 days' );
			}
		}
		return Dates::to_gmt_db( $candidate );
	}

	/**
	 * Check and normalise a schedule's fields.
	 *
	 * @param array $input Fields.
	 * @return array
	 * @throws DomainException 422 with field errors.
	 */
	private function validate( array $input ): array {
		$kinds   = array_keys( ExportService::kinds() );
		$formats = array_keys( ExportWriter::streamed() );
		$out     = array(
			'kind'      => sanitize_key( (string) ( $input['kind'] ?? 'bookings' ) ),
			'frequency' => 'monthly' === ( $input['frequency'] ?? '' ) ? 'monthly' : 'weekly',
			'weekday'   => (int) ( $input['weekday'] ?? 1 ),
			'day'       => (int) ( $input['day'] ?? 1 ),
			'time'      => (string) ( $input['time'] ?? '06:00' ),
			'mode'      => 'created' === ( $input['mode'] ?? '' ) ? 'created' : 'arrival',
			'format'    => sanitize_key( (string) ( $input['format'] ?? 'csv' ) ),
			'enabled'   => rest_sanitize_boolean( $input['enabled'] ?? true ),
		);
		$errors  = array();
		if ( ! in_array( $out['kind'], $kinds, true ) ) {
			$errors['kind'] = __( 'Choose what to export.', 'radius-hotel-booking-pro' );
		}
		if ( ! in_array( $out['format'], $formats, true ) ) {
			$errors['format'] = __( 'Choose a file format.', 'radius-hotel-booking-pro' );
		}
		if ( $out['weekday'] < 1 || $out['weekday'] > 7 ) {
			$errors['weekday'] = __( 'Choose a day of the week.', 'radius-hotel-booking-pro' );
		}
		if ( $out['day'] < 1 || $out['day'] > 28 ) {
			$errors['day'] = __( 'Choose a day from 1 to 28 (every month has it).', 'radius-hotel-booking-pro' );
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $out['time'] ) ) {
			$errors['time'] = __( 'Enter a time as HH:MM.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::invalid( $errors );
		}
		return $out;
	}

	/**
	 * A schedule as the screen shows it (times as ISO, site time).
	 *
	 * @param array $schedule Stored schedule.
	 * @return array
	 */
	private function shape( array $schedule ): array {
		$iso = static fn( $gmt ) => '' !== (string) $gmt ? Dates::to_iso( Dates::from_gmt( (string) $gmt ) ) : null;
		return array_merge(
			$schedule,
			array(
				'id'       => (int) $schedule['id'],
				'enabled'  => (bool) $schedule['enabled'],
				'next_run' => $schedule['enabled'] ? $iso( $schedule['next_run'] ?? '' ) : null,
				'last_run' => $iso( $schedule['last_run'] ?? '' ),
				'summary'  => $this->summary( $schedule ),
			)
		);
	}

	/**
	 * A schedule in words, for the log.
	 *
	 * @param array $schedule Schedule.
	 * @return string
	 */
	private function summary( array $schedule ): string {
		$kinds = ExportService::kinds();
		$what  = (string) ( $kinds[ $schedule['kind'] ]['label'] ?? $schedule['kind'] );
		if ( 'monthly' === $schedule['frequency'] ) {
			/* translators: 1: what (Bookings), 2: day of the month, 3: time, 4: file format. */
			return sprintf( __( '%1$s, monthly on day %2$d at %3$s, %4$s', 'radius-hotel-booking-pro' ), $what, (int) $schedule['day'], $schedule['time'], strtoupper( $schedule['format'] ) );
		}
		global $wp_locale;
		$day = $wp_locale ? $wp_locale->get_weekday( (int) $schedule['weekday'] % 7 ) : (string) $schedule['weekday'];
		/* translators: 1: what (Bookings), 2: weekday, 3: time, 4: file format. */
		return sprintf( __( '%1$s, every %2$s at %3$s, %4$s', 'radius-hotel-booking-pro' ), $what, $day, $schedule['time'], strtoupper( $schedule['format'] ) );
	}

	/**
	 * The stored option.
	 *
	 * @return array `{ next_id, items: id => schedule }`.
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return array(
			'next_id' => (int) ( $stored['next_id'] ?? 0 ),
			'items'   => is_array( $stored['items'] ?? null ) ? $stored['items'] : array(),
		);
	}

	/**
	 * Save the option (not autoloaded).
	 *
	 * @param array $stored `{ next_id, items }`.
	 * @return void
	 */
	private function save( array $stored ): void {
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * One stored schedule.
	 *
	 * @param array $stored Option.
	 * @param int   $id     Id.
	 * @return array
	 * @throws DomainException 404.
	 */
	private function find( array $stored, int $id ): array {
		if ( ! isset( $stored['items'][ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::notFound( __( 'This scheduled export does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $stored['items'][ $id ];
	}

	/**
	 * Log a schedule change.
	 *
	 * @param string $action   Action key.
	 * @param array  $schedule Schedule.
	 * @param string $format   Description with one `%s` (the summary).
	 * @return void
	 */
	private function log( string $action, array $schedule, string $format ): void {
		if ( ! function_exists( 'rtbp_activity' ) ) {
			return;
		}
		rtbp_activity(
			$action,
			array(
				'type'  => 'export_schedule',
				'id'    => (int) $schedule['id'],
				'label' => $this->summary( $schedule ),
			),
			array(
				'after'       => array_intersect_key( $schedule, array_flip( array( 'kind', 'frequency', 'weekday', 'day', 'time', 'mode', 'format', 'enabled' ) ) ),
				'description' => sprintf( $format, $this->summary( $schedule ) ),
			)
		);
	}
}

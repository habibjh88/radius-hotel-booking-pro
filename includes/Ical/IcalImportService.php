<?php
/**
 * The iCal import feeds (feature 8.10, import side).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Ical
 */

namespace RadiusTheme\RadiusHotelBookingPro\Ical;

use DateTimeImmutable;
use InvalidArgumentException;
use RadiusTheme\RadiusHotelBooking\Core\Container\Container;
use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Repositories\AvailabilityRepository;
use RadiusTheme\RadiusHotelBooking\Repositories\BlockRepository;
use RadiusTheme\RadiusHotelBooking\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * A room reads a channel's calendar (Booking.com, Airbnb…) every
 * `interval_minutes` and mirrors it as blocks in the free `blocks` table:
 * `source = ical`, `feed_id`, `external_uid` = the event's UID, `reason` =
 * the feed's name. Each sync reconciles by UID — new events become blocks,
 * moved ones are updated, events gone from the calendar are removed (past
 * blocks are left as they were). A channel night D → E is blocked from D at
 * the hotel's check-in time to E at its check-out time.
 *
 * A failed read keeps the blocks already there (a channel that is down must
 * not reopen its rooms) and records the error on the feed.
 */
class IcalImportService {

	/**
	 * Sync intervals staff can choose, in minutes.
	 */
	public const INTERVALS = array( 15, 30, 60, 180, 360, 720, 1440 );

	/**
	 * Channel calendars per room.
	 */
	public const MAX_PER_ROOM = 10;

	/**
	 * Largest calendar read, in bytes.
	 */
	public const MAX_BYTES = 2097152;

	/**
	 * How far ahead events become blocks, in days (the calendar's own limit).
	 */
	public const DAYS_AHEAD = 1100;

	/**
	 * Feeds.
	 *
	 * @var IcalFeedRepository
	 */
	private IcalFeedRepository $feeds;

	/**
	 * Constructor.
	 *
	 * @param IcalFeedRepository|null $feeds Feeds.
	 */
	public function __construct( ?IcalFeedRepository $feeds = null ) {
		$this->feeds = $feeds ?? new IcalFeedRepository();
	}

	/**
	 * Every live room with its export link and its channel calendars.
	 *
	 * @return array[] The export entry (IcalExportService) plus `imports`.
	 */
	public function rooms(): array {
		$imports = array();
		foreach ( $this->feeds->imports() as $row ) {
			$imports[ (int) $row['room_id'] ][] = self::shape( $row );
		}
		return array_map(
			static fn( $room ) => array_merge( $room, array( 'imports' => $imports[ $room['room_id'] ] ?? array() ) ),
			( new IcalExportService( $this->feeds ) )->rooms()
		);
	}

	/**
	 * Add a channel calendar to a room, then read it once.
	 *
	 * @param array $input `{ room_id, name, url, interval_minutes }`.
	 * @return array `{ feed, sync }`.
	 * @throws DomainException 422 (fields, or too many calendars).
	 */
	public function create( array $input ): array {
		$room_id = absint( $input['room_id'] ?? 0 );
		$room    = $this->room( $room_id );
		$data    = $this->validate( $input, $room_id, 0 );
		if ( count( $this->feeds->imports( $room_id ) ) >= self::MAX_PER_ROOM ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( array( 'url' => sprintf( /* translators: %d: most channel calendars per room. */ __( 'A room can read at most %d channel calendars.', 'radius-hotel-booking-pro' ), self::MAX_PER_ROOM ) ) );
		}

		$id = $this->feeds->insert(
			array_merge(
				$data,
				array(
					'room_id'    => $room_id,
					'direction'  => 'import',
					'created_by' => get_current_user_id() ? get_current_user_id() : null,
				)
			)
		);
		rtbp_activity(
			'ical.import_create',
			self::subject( $room ),
			array(
				'after'       => self::logged( $data ),
				/* translators: 1: channel calendar name, 2: room number. */
				'description' => sprintf( __( 'Added the channel calendar %1$s to room %2$s', 'radius-hotel-booking-pro' ), $data['name'], $room['number'] ),
			)
		);

		$sync = $this->sync( $id, true );
		return array(
			'feed' => $this->get( $id ),
			'sync' => $sync,
		);
	}

	/**
	 * Change a channel calendar; a new address is read at once.
	 *
	 * @param int   $id    Feed id.
	 * @param array $input `{ name, url, interval_minutes }`.
	 * @return array `{ feed, sync|null }`.
	 */
	public function update( int $id, array $input ): array {
		$feed = $this->import( $id );
		$room = $this->room( (int) $feed['room_id'] );
		$data = $this->validate( $input, (int) $feed['room_id'], $id );

		$before = self::logged( $feed );
		$after  = self::logged( $data );
		if ( $before === $after && $feed['url'] === $data['url'] ) {
			return array(
				'feed' => $this->get( $id ),
				'sync' => null,
			);
		}
		$this->feeds->update( $id, $data );
		rtbp_activity(
			'ical.import_update',
			self::subject( $room ),
			array(
				'before'      => $before,
				'after'       => $after,
				/* translators: 1: channel calendar name, 2: room number. */
				'description' => sprintf( __( 'Changed the channel calendar %1$s of room %2$s', 'radius-hotel-booking-pro' ), $data['name'], $room['number'] ),
			)
		);

		$sync = $feed['url'] !== $data['url'] ? $this->sync( $id, true ) : null;
		return array(
			'feed' => $this->get( $id ),
			'sync' => $sync,
		);
	}

	/**
	 * Remove a channel calendar and every block it made.
	 *
	 * @param int $id Feed id.
	 * @return array `{ removed }` blocks.
	 */
	public function delete( int $id ): array {
		$feed    = $this->import( $id );
		$room    = $this->feeds->rooms( (int) $feed['room_id'] )[0] ?? array(
			'id'     => (int) $feed['room_id'],
			'number' => '#' . $feed['room_id'],
		);
		$blocks  = self::blocks();
		$removed = Transaction::run(
			function () use ( $id, $feed, $room, $blocks ) {
				$this->feeds->find( $id, true );
				$rows = $blocks->forFeed( 'ical', $id );
				foreach ( $rows as $row ) {
					$blocks->delete( (int) $row['id'] );
				}
				$this->feeds->delete( $id );
				rtbp_activity(
					'ical.import_delete',
					self::subject( $room ),
					array(
						'before'      => self::logged( $feed ),
						/* translators: 1: channel calendar name, 2: room number, 3: blocks removed. */
						'description' => sprintf( __( 'Removed the channel calendar %1$s of room %2$s and its %3$d blocked periods', 'radius-hotel-booking-pro' ), $feed['name'], $room['number'], count( $rows ) ),
					)
				);
				return count( $rows );
			}
		);
		return array( 'removed' => $removed );
	}

	/**
	 * Read a channel calendar and mirror it as blocks.
	 *
	 * @param int                    $id     Feed id.
	 * @param bool                   $manual Asked for by staff (always logged).
	 * @param DateTimeImmutable|null $now    Now.
	 * @return array `{ ok, error, events, skipped, created, updated, removed, overlaps }`.
	 */
	public function sync( int $id, bool $manual = false, ?DateTimeImmutable $now = null ): array {
		$now    = $now ?? Dates::now();
		$feed   = $this->import( $id );
		$result = array(
			'ok'       => false,
			'error'    => '',
			'events'   => 0,
			'skipped'  => 0,
			'created'  => 0,
			'updated'  => 0,
			'removed'  => 0,
			'overlaps' => 0,
		);

		$rooms = $this->feeds->rooms( (int) $feed['room_id'] );
		if ( ! $rooms ) {
			return $this->fail( $feed, $result, __( 'The room of this calendar was removed.', 'radius-hotel-booking-pro' ), $manual, $now );
		}
		$room = $rooms[0];

		$read = $this->read( (string) $feed['url'] );
		if ( '' !== $read['error'] ) {
			return $this->fail( $feed, $result, $read['error'], $manual, $now );
		}
		try {
			$parsed = IcalParser::parse( $read['body'], Dates::timezone() );
		} catch ( InvalidArgumentException $e ) {
			return $this->fail( $feed, $result, __( 'This address does not return a calendar (iCal).', 'radius-hotel-booking-pro' ), $manual, $now );
		}
		if ( ! $parsed['complete'] ) {
			return $this->fail( $feed, $result, __( 'The calendar arrived incomplete (cut off or too large). The blocked dates were kept.', 'radius-hotel-booking-pro' ), $manual, $now );
		}
		$result['events']  = count( $parsed['events'] );
		$result['skipped'] = $parsed['skipped'];

		$blocks = self::blocks();
		$counts = Transaction::run(
			function () use ( $id, $feed, $room, $parsed, $blocks, $now, $manual ) {
				$this->feeds->find( $id, true );
				// The room locked like any booking write (§7.1): a stay being
				// written now either commits first (and is counted below) or
				// waits and then sees the blocks.
				if ( method_exists( AvailabilityRepository::class, 'lockRooms' ) ) {
					( new AvailabilityRepository() )->lockRooms( array( (int) $room['id'] ) );
				}
				$existing = $blocks->forFeed( 'ical', $id );
				$now_gmt  = Dates::to_gmt_db( $now );

				// A calendar that suddenly lists nothing while blocks are still
				// ahead is kept as it was on an automatic read (a channel
				// incident); staff accept it with "Read now".
				$ahead = count( array_filter( $existing, static fn( $row ) => $row['end_at_gmt'] > $now_gmt ) );
				if ( ! $manual && ! $parsed['events'] && $ahead ) {
					$this->feeds->update(
						$id,
						array(
							'last_synced_at_gmt' => $now_gmt,
							'last_status'        => 'warning',
							'last_error'         => __( 'The channel sent an empty calendar. Blocked dates kept; use Read now to accept it.', 'radius-hotel-booking-pro' ),
						),
						false
					);
					return null;
				}
				$horizon  = Dates::add_days( $now, self::DAYS_AHEAD );
				$check_in = (string) rtbp_setting( 'booking', 'checkInTime', '14:00' );
				$check_out = (string) rtbp_setting( 'booking', 'checkOutTime', '12:00' );
				$counts   = array(
					'created'  => 0,
					'updated'  => 0,
					'removed'  => 0,
					'overlaps' => 0,
				);
				$kept     = array();

				foreach ( $parsed['events'] as $event ) {
					if ( isset( $kept[ $event['uid'] ] ) ) {
						continue; // A repeated UID: the first one counts.
					}
					try {
						$start = $event['all_day'] ? Dates::at( $event['start'], $check_in ) : $event['start']->setTimezone( Dates::timezone() );
						$end   = $event['all_day'] ? Dates::at( $event['end'], $check_out ) : $event['end']->setTimezone( Dates::timezone() );
					} catch ( InvalidArgumentException $e ) {
						continue;
					}
					if ( $end <= $now || $start > $horizon || $end <= $start ) {
						continue; // Over, too far ahead, or empty.
					}
					$kept[ $event['uid'] ] = true;

					$columns = array(
						'scope'        => 'room',
						'scope_id'     => (int) $room['id'],
						'start_at'     => Dates::to_db( $start ),
						'end_at'       => Dates::to_db( $end ),
						'start_at_gmt' => Dates::to_gmt_db( $start ),
						'end_at_gmt'   => Dates::to_gmt_db( $end ),
						'reason'       => (string) $feed['name'],
					);
					$row     = $existing[ $event['uid'] ] ?? null;
					if ( $row ) {
						if ( $row['start_at_gmt'] === $columns['start_at_gmt'] && $row['end_at_gmt'] === $columns['end_at_gmt'] && (int) $row['scope_id'] === $columns['scope_id'] && 'room' === $row['scope'] && $row['reason'] === $columns['reason'] ) {
							continue;
						}
						$blocks->update( (int) $row['id'], $columns );
						++$counts['updated'];
					} else {
						$blocks->create(
							array_merge(
								$columns,
								array(
									'source'       => 'ical',
									'feed_id'      => $id,
									'external_uid' => $event['uid'],
									'created_by'   => null,
								)
							)
						);
						++$counts['created'];
					}
				}

				// Gone from the channel: reopen, unless it is already over.
				foreach ( $existing as $uid => $row ) {
					if ( ! isset( $kept[ $uid ] ) && $row['end_at_gmt'] > $now_gmt ) {
						$blocks->delete( (int) $row['id'] );
						++$counts['removed'];
					}
				}

				// Every live block, changed or not: the warning must last as long
				// as the double booking does.
				$counts['overlaps'] = $this->feeds->overlaps( $id, $now_gmt, AvailabilityRepository::OCCUPYING );

				$this->feeds->update(
					$id,
					array(
						'last_synced_at_gmt' => $now_gmt,
						'last_status'        => $counts['overlaps'] ? 'warning' : 'ok',
						'last_error'         => $counts['overlaps'] ? self::overlapMessage( $counts['overlaps'] ) : '',
					),
					false
				);
				return $counts;
			}
		);

		if ( null === $counts ) {
			$result['ok']    = true;
			$result['error'] = __( 'The channel sent an empty calendar. Blocked dates kept; use Read now to accept it.', 'radius-hotel-booking-pro' );
			return $result;
		}
		$result = array_merge( $result, $counts, array( 'ok' => true ) );
		if ( $manual || $counts['created'] || $counts['updated'] || $counts['removed'] ) {
			$this->logSync( $feed, $room, $result );
		}
		return $result;
	}

	/**
	 * The cron job: sync the feeds that are due.
	 *
	 * @param int $limit Most feeds per run.
	 * @return int Feeds synced.
	 */
	public function syncDue( int $limit = 10 ): int {
		$done = 0;
		foreach ( $this->feeds->due( Dates::to_gmt_db( Dates::now() ), $limit ) as $id ) {
			try {
				$this->sync( $id );
				++$done;
			} catch ( \Throwable $e ) {
				// One broken feed must not stop the others; the next run retries it.
				continue;
			}
		}
		return $done;
	}

	/**
	 * One import feed, shaped.
	 *
	 * @param int $id Id.
	 * @return array
	 * @throws DomainException 404.
	 */
	public function get( int $id ): array {
		$rows = array_values( array_filter( $this->feeds->imports(), static fn( $row ) => (int) $row['id'] === $id ) );
		if ( ! $rows ) {
			throw self::missing();
		}
		return self::shape( $rows[0] );
	}

	/**
	 * An import feed for the API.
	 *
	 * @param array $row Row (with `blocks`).
	 * @return array
	 */
	public static function shape( array $row ): array {
		return array(
			'id'               => (int) $row['id'],
			'room_id'          => (int) $row['room_id'],
			'name'             => (string) $row['name'],
			'url'              => (string) $row['url'],
			'host'             => (string) wp_parse_url( (string) $row['url'], PHP_URL_HOST ),
			'interval_minutes' => (int) $row['interval_minutes'],
			'last_synced_at'   => empty( $row['last_synced_at_gmt'] ) ? null : Dates::to_db( Dates::from_gmt( (string) $row['last_synced_at_gmt'] ) ),
			'last_status'      => '' === (string) $row['last_status'] ? 'never' : (string) $row['last_status'],
			'last_error'       => (string) $row['last_error'],
			'blocks'           => (int) ( $row['blocks'] ?? 0 ),
		);
	}

	/**
	 * Fetch the calendar text.
	 *
	 * @param string $url Stored URL.
	 * @return array{body: string, error: string}
	 */
	private function read( string $url ): array {
		// Redirects are followed here, not by WordPress: its check on a
		// redirect target allows link-local, CGNAT and IPv6 addresses, so
		// every hop goes through the same guard as the first URL.
		for ( $hop = 0; $hop <= 3; $hop++ ) {
			list( $safe, $error ) = IcalUrlGuard::check( $url );
			if ( null === $safe ) {
				return array(
					'body'  => '',
					'error' => $error,
				);
			}
			$response = wp_safe_remote_get(
				$safe,
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES,
					'user-agent'          => 'RadiusHotelBooking/' . ( defined( 'RADIUS_HOTEL_BOOKING_PRO_VERSION' ) ? RADIUS_HOTEL_BOOKING_PRO_VERSION : '1' ) . '; ' . home_url( '/' ),
					'headers'             => array( 'Accept' => 'text/calendar, */*;q=0.5' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return array(
					'body'  => '',
					/* translators: %s: the network error. */
					'error' => sprintf( __( 'The calendar could not be reached: %s', 'radius-hotel-booking-pro' ), $response->get_error_message() ),
				);
			}
			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( $code >= 300 && $code < 400 && '' !== $location ) {
				$url = \WP_Http::make_absolute_url( $location, $safe );
				continue;
			}
			if ( 200 !== $code ) {
				return array(
					'body'  => '',
					/* translators: %d: HTTP status code. */
					'error' => sprintf( __( 'The channel answered with error %d. Check the address.', 'radius-hotel-booking-pro' ), $code ),
				);
			}
			$body = (string) wp_remote_retrieve_body( $response );
			if ( strlen( $body ) >= self::MAX_BYTES ) {
				return array(
					'body'  => '',
					'error' => __( 'The calendar is too large to read. The blocked dates were kept.', 'radius-hotel-booking-pro' ),
				);
			}
			return array(
				'body'  => $body,
				'error' => '',
			);
		}
		return array(
			'body'  => '',
			'error' => __( 'The calendar address redirects too many times.', 'radius-hotel-booking-pro' ),
		);
	}

	/**
	 * Record a failed sync; the blocks stay.
	 *
	 * @param array             $feed   Feed row.
	 * @param array             $result Result so far.
	 * @param string            $error  Reason.
	 * @param bool              $manual Asked for by staff.
	 * @param DateTimeImmutable $now    Now.
	 * @return array Result.
	 */
	private function fail( array $feed, array $result, string $error, bool $manual, DateTimeImmutable $now ): array {
		$this->feeds->update(
			(int) $feed['id'],
			array(
				// Stamped, so the cron retries at the next interval rather than every run.
				'last_synced_at_gmt' => Dates::to_gmt_db( $now ),
				'last_status'        => 'error',
				'last_error'         => mb_substr( $error, 0, 191 ),
			),
			false
		);
		$result['error'] = $error;
		// Logged when staff asked, or when a working feed starts failing.
		if ( $manual || 'error' !== $feed['last_status'] ) {
			$room = $this->feeds->rooms( (int) $feed['room_id'] )[0] ?? array(
				'id'     => (int) $feed['room_id'],
				'number' => '#' . $feed['room_id'],
			);
			$this->logSync( $feed, $room, $result );
		}
		return $result;
	}

	/**
	 * Log a sync.
	 *
	 * @param array $feed   Feed row.
	 * @param array $room   Room row.
	 * @param array $result Result.
	 * @return void
	 */
	private function logSync( array $feed, array $room, array $result ): void {
		rtbp_activity(
			'ical.sync',
			self::subject( $room ),
			array(
				'after'       => array_merge(
					array(
						'feed'   => (string) $feed['name'],
						'host'   => (string) wp_parse_url( (string) $feed['url'], PHP_URL_HOST ),
						'status' => $result['ok'] ? 'ok' : 'error',
					),
					$result['ok']
						? array_intersect_key( $result, array_flip( array( 'events', 'created', 'updated', 'removed', 'overlaps' ) ) )
						: array( 'error' => $result['error'] )
				),
				'description' => $result['ok']
					/* translators: 1: channel calendar name, 2: room number, 3: blocks added, 4: changed, 5: removed. */
					? sprintf( __( 'Read %1$s for room %2$s: %3$d added, %4$d changed, %5$d removed', 'radius-hotel-booking-pro' ), $feed['name'], $room['number'], $result['created'], $result['updated'], $result['removed'] )
					/* translators: 1: channel calendar name, 2: room number, 3: error. */
					: sprintf( __( 'Could not read %1$s for room %2$s: %3$s', 'radius-hotel-booking-pro' ), $feed['name'], $room['number'], $result['error'] ),
			)
		);
	}

	/**
	 * Validated columns.
	 *
	 * @param array $input     Input.
	 * @param int   $room_id   Room id.
	 * @param int   $except_id Feed being changed.
	 * @return array `{ name, url, interval_minutes }`.
	 * @throws DomainException 422.
	 */
	private function validate( array $input, int $room_id, int $except_id ): array {
		$errors = array();
		$name   = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			$errors['name'] = __( 'Name the channel, e.g. Booking.com (up to 100 characters).', 'radius-hotel-booking-pro' );
		}
		list( $url, $url_error ) = IcalUrlGuard::check( (string) ( $input['url'] ?? '' ) );
		if ( null === $url ) {
			$errors['url'] = $url_error;
		} elseif ( $this->feeds->urlTaken( $room_id, $url, $except_id ) ) {
			$errors['url'] = __( 'This room already reads this calendar.', 'radius-hotel-booking-pro' );
		}
		$interval = (int) ( $input['interval_minutes'] ?? 60 );
		if ( ! in_array( $interval, self::INTERVALS, true ) ) {
			$errors['interval_minutes'] = __( 'Choose how often to read the calendar.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		return array(
			'name'             => $name,
			'url'              => $url,
			'interval_minutes' => $interval,
		);
	}

	/**
	 * An import feed row, or 404.
	 *
	 * @param int $id Id.
	 * @return array
	 * @throws DomainException 404.
	 */
	private function import( int $id ): array {
		$feed = $this->feeds->find( $id );
		if ( ! $feed || 'import' !== $feed['direction'] ) {
			throw self::missing();
		}
		return $feed;
	}

	/**
	 * A live room, or 404.
	 *
	 * @param int $room_id Room id.
	 * @return array
	 * @throws DomainException 422 when the room is not live.
	 */
	private function room( int $room_id ): array {
		$rows = $room_id > 0 ? $this->feeds->rooms( $room_id ) : array();
		if ( ! $rows ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( array( 'room_id' => __( 'Choose a room.', 'radius-hotel-booking-pro' ) ) );
		}
		return $rows[0];
	}

	/**
	 * The free blocks repository, or a 503 when the free plugin is too old.
	 *
	 * @return BlockRepository
	 * @throws DomainException 503 when the free plugin is too old.
	 */
	private static function blocks(): BlockRepository {
		// forFeed() is in the free plugin from M08 T5b/T6b (main, 2026-09-30).
		if ( ! class_exists( BlockRepository::class ) || ! method_exists( BlockRepository::class, 'forFeed' ) || ! method_exists( BlockRepository::class, 'linesInside' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw new DomainException( 'free_outdated', __( 'Update Radius Hotel Booking to use calendar sync.', 'radius-hotel-booking-pro' ), 503 );
		}
		return Container::resolve( BlockRepository::class );
	}

	/**
	 * The 404 for a missing feed.
	 *
	 * @return DomainException
	 */
	private static function missing(): DomainException {
		return DomainException::notFound( __( 'This channel calendar does not exist.', 'radius-hotel-booking-pro' ) );
	}

	/**
	 * The warning when channel dates fall on booked stays.
	 *
	 * @param int $count Booked stays overlapping.
	 * @return string
	 */
	private static function overlapMessage( int $count ): string {
		/* translators: %d: bookings overlapping the channel's dates. */
		return sprintf( _n( '%d booking here overlaps the channel’s dates: a possible double booking.', '%d bookings here overlap the channel’s dates: possible double bookings.', $count, 'radius-hotel-booking-pro' ), $count );
	}

	/**
	 * The activity subject.
	 *
	 * @param array $room Room row.
	 * @return array
	 */
	private static function subject( array $room ): array {
		return array(
			'type'  => 'room',
			'id'    => (int) $room['id'],
			/* translators: %s: room number. */
			'label' => sprintf( __( 'Room %s', 'radius-hotel-booking-pro' ), $room['number'] ),
		);
	}

	/**
	 * What the log records of a feed: never the full address (a channel's
	 * link is a secret), only its host.
	 *
	 * @param array $feed Feed row or columns.
	 * @return array
	 */
	private static function logged( array $feed ): array {
		return array(
			'name'             => (string) $feed['name'],
			'host'             => (string) wp_parse_url( (string) $feed['url'], PHP_URL_HOST ),
			'interval_minutes' => (int) $feed['interval_minutes'],
		);
	}
}

<?php
/**
 * The iCal export feeds (feature 8.10, export side).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Ical
 */

namespace RadiusTheme\RadiusHotelBookingPro\Ical;

use DateTimeImmutable;
use RadiusTheme\RadiusHotelBooking\Core\Container\Container;
use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Repositories\AvailabilityRepository;
use RadiusTheme\RadiusHotelBooking\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Each room can publish one calendar at a secret URL (a random token, unlike
 * the legacy `/room/{id}/calendar.ics`). Staff switch it on, get a new URL
 * (the old one stops working at once) or switch it off. The feed lists the
 * room's bookings and blocks as "Not available" nights, never guest data.
 */
class IcalExportService {

	/**
	 * Days of the past a feed still lists (a stay that started yesterday).
	 */
	public const DAYS_BACK = 1;

	/**
	 * Days ahead a feed lists.
	 */
	public const DAYS_AHEAD = 730;

	/**
	 * How often a read updates "last read", in seconds (spares a write per poll).
	 */
	public const TOUCH_EVERY = 300;

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
	 * Every live room with its export feed (or none).
	 *
	 * @return array[] See entry().
	 */
	public function rooms(): array {
		$exports = $this->feeds->exports();
		return array_map( fn( $room ) => $this->entry( $room, $exports[ (int) $room['id'] ] ?? null ), $this->feeds->rooms() );
	}

	/**
	 * Switch a room's feed on, or give it a new URL.
	 *
	 * @param int $room_id Room id.
	 * @return array The room entry.
	 * @throws DomainException 404.
	 */
	public function publish( int $room_id ): array {
		$room = $this->room( $room_id );
		Transaction::run(
			function () use ( $room_id, $room ) {
				if ( ! $this->feeds->lockRoom( $room_id ) ) {
					throw self::missing();
				}
				$feed  = $this->feeds->exportFor( $room_id );
				$token = self::token();
				if ( $feed ) {
					$this->feeds->update(
						(int) $feed['id'],
						array(
							'token'              => $token,
							'last_synced_at_gmt' => null,
						)
					);
				} else {
					$this->feeds->insert(
						array(
							'room_id'    => $room_id,
							'direction'  => 'export',
							'token'      => $token,
							'created_by' => get_current_user_id() ? get_current_user_id() : null,
						)
					);
				}
				rtbp_activity(
					$feed ? 'ical.feed_regenerate' : 'ical.feed_create',
					self::subject( $room ),
					array(
						'description' => $feed
							/* translators: %s: room number. */
							? sprintf( __( 'Gave the calendar of room %s a new link; the old link stopped working', 'radius-hotel-booking-pro' ), $room['number'] )
							/* translators: %s: room number. */
							: sprintf( __( 'Published the calendar of room %s', 'radius-hotel-booking-pro' ), $room['number'] ),
					)
				);
			}
		);
		return $this->entry( $room, $this->feeds->exportFor( $room_id ) );
	}

	/**
	 * Switch a room's feed off: its link stops working.
	 *
	 * @param int $room_id Room id.
	 * @return array The room entry.
	 * @throws DomainException 404.
	 */
	public function unpublish( int $room_id ): array {
		$room = $this->room( $room_id );
		$feed = $this->feeds->exportFor( $room_id );
		if ( $feed ) {
			$this->feeds->delete( (int) $feed['id'] );
			rtbp_activity(
				'ical.feed_delete',
				self::subject( $room ),
				array(
					/* translators: %s: room number. */
					'description' => sprintf( __( 'Stopped publishing the calendar of room %s', 'radius-hotel-booking-pro' ), $room['number'] ),
				)
			);
		}
		return $this->entry( $room, null );
	}

	/**
	 * The calendar behind a token, or null when no live room has it.
	 *
	 * @param string                 $token Token.
	 * @param DateTimeImmutable|null $now   Now.
	 * @return array|null `{ filename, body }`.
	 */
	public function feed( string $token, ?DateTimeImmutable $now = null ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{48}$/', $token ) ) {
			return null;
		}
		$feed = $this->feeds->exportByToken( $token );
		if ( ! $feed ) {
			return null;
		}
		$now     = $now ?? Dates::now();
		$room_id = (int) $feed['room_id'];
		$today   = Dates::start_of_day( $now->setTimezone( Dates::timezone() )->format( 'Y-m-d' ) );
		$from    = Dates::to_gmt_db( Dates::add_days( $today, -self::DAYS_BACK ) );
		$to      = Dates::to_gmt_db( Dates::add_days( $today, self::DAYS_AHEAD ) );
		$busy    = Container::resolve( AvailabilityRepository::class )->busy( array( $room_id ), $from, $to, '', 0, Dates::to_gmt_db( $now ) );

		$body = IcalCalendar::build(
			$busy[ $room_id ] ?? array(),
			/* translators: 1: site name, 2: room number. */
			sprintf( __( '%1$s – Room %2$s', 'radius-hotel-booking-pro' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $feed['number'] ),
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			Dates::timezone(),
			$now,
			__( 'Not available', 'radius-hotel-booking-pro' ),
			(string) rtbp_setting( 'booking', 'checkInTime', '14:00' ),
			(string) rtbp_setting( 'booking', 'checkOutTime', '12:00' )
		);

		$last = empty( $feed['last_synced_at_gmt'] ) ? 0 : strtotime( $feed['last_synced_at_gmt'] . ' UTC' );
		if ( $now->getTimestamp() - $last >= self::TOUCH_EVERY ) {
			$this->feeds->update( (int) $feed['id'], array( 'last_synced_at_gmt' => Dates::to_gmt_db( $now ) ), false );
		}

		return array(
			'filename' => sanitize_file_name( 'room-' . $feed['number'] ) . '.ics',
			'body'     => $body,
		);
	}

	/**
	 * The public URL of a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function url( string $token ): string {
		return rest_url( 'radius-hotel-booking/v1/public/ical/' . $token . '.ics' );
	}

	/**
	 * A room and its feed, for the API.
	 *
	 * @param array      $room Room row.
	 * @param array|null $feed Export feed row.
	 * @return array `{ room_id, number, room_type_id, room_type, floor, published, url, published_at, last_read_at }`.
	 */
	private function entry( array $room, ?array $feed ): array {
		return array(
			'room_id'      => (int) $room['id'],
			'number'       => (string) $room['number'],
			'room_type_id' => (int) $room['room_type_id'],
			'room_type'    => (string) $room['room_type'],
			'floor'        => (string) $room['floor'],
			'published'    => null !== $feed,
			'url'          => $feed ? self::url( (string) $feed['token'] ) : null,
			'published_at' => $feed ? Dates::to_db( Dates::from_gmt( (string) $feed['updated_at'] ) ) : null,
			'last_read_at' => $feed && ! empty( $feed['last_synced_at_gmt'] ) ? Dates::to_db( Dates::from_gmt( (string) $feed['last_synced_at_gmt'] ) ) : null,
		);
	}

	/**
	 * A live room, or 404.
	 *
	 * @param int $room_id Room id.
	 * @return array
	 * @throws DomainException 404.
	 */
	private function room( int $room_id ): array {
		$rows = $room_id > 0 ? $this->feeds->rooms( $room_id ) : array();
		if ( ! $rows ) {
			throw self::missing();
		}
		return $rows[0];
	}

	/**
	 * The 404 for a missing room.
	 *
	 * @return DomainException
	 */
	private static function missing(): DomainException {
		return DomainException::notFound( __( 'This room does not exist.', 'radius-hotel-booking-pro' ) );
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
	 * A new random token: 48 hex characters (192 bits).
	 *
	 * @return string
	 */
	private static function token(): string {
		return bin2hex( random_bytes( 24 ) );
	}
}

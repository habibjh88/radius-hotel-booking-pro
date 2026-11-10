<?php
/**
 * Cash register sessions (R3-T5 – R3-T8).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\CashRegister
 */

namespace RadiusTheme\RadiusHotelBookingPro\CashRegister;

use RadiusTheme\RadiusHotelBooking\Access\Access;
use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Settings\PaymentSettings;
use RadiusTheme\RadiusHotelBooking\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A staff member opens their register at the start of a shift (with the
 * cash already in the drawer), every payment and refund they record is
 * tallied per method while it is open, and at the end they close it,
 * counting the cash: the expected and counted amounts and the difference are
 * kept. One open register per user. Opening and closing are logged.
 */
final class CashRegisterService {

	/**
	 * The method whose money sits in the drawer (the opening float is added to it).
	 */
	const CASH = 'cash';

	/**
	 * Repository.
	 *
	 * @var CashSessionRepository
	 */
	private CashSessionRepository $sessions;

	/**
	 * Constructor.
	 *
	 * @param CashSessionRepository|null $sessions Repository.
	 */
	public function __construct( ?CashSessionRepository $sessions = null ) {
		$this->sessions = $sessions ?? new CashSessionRepository();
	}

	/**
	 * The signed-in user's register: the open session with its live tally,
	 * or none, plus their last closed ones.
	 *
	 * @param int $user_id User.
	 * @return array `{ session: array|null, recent: array[] }`.
	 */
	public function mine( int $user_id ): array {
		$open   = $this->sessions->openFor( $user_id );
		$recent = $this->sessions->page(
			array(
				'user_id' => $user_id,
				'status'  => 'closed',
			),
			1,
			5
		);
		return array(
			'session' => $open ? self::shape( $open, $this->tally( $open ) ) : null,
			'recent'  => array_map( static fn( $row ) => self::shape( $row ), $recent['items'] ),
		);
	}

	/**
	 * Open the user's register.
	 *
	 * @param int   $user_id User.
	 * @param mixed $float   Cash in the drawer at the start.
	 * @return array As mine().
	 * @throws DomainException 409 `register_open`, 422 `opening_float`.
	 */
	public function open( int $user_id, $float ): array {
		$amount = is_numeric( $float ) ? Money::round( (float) $float ) : -1;
		if ( '' === $float || null === $float ) {
			$amount = 0.0;
		}
		if ( $amount < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( array( 'opening_float' => __( 'Enter the cash in the drawer, 0 or more.', 'radius-hotel-booking-pro' ) ) );
		}
		Transaction::run(
			function () use ( $user_id, $amount ) {
				if ( $this->sessions->openFor( $user_id, true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
					throw DomainException::conflict( 'register_open', __( 'Your cash register is already open.', 'radius-hotel-booking-pro' ) );
				}
				$id = $this->sessions->insert(
					array(
						'user_id'       => $user_id,
						'status'        => 'open',
						'opened_at'     => current_time( 'mysql' ),
						'opening_float' => number_format( $amount, 2, '.', '' ),
					)
				);
				self::log( 'register.open', $id, $user_id, array(), array( 'opening_float' => $amount ), __( 'Opened the cash register', 'radius-hotel-booking-pro' ) );
			}
		);
		return $this->mine( $user_id );
	}

	/**
	 * Close the user's register: what each method should hold, the cash
	 * counted, the difference.
	 *
	 * @param int    $user_id User.
	 * @param mixed  $counted Cash counted in the drawer.
	 * @param string $note    Note (why it does not match).
	 * @return array The closed session.
	 * @throws DomainException 409 `register_closed`, 422 `counted_cash`.
	 */
	public function close( int $user_id, $counted, string $note = '' ): array {
		if ( '' === $counted || null === $counted || ! is_numeric( $counted ) || (float) $counted < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( array( 'counted_cash' => __( 'Enter the cash you counted in the drawer.', 'radius-hotel-booking-pro' ) ) );
		}
		$counted = Money::round( (float) $counted );
		$note    = mb_substr( sanitize_textarea_field( $note ), 0, 1000 );
		$id      = Transaction::run(
			function () use ( $user_id, $counted, $note ) {
				$open = $this->sessions->openFor( $user_id, true );
				if ( ! $open ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
					throw DomainException::conflict( 'register_closed', __( 'Your cash register is not open.', 'radius-hotel-booking-pro' ) );
				}
				$now      = current_time( 'mysql' );
				$expected = $this->expected( $open, $now );
				$diff     = Money::round( $counted - (float) ( $expected[ self::CASH ] ?? 0 ) );
				$this->sessions->update(
					(int) $open['id'],
					array(
						'status'     => 'closed',
						'closed_at'  => $now,
						'closed_by'  => get_current_user_id() ? get_current_user_id() : $user_id,
						'expected'   => wp_json_encode( $expected ),
						'counted'    => wp_json_encode( array( self::CASH => $counted ) ),
						'difference' => number_format( $diff, 2, '.', '' ),
						'note'       => $note,
					)
				);
				self::log(
					'register.close',
					(int) $open['id'],
					$user_id,
					array(),
					array(
						'expected'   => $expected,
						'counted'    => $counted,
						'difference' => $diff,
					),
					__( 'Closed the cash register', 'radius-hotel-booking-pro' )
				);
				return (int) $open['id'];
			}
		);
		return $this->get( $id, $user_id );
	}

	/**
	 * One session: the viewer's own, or anyone's with `register.manage`.
	 *
	 * @param int $id      Id.
	 * @param int $user_id Viewer.
	 * @return array
	 * @throws DomainException 404.
	 */
	public function get( int $id, int $user_id ): array {
		$row = $this->sessions->find( $id );
		if ( ! $row || ( (int) $row['user_id'] !== $user_id && ! self::canManage() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'This cash register session does not exist.', 'radius-hotel-booking-pro' ) );
		}
		$user               = get_userdata( (int) $row['user_id'] );
		$row['display_name'] = $user ? $user->display_name : '';
		return self::shape( $row, 'open' === $row['status'] ? $this->tally( $row ) : null );
	}

	/**
	 * Every register, for a manager.
	 *
	 * @param array $filters `{ user_id?, status?, from?, to? }`.
	 * @param int   $page    Page.
	 * @param int   $per     Per page.
	 * @return array `{ sessions, total }`.
	 */
	public function all( array $filters, int $page, int $per ): array {
		$result = $this->sessions->page( $filters, $page, $per );
		return array(
			'sessions' => array_map(
				fn( $row ) => self::shape( $row, 'open' === $row['status'] ? $this->tally( $row ) : null ),
				$result['items']
			),
			'total'    => $result['total'],
		);
	}

	/**
	 * `rtbp_payment_validated`: with *Require an open register* on, refuse a
	 * payment or refund from someone whose register is closed. Someone without
	 * `register.use` has no register to open, so the rule skips them.
	 *
	 * @param array $data The payment.
	 * @return void
	 * @throws DomainException 409 `register_closed`.
	 */
	public static function requireOpen( $data ): void {
		unset( $data );
		$user_id = get_current_user_id();
		if ( ! $user_id || ! CashRegisterSettings::get( 'requireOpen', false ) ) {
			return;
		}
		if ( class_exists( Access::class ) && ! Access::can( 'register.use' ) ) {
			return;
		}
		if ( ! ( new CashSessionRepository() )->openFor( $user_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::conflict( 'register_closed', __( 'Open your cash register first: Front desk → Cash register.', 'radius-hotel-booking-pro' ) );
		}
	}

	/**
	 * Whether the viewer sees every register.
	 *
	 * @return bool
	 */
	public static function canManage(): bool {
		return class_exists( Access::class ) ? Access::can( 'register.manage' ) : current_user_can( 'manage_options' );
	}

	/**
	 * The live tally of an open session: method => `{ amount, count }`, with
	 * the expected cash (float included).
	 *
	 * @param array $row Session.
	 * @return array `{ methods: [ { key, label, amount, count } ], expected_cash }`.
	 */
	private function tally( array $row ): array {
		$takings = $this->sessions->takings( (int) $row['user_id'], (string) $row['opened_at'], null );
		return array(
			'methods'       => self::methods( $takings ),
			'expected_cash' => Money::round( (float) $row['opening_float'] + (float) ( $takings[ self::CASH ]['amount'] ?? 0 ) ),
		);
	}

	/**
	 * What each method should hold at Close: its takings, cash with the float.
	 *
	 * @param array  $row Session.
	 * @param string $now Close time.
	 * @return array<string, float>
	 */
	private function expected( array $row, string $now ): array {
		$out = array();
		foreach ( $this->sessions->takings( (int) $row['user_id'], (string) $row['opened_at'], $now ) as $method => $totals ) {
			$out[ $method ] = Money::round( (float) $totals['amount'] );
		}
		$out[ self::CASH ] = Money::round( (float) $row['opening_float'] + (float) ( $out[ self::CASH ] ?? 0 ) );
		return $out;
	}

	/**
	 * The enabled methods in Settings order with their takings, then any other
	 * method money came in by (one since switched off).
	 *
	 * @param array $takings Method => `{ amount, count }`.
	 * @return array[] `[ { key, label, amount, count } ]`.
	 */
	private static function methods( array $takings ): array {
		$keys = class_exists( PaymentSettings::class )
			? array_map( static fn( $m ) => (string) $m['key'], PaymentSettings::enabled_methods() )
			: array();
		$keys = array_values( array_unique( array_merge( $keys, array_keys( $takings ) ) ) );
		return array_map(
			static fn( $key ) => array(
				'key'    => $key,
				'label'  => self::label( $key ),
				'amount' => (float) ( $takings[ $key ]['amount'] ?? 0 ),
				'count'  => (int) ( $takings[ $key ]['count'] ?? 0 ),
			),
			$keys
		);
	}

	/**
	 * A method's name.
	 *
	 * @param string $key Method key.
	 * @return string
	 */
	private static function label( string $key ): string {
		return class_exists( PaymentSettings::class ) ? PaymentSettings::method_label( $key ) : $key;
	}

	/**
	 * The API shape of a session.
	 *
	 * @param array      $row   Row (with `display_name` when listed).
	 * @param array|null $tally Live tally (open sessions).
	 * @return array
	 */
	public static function shape( array $row, ?array $tally = null ): array {
		$expected = json_decode( (string) ( $row['expected'] ?? '' ), true );
		$counted  = json_decode( (string) ( $row['counted'] ?? '' ), true );
		$user     = isset( $row['display_name'] ) ? (string) $row['display_name'] : '';
		if ( '' === $user ) {
			$wp_user = get_userdata( (int) $row['user_id'] );
			$user    = $wp_user ? $wp_user->display_name : '';
		}
		$closed = is_array( $expected ) ? array_map(
			static fn( $key, $amount ) => array(
				'key'      => (string) $key,
				'label'    => self::label( (string) $key ),
				'expected' => (float) $amount,
				'counted'  => is_array( $counted ) && isset( $counted[ $key ] ) ? (float) $counted[ $key ] : null,
			),
			array_keys( $expected ),
			array_values( $expected )
		) : array();
		return array(
			'id'            => (int) $row['id'],
			'user_id'       => (int) $row['user_id'],
			'user_name'     => $user,
			'status'        => (string) $row['status'],
			'opened_at'     => (string) $row['opened_at'],
			'closed_at'     => $row['closed_at'] ? (string) $row['closed_at'] : null,
			'opening_float' => (float) $row['opening_float'],
			'difference'    => null !== $row['difference'] ? (float) $row['difference'] : null,
			'note'          => (string) ( $row['note'] ?? '' ),
			// Open: the live tally; closed: what was frozen at Close.
			'live'          => $tally,
			'closed'        => $closed,
		);
	}

	/**
	 * Record an opening or closing.
	 *
	 * @param string $action      Action.
	 * @param int    $id          Session id.
	 * @param int    $user_id     Its user.
	 * @param array  $before      Before.
	 * @param array  $after       After.
	 * @param string $description Description.
	 * @return void
	 */
	private static function log( string $action, int $id, int $user_id, array $before, array $after, string $description ): void {
		if ( ! function_exists( 'rtbp_activity' ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		rtbp_activity(
			$action,
			array(
				'type'  => 'cash_session',
				'id'    => $id,
				'label' => $user ? $user->display_name : '#' . $user_id,
			),
			array(
				'before'      => $before,
				'after'       => $after,
				'description' => $description,
			)
		);
	}
}

<?php
/**
 * Statutory contributions and their payments (M15 T6, 15.12, 15.13).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * A month's contributions per authority and code — social security and tax
 * lines from the payroll calculator, and the deductions' and
 * garnishments' remittances — summed from the lines of **settled** runs paid
 * that month, with the employee and employer shares side by side (the
 * legacy kept one row per party; the hotel remits both together). Payments
 * are recorded against a month + authority + code; state:
 *
 * - `voided` — nothing due, because every run that owed it was voided (a
 *   payment made meanwhile stays listed, to be reclaimed);
 * - `unpaid` / `partial` / `paid` — from what was paid against what is due.
 *
 * A payment cannot exceed what is still due; one entered by mistake is
 * voided with a reason, never deleted. Nothing here is written when a run
 * is settled or voided: the sums follow the runs.
 */
final class StatutoryService {

	/**
	 * The months that have contributions, newest first, with their totals.
	 *
	 * @param int $limit Months.
	 * @return array[] `{ year, month, due, paid }`.
	 */
	public function months( int $limit = 24 ): array {
		global $wpdb;
		$s = PayrollRunRepository::table( 'statutory' );
		$r = PayrollRunRepository::table();
		$p = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sums over the run tables, read live.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.year, s.month, SUM( s.employee_amount + s.employer_amount ) AS due FROM %i s JOIN %i r ON r.id = s.run_id WHERE r.status = 'settled' GROUP BY s.year, s.month ORDER BY s.year DESC, s.month DESC LIMIT %d", $s, $r, $limit ), ARRAY_A );
		$paid = $wpdb->get_results( $wpdb->prepare( 'SELECT year, month, SUM( amount ) AS paid FROM %i WHERE voided_at IS NULL GROUP BY year, month', $p ), ARRAY_A );
		// phpcs:enable
		$paid_by = array();
		foreach ( (array) $paid as $row ) {
			$paid_by[ $row['year'] . '-' . $row['month'] ] = (string) $row['paid'];
		}
		return array_map(
			static fn( $row ) => array(
				'year'  => (int) $row['year'],
				'month' => (int) $row['month'],
				'due'   => number_format( (float) $row['due'], 2, '.', '' ),
				'paid'  => number_format( (float) ( $paid_by[ $row['year'] . '-' . $row['month'] ] ?? 0 ), 2, '.', '' ),
			),
			(array) $rows
		);
	}

	/**
	 * One month's contributions with their payments and state.
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @return array{items: array[], totals: array}
	 * @throws DomainException 422 on a bad month.
	 */
	public function month( int $year, int $month ): array {
		self::checkMonth( $year, $month );
		global $wpdb;
		$s = PayrollRunRepository::table( 'statutory' );
		$r = PayrollRunRepository::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sums over the run tables, read live.
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT s.authority, s.code, s.kind, MAX( s.label ) AS label, r.status, SUM( s.employee_amount ) AS employee, SUM( s.employer_amount ) AS employer, COUNT( DISTINCT s.employee_id ) AS employees FROM %i s JOIN %i r ON r.id = s.run_id WHERE s.year = %d AND s.month = %d AND r.status IN ( 'settled', 'voided' ) GROUP BY s.authority, s.code, s.kind, r.status ORDER BY s.kind = 'statutory' DESC, s.authority, s.code", $s, $r, $year, $month ), ARRAY_A );
		$payments = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE year = %d AND month = %d ORDER BY paid_on, id', self::table(), $year, $month ), ARRAY_A );
		// phpcs:enable

		$items = array();
		foreach ( (array) $rows as $row ) {
			$key = $row['authority'] . '|' . $row['code'];
			if ( ! isset( $items[ $key ] ) ) {
				$items[ $key ] = array(
					'authority' => (string) $row['authority'],
					'code'      => (string) $row['code'],
					'label'     => (string) $row['label'],
					'kind'      => (string) $row['kind'],
					'employees' => 0,
					'employee'  => '0',
					'employer'  => '0',
					'voided'    => '0',
					'payments'  => array(),
				);
			}
			$both = Decimal::add( Decimal::of( (string) $row['employee'] ), Decimal::of( (string) $row['employer'] ) );
			if ( 'settled' === $row['status'] ) {
				$items[ $key ]['employee']  = Decimal::add( $items[ $key ]['employee'], Decimal::of( (string) $row['employee'] ) );
				$items[ $key ]['employer']  = Decimal::add( $items[ $key ]['employer'], Decimal::of( (string) $row['employer'] ) );
				$items[ $key ]['employees'] = max( $items[ $key ]['employees'], (int) $row['employees'] );
			} else {
				$items[ $key ]['voided'] = Decimal::add( $items[ $key ]['voided'], $both );
			}
		}
		foreach ( (array) $payments as $payment ) {
			$key = $payment['authority'] . '|' . $payment['code'];
			if ( ! isset( $items[ $key ] ) ) {
				continue;
			}
			$items[ $key ]['payments'][] = self::shapePayment( $payment );
		}

		$totals = array(
			'due'     => '0',
			'paid'    => '0',
			'balance' => '0',
		);
		$out    = array();
		foreach ( $items as $item ) {
			$due  = Decimal::add( $item['employee'], $item['employer'] );
			$paid = '0';
			foreach ( $item['payments'] as $payment ) {
				if ( ! $payment['voided_at'] ) {
					$paid = Decimal::add( $paid, $payment['amount'] );
				}
			}
			$balance = Decimal::max( '0', Decimal::sub( $due, $paid ) );
			// Every run that owed it was voided; a payment made meanwhile stays listed (to be reclaimed).
			if ( 0 === Decimal::cmp( $due, '0' ) && Decimal::cmp( $item['voided'], '0' ) > 0 ) {
				$state = 'voided';
			} elseif ( 0 === Decimal::cmp( $paid, '0' ) ) {
				$state = 'unpaid';
			} elseif ( Decimal::cmp( $paid, $due ) < 0 ) {
				$state = 'partial';
			} else {
				$state = 'paid';
			}
			$money = static fn( $v ) => number_format( (float) $v, 2, '.', '' );
			$out[] = array(
				'authority' => $item['authority'],
				'code'      => $item['code'],
				'label'     => $item['label'],
				'kind'      => $item['kind'],
				'employees' => $item['employees'],
				'employee'  => $money( $item['employee'] ),
				'employer'  => $money( $item['employer'] ),
				'due'       => $money( $due ),
				'paid'      => $money( $paid ),
				'balance'   => $money( $balance ),
				'state'     => $state,
				'payments'  => $item['payments'],
			);
			$totals['due']     = Decimal::add( $totals['due'], $due );
			$totals['paid']    = Decimal::add( $totals['paid'], $paid );
			$totals['balance'] = Decimal::add( $totals['balance'], $balance );
		}
		return array(
			'year'   => $year,
			'month'  => $month,
			'items'  => $out,
			'totals' => array_map( static fn( $v ) => number_format( (float) $v, 2, '.', '' ), $totals ),
		);
	}

	/**
	 * Record a payment against one contribution of one month.
	 *
	 * @param array $input `{ year, month, authority, code, amount, paid_on, method?, reference? }`.
	 * @return array The month (as month()).
	 * @throws DomainException 404 `no_contribution`, 409 `more_than_due` / `busy`, 422.
	 */
	public function record( array $input ): array {
		$year      = (int) ( $input['year'] ?? 0 );
		$month     = (int) ( $input['month'] ?? 0 );
		$authority = sanitize_text_field( (string) ( $input['authority'] ?? '' ) );
		$code      = sanitize_text_field( (string) ( $input['code'] ?? '' ) );
		self::checkMonth( $year, $month );
		$errors = array();
		$amount = $input['amount'] ?? '';
		if ( ! is_numeric( $amount ) || (float) $amount <= 0 ) {
			$errors['amount'] = __( 'Enter an amount above zero.', 'radius-hotel-booking-pro' );
		}
		$paid_on = substr( trim( (string) ( $input['paid_on'] ?? '' ) ), 0, 10 );
		if ( ! PayPeriods::valid( $paid_on ) ) {
			$errors['paid_on'] = __( 'Enter a valid date.', 'radius-hotel-booking-pro' );
		} elseif ( $paid_on > Dates::today() ) {
			$errors['paid_on'] = __( 'This is in the future.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::invalid( $errors );
		}
		// Rounded to the currency's precision, like every line, so a balance can always be cleared.
		$amount = Decimal::money( Decimal::of( (string) $amount ) );
		if ( (float) $amount <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::invalid( array( 'amount' => __( 'Enter an amount greater than zero.', 'radius-hotel-booking-pro' ) ) );
		}

		// One payment at a time per contribution: the balance is read and written under a named lock.
		global $wpdb;
		$lock = 'rtbp_pro_stat_' . md5( "$year-$month-$authority-$code" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, not data.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw new DomainException( 'busy', __( 'Someone else is recording a payment for this right now. Please try again.', 'radius-hotel-booking-pro' ), 503 );
		}
		try {
			$item = null;
			foreach ( $this->month( $year, $month )['items'] as $row ) {
				if ( $row['authority'] === $authority && $row['code'] === $code ) {
					$item = $row;
				}
			}
			if ( ! $item || 'voided' === $item['state'] || 0 === Decimal::cmp( $item['due'], '0' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
				throw new DomainException( 'no_contribution', __( 'Nothing is due for this contribution this month.', 'radius-hotel-booking-pro' ), 404 );
			}
			if ( Decimal::cmp( $amount, $item['balance'] ) > 0 ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
				throw new DomainException(
					'more_than_due',
					/* translators: %s: amount still due. */
					sprintf( __( 'Only %s is still due.', 'radius-hotel-booking-pro' ), number_format_i18n( (float) $item['balance'] ) ),
					409,
					array( 'amount' => __( 'This is more than what is still due.', 'radius-hotel-booking-pro' ) )
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$now = current_time( 'mysql' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plain insert under the lock.
			$wpdb->insert(
				self::table(),
				array(
					'year'       => $year,
					'month'      => $month,
					'authority'  => $authority,
					'code'       => $code,
					'amount'     => $amount,
					'paid_on'    => $paid_on,
					'method'     => mb_substr( sanitize_text_field( (string) ( $input['method'] ?? '' ) ), 0, 60 ),
					'reference'  => mb_substr( sanitize_text_field( (string) ( $input['reference'] ?? '' ) ), 0, 150 ),
					'created_by' => get_current_user_id() ? get_current_user_id() : null,
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
			$id = (int) $wpdb->insert_id;
			self::log(
				$id,
				$item,
				$year,
				$month,
				array(),
				array(
					'amount'  => $amount,
					'paid_on' => $paid_on,
				),
				/* translators: 1: amount, 2: contribution, 3: authority, 4: month (MM/YYYY). */
				sprintf( __( 'Recorded a payment of %1$s for %2$s (%3$s), %4$s', 'radius-hotel-booking-pro' ), number_format_i18n( (float) $amount ), $item['label'], $authority, sprintf( '%02d/%d', $month, $year ) )
			);
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, not data.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
		return $this->month( $year, $month );
	}

	/**
	 * Void a payment entered by mistake.
	 *
	 * @param int    $id     Payment.
	 * @param string $reason Why.
	 * @return array The month.
	 * @throws DomainException 404, 409 `already_voided`, 422 `reason`.
	 */
	public function void( int $id, string $reason ): array {
		$reason = mb_substr( sanitize_text_field( $reason ), 0, 255 );
		if ( '' === $reason ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::invalid( array( 'reason' => __( 'Say why the payment is voided.', 'radius-hotel-booking-pro' ) ) );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one row, read live.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		if ( ! $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'This payment does not exist.', 'radius-hotel-booking-pro' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- guarded update: only a live payment.
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET voided_at = %s, voided_by = %d, void_reason = %s WHERE id = %d AND voided_at IS NULL', self::table(), current_time( 'mysql' ), get_current_user_id(), $reason, $id ) );
		if ( ! $done ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::conflict( 'already_voided', __( 'This payment is already voided.', 'radius-hotel-booking-pro' ) );
		}
		self::log(
			$id,
			array(
				'label'     => (string) $row['code'],
				'authority' => (string) $row['authority'],
			),
			(int) $row['year'],
			(int) $row['month'],
			array( 'amount' => (string) $row['amount'] ),
			array( 'voided' => $reason ),
			/* translators: 1: amount, 2: reason. */
			sprintf( __( 'Voided a statutory payment of %1$s: %2$s', 'radius-hotel-booking-pro' ), number_format_i18n( (float) $row['amount'] ), $reason )
		);
		return $this->month( (int) $row['year'], (int) $row['month'] );
	}

	/**
	 * The payments table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_statutory_payments';
	}

	/**
	 * A payment for the API.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function shapePayment( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'amount'      => number_format( (float) $row['amount'], 2, '.', '' ),
			'paid_on'     => substr( (string) $row['paid_on'], 0, 10 ),
			'method'      => (string) $row['method'],
			'reference'   => (string) $row['reference'],
			'voided_at'   => $row['voided_at'] ? (string) $row['voided_at'] : null,
			'void_reason' => $row['void_reason'] ? (string) $row['void_reason'] : null,
		);
	}

	/**
	 * A valid month, or 422.
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @return void
	 * @throws DomainException 422.
	 */
	private static function checkMonth( int $year, int $month ): void {
		if ( $year < 2000 || $year > 2100 || $month < 1 || $month > 12 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::invalid( array( 'month' => __( 'Choose a month.', 'radius-hotel-booking-pro' ) ) );
		}
	}

	/**
	 * Log a payment write as `payroll.statutory_pay`.
	 *
	 * @param int    $id          Payment id.
	 * @param array  $item        `{ label, authority }`.
	 * @param int    $year        Year.
	 * @param int    $month       Month.
	 * @param array  $before      Before.
	 * @param array  $after       After.
	 * @param string $description Description.
	 * @return void
	 */
	private static function log( int $id, array $item, int $year, int $month, array $before, array $after, string $description ): void {
		if ( ! function_exists( 'rtbp_activity' ) ) {
			return;
		}
		rtbp_activity(
			'payroll.statutory_pay',
			array(
				'type'  => 'statutory_payment',
				'id'    => $id,
				'label' => sprintf( '%s · %s · %02d/%d', $item['authority'], $item['label'], $month, $year ),
			),
			array(
				'before'      => $before,
				'after'       => $after,
				'description' => $description,
			)
		);
	}
}

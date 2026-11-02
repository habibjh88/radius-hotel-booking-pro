<?php
/**
 * Bonus rules: bonuses a pay run adds on its own to every employee it applies to.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Payroll → Bonuses (R1-T17). Each rule is
 * `{ key, label, kind, amount, percent, taxable, applies (all|selected), employees: [ids] }`:
 *
 * - `fixed` — the same amount every pay period;
 * - `percent` — a percentage of the period's base pay;
 * - more kinds from `rtbp_pro_payroll_bonus_kinds` (a country add-on: the
 *   Côte d'Ivoire seniority bonus), turned into `fixed` or `percent` for one
 *   employee by `rtbp_pro_payroll_bonus_resolve`.
 *
 * A taxable bonus is an earning (in gross pay, before contributions and tax);
 * a non-taxable one is added to net pay. Manual bonuses typed into a run line
 * (the Bonus pay item) still work alongside.
 */
final class BonusRules {

	/**
	 * The kinds a rule can be: key => label.
	 *
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		$kinds = array(
			'fixed'   => __( 'Fixed amount each pay period', 'radius-hotel-booking-pro' ),
			'percent' => __( 'Percentage of base pay', 'radius-hotel-booking-pro' ),
		);

		/**
		 * Filters the kinds of bonus rule. An add-on's kind is resolved per
		 * employee with `rtbp_pro_payroll_bonus_resolve`.
		 *
		 * @param array<string, string> $kinds Key => label.
		 */
		return (array) apply_filters( 'rtbp_pro_payroll_bonus_kinds', $kinds );
	}

	/**
	 * Clean the rules list (the `bonusRules` setting).
	 *
	 * @param mixed $value Raw list.
	 * @return array|\WP_Error
	 */
	public static function sanitize( $value ) {
		if ( ! is_array( $value ) || count( $value ) > PayrollSettings::MAX_ROWS ) {
			/* translators: %d: most rows. */
			return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( 'List at most %d bonuses.', 'radius-hotel-booking-pro' ), PayrollSettings::MAX_ROWS ) );
		}
		$kinds = self::kinds();
		$clean = array();
		$keys  = array();
		foreach ( array_values( $value ) as $index => $row ) {
			$row   = is_array( $row ) ? $row : array();
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			if ( '' === $label || mb_strlen( $label ) > 60 ) {
				/* translators: %d: position in the list. */
				return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( 'Bonus %d needs a name of at most 60 characters.', 'radius-hotel-booking-pro' ), $index + 1 ) );
			}
			$kind = (string) ( $row['kind'] ?? 'fixed' );
			if ( ! isset( $kinds[ $kind ] ) ) {
				/* translators: %s: bonus name. */
				return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( '"%s": choose how it is calculated.', 'radius-hotel-booking-pro' ), $label ) );
			}
			$amount  = (float) ( $row['amount'] ?? 0 );
			$percent = (float) ( $row['percent'] ?? 0 );
			if ( $amount < 0 || $percent < 0 || $percent > 100 ) {
				/* translators: %s: bonus name. */
				return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( '"%s": the amount must be 0 or more and the percentage 0–100 %%.', 'radius-hotel-booking-pro' ), $label ) );
			}
			if ( ( 'fixed' === $kind && $amount <= 0 ) || ( 'percent' === $kind && $percent <= 0 ) ) {
				/* translators: %s: bonus name. */
				return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( '"%s": enter the amount or percentage it pays.', 'radius-hotel-booking-pro' ), $label ) );
			}
			$applies   = 'selected' === ( $row['applies'] ?? '' ) ? 'selected' : 'all';
			$employees = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $row['employees'] ?? array() ) ) ) ) );
			if ( 'selected' === $applies && ! $employees ) {
				/* translators: %s: bonus name. */
				return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( '"%s": choose the employees it applies to.', 'radius-hotel-booking-pro' ), $label ) );
			}
			// A stable key, so a line's bonus keeps its name when the list is reordered.
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			$key = '' !== $key && ! isset( $keys[ $key ] ) ? $key : 'bonus_' . substr( md5( $label . $index . microtime() ), 0, 8 );
			$keys[ $key ] = true;
			$clean[]      = array(
				'key'       => $key,
				'label'     => $label,
				'kind'      => $kind,
				'amount'    => round( $amount, 2 ),
				'percent'   => round( $percent, 4 ),
				'taxable'   => ! empty( $row['taxable'] ),
				'applies'   => $applies,
				'employees' => 'selected' === $applies ? $employees : array(),
				'active'    => ! array_key_exists( 'active', $row ) || ! empty( $row['active'] ),
			);
		}
		return $clean;
	}

	/**
	 * The bonuses one employee gets on a pay date, ready for the calculator:
	 * `[ { key, label, kind: fixed|percent, value, taxable } ]`.
	 *
	 * @param int    $employee_id Employee id.
	 * @param string $pay_date    `Y-m-d`.
	 * @return array[]
	 */
	public static function forEmployee( int $employee_id, string $pay_date ): array {
		$out = array();
		foreach ( (array) PayrollSettings::get( 'bonusRules', array() ) as $rule ) {
			if ( empty( $rule['active'] ) && array_key_exists( 'active', (array) $rule ) ) {
				continue;
			}
			if ( 'selected' === ( $rule['applies'] ?? 'all' ) && ! in_array( $employee_id, array_map( 'intval', (array) ( $rule['employees'] ?? array() ) ), true ) ) {
				continue;
			}
			$kind = (string) ( $rule['kind'] ?? 'fixed' );
			if ( 'fixed' === $kind ) {
				$resolved = array(
					'kind'  => 'fixed',
					'value' => (string) ( $rule['amount'] ?? 0 ),
				);
			} elseif ( 'percent' === $kind ) {
				$resolved = array(
					'kind'  => 'percent',
					'value' => (string) ( $rule['percent'] ?? 0 ),
				);
			} else {
				/**
				 * Resolve an add-on's bonus kind for one employee: return
				 * `{ kind: fixed|percent, value, label? }`, or null when it does
				 * not apply to them (yet).
				 *
				 * @param array|null $resolved    Null.
				 * @param array      $rule        The rule.
				 * @param int        $employee_id Employee id.
				 * @param string     $pay_date    `Y-m-d`.
				 */
				$resolved = apply_filters( 'rtbp_pro_payroll_bonus_resolve', null, $rule, $employee_id, $pay_date );
			}
			if ( ! is_array( $resolved ) || ! in_array( $resolved['kind'] ?? '', array( 'fixed', 'percent' ), true ) || (float) ( $resolved['value'] ?? 0 ) <= 0 ) {
				continue;
			}
			$out[] = array(
				'key'     => (string) $rule['key'],
				'label'   => (string) ( $resolved['label'] ?? $rule['label'] ),
				'kind'    => (string) $resolved['kind'],
				'value'   => (string) $resolved['value'],
				'taxable' => ! empty( $rule['taxable'] ),
			);
		}
		return $out;
	}
}

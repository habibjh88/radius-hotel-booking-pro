<?php
/**
 * Which statutory calculator payroll uses.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory\Calculator;
use RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory\RuleCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * The built-in RuleCalculator, unless a country add-on supplies its own
 * through `rtbp_pro_payroll_calculator`.
 */
final class Statutory {

	/**
	 * Pay intervals → the monthly factor (monthly amount = per-period amount × factor).
	 */
	public const INTERVALS = array(
		'weekly'       => '52/12',
		'biweekly'     => '26/12',
		'twicemonthly' => '2',
		'monthly'      => '1',
	);

	/**
	 * The calculator in use.
	 *
	 * @return Calculator
	 */
	public static function calculator(): Calculator {
		/**
		 * The payroll statutory calculator (taxes and social contributions).
		 * A country add-on returns its own Calculator here.
		 *
		 * @param Calculator $calculator The built-in RuleCalculator.
		 */
		$calculator = apply_filters( 'rtbp_pro_payroll_calculator', new RuleCalculator() );
		return $calculator instanceof Calculator ? $calculator : new RuleCalculator();
	}

	/**
	 * Whether the built-in calculator is the one in use (Settings → Payroll
	 * then shows its rates).
	 *
	 * @return bool
	 */
	public static function builtIn(): bool {
		return self::calculator() instanceof RuleCalculator;
	}
}

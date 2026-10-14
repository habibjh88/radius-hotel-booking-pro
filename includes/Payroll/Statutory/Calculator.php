<?php
/**
 * A payroll statutory calculator.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory;

defined( 'ABSPATH' ) || exit;

/**
 * Works out one employee's taxes and social contributions for one pay
 * period. Pro ships RuleCalculator (rates and brackets set in Settings →
 * Payroll); a country add-on supplies its own through the
 * `rtbp_pro_payroll_calculator` filter, usually by extending
 * AbstractCalculator.
 */
interface Calculator {

	/**
	 * A stable key (`rules`, `ci` …), stored with each run line.
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * A name for people (Settings → Payroll shows which calculator is used).
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Who receives the tax withheld on one-off additions.
	 *
	 * @return string
	 */
	public function taxAuthority(): string;

	/**
	 * Decimals every payroll amount is rounded to, or null for the site
	 * currency's (Settings → General). A country whose law counts in whole
	 * units (CFA francs) returns 0 so payslips stay exact whatever the site's
	 * display decimals.
	 *
	 * @return int|null
	 */
	public function places(): ?int;

	/**
	 * Calculate.
	 *
	 * @param array $input `{ gross, interval, pay_date (Y-m-d), pre_tax?: [ { key, label, amount,
	 *                     employer_amount? } ], post_tax?: [ { key, label, amount } ] }` — per period.
	 * @return array `{ table_version, interval, gross, pre_tax, pre_tax_total, employer_deductions_total,
	 *               taxable, monthly_taxable, lines: { KEY: { code, authority, side, kind, label, base,
	 *               rate, amount } }, employee_total, employer_total, post_tax, post_tax_total, net }`.
	 */
	public function calculate( array $input ): array;
}

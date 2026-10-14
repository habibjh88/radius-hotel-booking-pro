<?php
/**
 * The built-in statutory calculator.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory;

use RadiusTheme\RadiusHotelBookingPro\Payroll\Decimal;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayrollSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Contributions and income tax as the hotel sets them in Settings → Payroll,
 * for any country:
 *
 * - each contribution: rate × the monthly taxable pay, up to its ceiling,
 *   paid by the employee or the hotel;
 * - income tax (when on): the bracket scale on the monthly taxable pay,
 *   less the employee contributions when so set.
 *
 * Nothing set: no lines, so net = gross less the deductions. The settings
 * can be passed in (tests, previews) instead of read.
 */
final class RuleCalculator extends AbstractCalculator {

	/**
	 * Settings, or null to read them.
	 *
	 * @var array|null
	 */
	private ?array $settings;

	/**
	 * Constructor.
	 *
	 * @param array|null $settings The `payroll` section's values.
	 */
	public function __construct( ?array $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'rules';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Rates set in Settings → Payroll', 'radius-hotel-booking-pro' );
	}

	/**
	 * Tax authority.
	 *
	 * @return string
	 */
	public function taxAuthority(): string {
		$authority = (string) $this->setting( 'taxAuthority', '' );
		return '' !== $authority ? $authority : __( 'Tax office', 'radius-hotel-booking-pro' );
	}

	/**
	 * The lines.
	 *
	 * @param string $monthly Monthly taxable pay.
	 * @param array  $input   Input.
	 * @return array
	 */
	protected function monthlyLines( string $monthly, array $input ): array {
		$lines    = array();
		$employee = '0';
		foreach ( (array) $this->setting( 'contributions', array() ) as $row ) {
			$cap  = Decimal::of( (string) ( $row['cap'] ?? '0' ) );
			$base = Decimal::cmp( $cap, '0' ) > 0 ? Decimal::min( $monthly, $cap ) : $monthly;
			$rate = Decimal::div( Decimal::of( (string) ( $row['rate'] ?? '0' ) ), '100' );
			$side = 'employer' === ( $row['side'] ?? '' ) ? 'employer' : 'employee';
			$amt  = Decimal::mul( $base, $rate );
			if ( 'employee' === $side ) {
				$employee = Decimal::add( $employee, $amt );
			}
			$lines[ strtoupper( $side ) . '_' . (string) $row['code'] ] = array(
				'code'      => (string) $row['code'],
				'authority' => (string) ( $row['authority'] ?? '' ),
				'side'      => $side,
				'kind'      => 'contribution',
				'label'     => (string) ( $row['label'] ?? '' ),
				'base'      => $base,
				'rate'      => $rate,
				'amount'    => $amt,
			);
		}

		$brackets = (array) $this->setting( 'taxBrackets', array() );
		if ( $this->setting( 'incomeTax', false ) && $brackets ) {
			$base  = $this->setting( 'taxAfterContributions', true ) ? Decimal::max( '0', Decimal::sub( $monthly, $employee ) ) : $monthly;
			$scale = array();
			foreach ( $brackets as $bracket ) {
				$scale[] = array(
					'' === (string) ( $bracket['upTo'] ?? '' ) ? null : (string) $bracket['upTo'],
					Decimal::div( Decimal::of( (string) ( $bracket['rate'] ?? '0' ) ), '100' ),
				);
			}
			$tax            = self::scale( $base, $scale, (string) $this->setting( 'taxMode', 'progressive' ) );
			$label          = (string) $this->setting( 'incomeTaxLabel', '' );
			$lines['TAX']   = array(
				'code'      => 'INCOME_TAX',
				'authority' => $this->taxAuthority(),
				'side'      => 'employee',
				'kind'      => 'tax',
				'label'     => '' !== $label ? $label : __( 'Income tax', 'radius-hotel-booking-pro' ),
				'base'      => $base,
				'rate'      => $tax['rate'],
				'amount'    => $tax['amount'],
			);
		}

		return array(
			// The settings' fingerprint: a past line shows which rates it used.
			'version' => substr( md5( (string) wp_json_encode( array( $this->setting( 'contributions', array() ), $brackets, $this->setting( 'taxMode', '' ), $this->setting( 'taxAfterContributions', true ), $this->setting( 'incomeTax', false ) ) ) ), 0, 8 ),
			'lines'   => $lines,
		);
	}

	/**
	 * A setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private function setting( string $key, $default ) {
		if ( null !== $this->settings ) {
			return $this->settings[ $key ] ?? $default;
		}
		return PayrollSettings::get( $key, $default );
	}
}

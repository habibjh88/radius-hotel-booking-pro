<?php
/**
 * Pricing templates: create, change, delete.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

use RadiusTheme\RadiusHotelBooking\ActivityLog\ChangeDiff;
use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable adjustments (feature 7.11): *increase* or *decrease* by a percent
 * or a fixed amount, or *set* a fixed price. A template used by a rule
 * cannot be deleted; changing it changes every rule that uses it (for new
 * prices only: bookings keep the price they were sold at).
 */
class PricingTemplateService {

	const MODES        = array( 'increase', 'decrease', 'set' );
	const AMOUNT_TYPES = array( 'percent', 'fixed' );

	/**
	 * Templates.
	 *
	 * @var PricingTemplateRepository
	 */
	private PricingTemplateRepository $templates;

	/**
	 * Constructor.
	 *
	 * @param PricingTemplateRepository|null $templates Templates.
	 */
	public function __construct( ?PricingTemplateRepository $templates = null ) {
		$this->templates = $templates ?? new PricingTemplateRepository();
	}

	/**
	 * Every template for the API, with how many rules use it.
	 *
	 * @return array[]
	 */
	public function all(): array {
		$counts = $this->templates->ruleCounts();
		return array_map( fn( PricingTemplate $t ) => self::shape( $t, $counts[ (int) $t->id ] ?? 0 ), $this->templates->ordered() );
	}

	/**
	 * One template, or 404.
	 *
	 * @param int $id Id.
	 * @return PricingTemplate
	 * @throws DomainException When missing.
	 */
	public function get( int $id ): PricingTemplate {
		$template = $this->templates->find( $id );
		if ( ! $template instanceof PricingTemplate ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::notFound( __( 'This pricing template does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $template;
	}

	/**
	 * Create a template.
	 *
	 * @param array $input `{ name, mode, amount_type, amount }`.
	 * @return array The template for the API.
	 * @throws DomainException When the insert did not happen.
	 */
	public function create( array $input ): array {
		$data     = $this->validate( $input, null );
		$template = $this->templates->create( $data );
		if ( ! $template instanceof PricingTemplate || ! $template->id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw new DomainException( 'template_not_saved', __( 'The template could not be saved. Please try again.', 'radius-hotel-booking-pro' ), 500 );
		}

		rtbp_activity(
			'pricing.template_create',
			$template,
			array(
				'after'       => self::audited( $data ),
				/* translators: %s: template name. */
				'description' => sprintf( __( 'Added the pricing template %s', 'radius-hotel-booking-pro' ), $template->name ),
			)
		);

		return self::shape( $template, 0 );
	}

	/**
	 * Change a template (only the fields given).
	 *
	 * @param int   $id    Id.
	 * @param array $input Fields.
	 * @return array The template for the API.
	 */
	public function update( int $id, array $input ): array {
		$template = $this->get( $id );
		$before   = self::audited( $template->toArray() );
		$this->templates->update( $id, $this->validate( $input, $template ) );
		PricingRuleService::flushSteps(); // Rules read their template's adjustment.
		$template = $this->get( $id );

		$diff = ChangeDiff::between( $before, self::audited( $template->toArray() ) );
		if ( $diff['after'] ) {
			rtbp_activity(
				'pricing.template_update',
				$template,
				$diff + array(
					/* translators: %s: template name. */
					'description' => sprintf( __( 'Changed the pricing template %s', 'radius-hotel-booking-pro' ), $template->name ),
				)
			);
		}

		return self::shape( $template, $this->templates->ruleCounts()[ $id ] ?? 0 );
	}

	/**
	 * Delete a template. Refused while a rule uses it.
	 *
	 * @param int $id Id.
	 * @return void
	 * @throws DomainException When in use.
	 */
	public function delete( int $id ): void {
		$template = $this->get( $id );

		Transaction::run(
			function () use ( $template, $id ) {
				if ( ! $this->templates->lock( $id ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
					throw DomainException::notFound( __( 'This pricing template does not exist.', 'radius-hotel-booking-pro' ) );
				}
				$rules = $this->templates->ruleCounts()[ $id ] ?? 0;
				if ( $rules ) {
					$message = sprintf(
						/* translators: 1: template name, 2: number of rules. */
						_n( '%1$s is used by %2$d seasonal rule. Change or delete the rule first.', '%1$s is used by %2$d seasonal rules. Change or delete them first.', $rules, 'radius-hotel-booking-pro' ),
						$template->name,
						$rules
					);
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
					throw DomainException::conflict( 'template_in_use', $message, array( 'rules' => $rules ) );
				}
				$this->templates->delete( $id );
			}
		);

		rtbp_activity(
			'pricing.template_delete',
			$template,
			array(
				'before'      => self::audited( $template->toArray() ),
				/* translators: %s: template name. */
				'description' => sprintf( __( 'Deleted the pricing template %s', 'radius-hotel-booking-pro' ), $template->name ),
			)
		);
	}

	/**
	 * An adjustment in words: "+20 %", "−$5.00", "= $25.00".
	 *
	 * @param string $mode        Mode.
	 * @param string $amount_type Amount type.
	 * @param float  $amount      Amount.
	 * @return string
	 */
	public static function describe( string $mode, string $amount_type, float $amount ): string {
		if ( 'set' === $mode ) {
			/* translators: %s: price. */
			return sprintf( __( '= %s', 'radius-hotel-booking-pro' ), Money::format( $amount ) );
		}
		$sign  = 'decrease' === $mode ? '−' : '+';
		$value = 'percent' === $amount_type
			/* translators: %s: a percentage number. */
			? sprintf( __( '%s %%', 'radius-hotel-booking-pro' ), rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) )
			: Money::format( $amount );
		return $sign . $value;
	}

	/**
	 * Apply an adjustment to a price (legacy semantics; never below 0).
	 *
	 * @param float  $before      Price.
	 * @param string $mode        increase | decrease | set.
	 * @param string $amount_type percent | fixed.
	 * @param float  $amount      Amount.
	 * @return float
	 */
	public static function apply( float $before, string $mode, string $amount_type, float $amount ): float {
		if ( 'set' === $mode ) {
			$after = $amount;
		} else {
			$delta = 'percent' === $amount_type ? $before * $amount / 100 : $amount;
			$after = 'decrease' === $mode ? $before - $delta : $before + $delta;
		}
		return max( 0.0, $after );
	}

	/**
	 * Validate and clean the fields given.
	 *
	 * @param array                $input    Input.
	 * @param PricingTemplate|null $template The template being changed, or null on create.
	 * @return array Clean columns.
	 * @throws DomainException With field errors.
	 */
	private function validate( array $input, ?PricingTemplate $template ): array {
		$current = $template ? $template->toArray() : array();
		$pick    = static fn( $key, $default ) => array_key_exists( $key, $input ) ? $input[ $key ] : ( $current[ $key ] ?? $default );
		$errors  = array();

		$name = sanitize_text_field( (string) $pick( 'name', '' ) );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			$errors['name'] = __( 'Give the template a name of up to 120 characters.', 'radius-hotel-booking-pro' );
		}
		$mode = sanitize_key( (string) $pick( 'mode', 'increase' ) );
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$errors['mode'] = __( 'Choose increase, decrease or set.', 'radius-hotel-booking-pro' );
		}
		// "Set" is always a price, never a percentage.
		$type = 'set' === $mode ? 'fixed' : sanitize_key( (string) $pick( 'amount_type', 'percent' ) );
		if ( ! in_array( $type, self::AMOUNT_TYPES, true ) ) {
			$errors['amount_type'] = __( 'Choose a percentage or a fixed amount.', 'radius-hotel-booking-pro' );
		}
		$amount = filter_var( $pick( 'amount', null ), FILTER_VALIDATE_FLOAT );
		$max    = 'percent' === $type ? ( 'decrease' === $mode ? 100 : 1000 ) : 99999999;
		if ( false === $amount || $amount < 0 || $amount > $max ) {
			$errors['amount'] = 'percent' === $type
				/* translators: %d: the largest percentage. */
				? sprintf( __( 'A percentage from 0 to %d.', 'radius-hotel-booking-pro' ), $max )
				: __( 'An amount of 0 or more.', 'radius-hotel-booking-pro' );
		}

		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::invalid( $errors );
		}
		return array(
			'name'        => $name,
			'mode'        => $mode,
			'amount_type' => $type,
			'amount'      => 'fixed' === $type ? Money::round( $amount ) : round( (float) $amount, 2 ),
		);
	}

	/**
	 * A template for the API.
	 *
	 * @param PricingTemplate $template Template.
	 * @param int             $rules    Rules using it.
	 * @return array
	 */
	public static function shape( PricingTemplate $template, int $rules ): array {
		return array(
			'id'          => (int) $template->id,
			'name'        => (string) $template->name,
			'mode'        => (string) $template->mode,
			'amount_type' => (string) $template->amount_type,
			'amount'      => (float) $template->amount,
			'summary'     => self::describe( (string) $template->mode, (string) $template->amount_type, (float) $template->amount ),
			'rules'       => $rules,
		);
	}

	/**
	 * The fields the activity log compares.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function audited( array $row ): array {
		$out = array_intersect_key( $row, array_flip( array( 'name', 'mode', 'amount_type', 'amount' ) ) );
		if ( isset( $out['amount'] ) ) {
			$out['amount'] = (float) $out['amount'];
		}
		return $out;
	}
}

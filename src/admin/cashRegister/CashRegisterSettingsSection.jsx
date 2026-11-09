/**
 * Settings → Cash register (R3-T7): whether a payment may be taken while the
 * staff member's register is closed. On/off of the register itself is the
 * feature switch under Pro features.
 */
import { __ } from '@wordpress/i18n';
import { SettingsSection, ToggleRow } from '@rtbp/ui';

/**
 * @param {Object}   props          Props from the free Settings screen.
 * @param {Object}   props.value    Section values.
 * @param {Function} props.setField `setField( key )( value )`.
 * @param {Object}   props.errors   Key => server message.
 * @return {JSX.Element} Tab.
 */
export default function CashRegisterSettingsSection( {
	value,
	setField,
	errors,
} ) {
	return (
		<SettingsSection
			title={ __( 'Cash register', 'radius-hotel-booking-pro' ) }
			description={ __(
				'Each staff member opens their register at the start of a shift and closes it at the end with a cash count (Front desk → Cash register).',
				'radius-hotel-booking-pro'
			) }
		>
			<ToggleRow
				label={ __(
					'Require an open register to take a payment',
					'radius-hotel-booking-pro'
				) }
				description={ __(
					'Staff cannot record a payment or refund, or mark a new booking as paid, until they open their register.',
					'radius-hotel-booking-pro'
				) }
				checked={ Boolean( value.requireOpen ) }
				onChange={ setField( 'requireOpen' ) }
				error={ errors.requireOpen }
			/>
		</SettingsSection>
	);
}

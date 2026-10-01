/**
 * Settings → Automatic release (M05 T8): how long after the payment deadline
 * an unpaid booking is released. On/off is the feature switch under Pro
 * features.
 */
import { __ } from '@wordpress/i18n';
import { Field, NumberInput, SettingsSection } from '@rtbp/ui';

/**
 * @param {Object}   props          Props from the free Settings screen.
 * @param {Object}   props.value    Section values.
 * @param {Function} props.setField `setField( key )( value )`.
 * @param {Object}   props.errors   Key => server message.
 * @param {Object}   props.schema   Key => schema.
 * @return {JSX.Element} Tab.
 */
export default function AutoReleaseSettingsSection( {
	value,
	setField,
	errors,
	schema,
} ) {
	return (
		<SettingsSection
			title={ __( 'Automatic release', 'radius-hotel-booking-pro' ) }
			description={ __(
				'An unpaid or partly paid booking is cancelled and its rooms freed once its payment deadline has passed by this long. The guest is e-mailed. Bookings on hold are never released automatically. Checked every 15 minutes.',
				'radius-hotel-booking-pro'
			) }
		>
			<Field
				label={ __(
					'Release after the deadline',
					'radius-hotel-booking-pro'
				) }
				description={ __(
					'0 releases the booking as soon as the deadline passes.',
					'radius-hotel-booking-pro'
				) }
				error={ errors.graceHours }
			>
				<NumberInput
					value={ value.graceHours }
					onChange={ setField( 'graceHours' ) }
					unit={ __( 'hours', 'radius-hotel-booking-pro' ) }
					limits={ schema.graceHours }
				/>
			</Field>
		</SettingsSection>
	);
}

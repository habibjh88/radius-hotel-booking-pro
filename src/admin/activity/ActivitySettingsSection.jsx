/**
 * Settings → Activity log (M14): automatic archiving, purge, page views and
 * the grouping window. Saving needs `activity.archive` (it can switch on the
 * purge), so managers confirm with a PIN.
 */
import { __ } from '@wordpress/i18n';
import {
	Field,
	NumberInput,
	SegmentedControl,
	SettingsSection,
	ToggleRow,
} from '@rtbp/ui';

/**
 * @param {Object}   props          Props from the free Settings screen.
 * @param {Object}   props.value    Section values.
 * @param {Function} props.setField `setField( key )( value )`.
 * @param {Object}   props.errors   Key => server message.
 * @param {Object}   props.schema   Key => schema.
 * @return {JSX.Element} Tab.
 */
export default function ActivitySettingsSection( { value, setField, errors, schema } ) {
	const months = [ 0, 1, 2, 3, 6, 12, 24 ].map( ( n ) => ( {
		value: String( n ),
		label: 0 === n ? __( 'Off', 'radius-hotel-booking-pro' ) : String( n ),
	} ) );

	return (
		<>
			<SettingsSection
				title={ __( 'Archiving', 'radius-hotel-booking-pro' ) }
				description={ __(
					'The log is written to a file you can download. The next run date is shown under Activity log → Archives.',
					'radius-hotel-booking-pro'
				) }
			>
				<Field
					label={ __( 'Archive every (months)', 'radius-hotel-booking-pro' ) }
					error={ errors.archiveEvery }
				>
					<div className="max-w-full overflow-x-auto">
						<SegmentedControl
							label={ __( 'Archive every (months)', 'radius-hotel-booking-pro' ) }
							value={ String( value.archiveEvery ?? 12 ) }
							onChange={ ( next ) => setField( 'archiveEvery' )( Number( next ) ) }
							options={ months }
						/>
					</div>
				</Field>
				<ToggleRow
					label={ __( 'Remove archived entries', 'radius-hotel-booking-pro' ) }
					description={ __(
						'After each archive, entries are removed from the database once the file has been checked. Off by default.',
						'radius-hotel-booking-pro'
					) }
					checked={ value.purgeAfterArchive }
					onChange={ setField( 'purgeAfterArchive' ) }
					error={ errors.purgeAfterArchive }
				/>
			</SettingsSection>

			<SettingsSection
				title={ __( 'Page views', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Which screens staff open. Repeated views of one screen become one line.',
					'radius-hotel-booking-pro'
				) }
			>
				<ToggleRow
					label={ __( 'Record page views', 'radius-hotel-booking-pro' ) }
					checked={ value.logPageViews }
					onChange={ setField( 'logPageViews' ) }
					error={ errors.logPageViews }
				>
					<Field
						label={ __( 'Group repeated views within', 'radius-hotel-booking-pro' ) }
						error={ errors.groupWindow }
					>
						<NumberInput
							value={ value.groupWindow }
							onChange={ setField( 'groupWindow' ) }
							unit={ __( 'seconds', 'radius-hotel-booking-pro' ) }
							limits={ schema.groupWindow }
						/>
					</Field>
				</ToggleRow>
			</SettingsSection>
		</>
	);
}

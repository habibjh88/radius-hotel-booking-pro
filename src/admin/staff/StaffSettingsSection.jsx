/**
 * Settings → Staff: the departments and job titles staff files, contracts
 * and *Add an employee* pick from, and the staff list filters by. Names
 * already on files but not listed can be added in one click.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Field, SettingsSection, TagInput } from '@rtbp/ui';

import { useStaffOptions } from './api';

/**
 * One list with its "in use, not listed" line.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.label    Label.
 * @param {string}   props.help     Help text.
 * @param {string[]} props.value    The list.
 * @param {string[]} props.inUse    Names on staff files.
 * @param {Function} props.onChange Called with the new list.
 * @param {string}   props.error    Server message.
 * @return {JSX.Element} Field.
 */
function NameList( { label, help, value, inUse, onChange, error } ) {
	const list = Array.isArray( value ) ? value : [];
	const lower = list.map( ( name ) => name.toLowerCase() );
	const missing = ( inUse || [] ).filter(
		( name ) => ! lower.includes( name.toLowerCase() )
	);
	return (
		<Field label={ label } description={ help } error={ error }>
			<div className="space-y-2">
				<TagInput
					value={ list }
					onChange={ onChange }
					max={ 100 }
					maxLength={ 100 }
					placeholder={ __(
						'Type a name and press Enter',
						'radius-hotel-booking-pro'
					) }
				/>
				{ missing.length ? (
					<div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
						<span>
							{ sprintf(
								/* translators: %s: names, comma separated. */
								__(
									'On staff files but not listed: %s',
									'radius-hotel-booking-pro'
								),
								missing.join( ', ' )
							) }
						</span>
						<Button
							type="button"
							size="sm"
							variant="outline"
							onClick={ () =>
								onChange( [ ...list, ...missing ] )
							}
						>
							{ __( 'Add them', 'radius-hotel-booking-pro' ) }
						</Button>
					</div>
				) : null }
			</div>
		</Field>
	);
}

/**
 * @param {Object}   props          Props from the free Settings screen.
 * @param {Object}   props.value    Section values.
 * @param {Function} props.setField `setField( key )( value )`.
 * @param {Object}   props.errors   Key => server message.
 * @return {JSX.Element} Tab.
 */
export default function StaffSettingsSection( { value, setField, errors } ) {
	const options = useStaffOptions();
	const inUse = options.data?.in_use || {};
	return (
		<SettingsSection
			title={ __( 'Staff', 'radius-hotel-booking-pro' ) }
			description={ __(
				'The departments and job titles to choose from on staff files and contracts. The staff list can be filtered and sorted by them.',
				'radius-hotel-booking-pro'
			) }
		>
			<NameList
				label={ __( 'Departments', 'radius-hotel-booking-pro' ) }
				help={ __(
					'For example Reception, Housekeeping, Kitchen, Maintenance.',
					'radius-hotel-booking-pro'
				) }
				value={ value.departments }
				inUse={ inUse.departments }
				onChange={ setField( 'departments' ) }
				error={ errors.departments }
			/>
			<NameList
				label={ __( 'Job titles', 'radius-hotel-booking-pro' ) }
				help={ __(
					'For example Receptionist, Supervisor, Cook, Security guard.',
					'radius-hotel-booking-pro'
				) }
				value={ value.jobTitles }
				inUse={ inUse.jobTitles }
				onChange={ setField( 'jobTitles' ) }
				error={ errors.jobTitles }
			/>
		</SettingsSection>
	);
}

/**
 * Date and time fields backed by the free plugin's shadcn pickers
 * (`rtbp.ui.DatePicker` / `TimePicker`). A free plugin too old to publish them
 * gets the browser's native input instead, so nothing breaks.
 *
 *   <DateField value={ values.day } onChange={ ( day ) => set( 'day', day ) } />
 *   <TimeField value={ values.time } onChange={ … } minuteStep={ 15 } />
 *
 * `onChange` receives the new `Y-m-d` / `HH:MM` string, not an event.
 *
 * PhoneField is the free plugin's phone input (`rtbp.ui.PhoneInput`: country
 * flag, calling code, validation; value in E.164), or a tel input with an
 * older free plugin.
 */
import { Suspense } from 'react';
import { DatePicker, Input, PhoneInput, TimePicker } from '@rtbp/ui';

/**
 * @param {Object}   props          Props.
 * @param {Object}   props.Picker   The free plugin's picker, if published.
 * @param {string}   props.type     Native fallback type ('date' | 'time').
 * @param {string}   props.value    Value.
 * @param {Function} props.onChange Called with the new value.
 * @return {JSX.Element} Field.
 */
function PickerField( { Picker, type, value, onChange, ...props } ) {
	const {
		clearable,
		yearDropdown,
		minuteStep,
		placeholder,
		align,
		...native
	} = props;

	if ( ! Picker ) {
		return (
			<Input
				type={ type }
				step={ minuteStep ? minuteStep * 60 : undefined }
				value={ value ?? '' }
				onChange={ ( e ) => onChange?.( e.target.value ) }
				{ ...native }
			/>
		);
	}

	return (
		<Suspense
			fallback={
				<Input
					value={ value ?? '' }
					readOnly
					disabled
					className={ native.className }
				/>
			}
		>
			<Picker value={ value ?? '' } onChange={ onChange } { ...props } />
		</Suspense>
	);
}

export const DateField = ( props ) => (
	<PickerField Picker={ DatePicker } type="date" { ...props } />
);

export const TimeField = ( props ) => (
	<PickerField Picker={ TimePicker } type="time" { ...props } />
);

/**
 * @param {Object}   props          Props.
 * @param {string}   props.value    E.164 phone.
 * @param {Function} props.onChange Called with the new value.
 * @return {JSX.Element} Field.
 */
export function PhoneField( { value, onChange, ...props } ) {
	const native = (
		<Input
			type="tel"
			inputMode="tel"
			value={ value ?? '' }
			onChange={ ( e ) => onChange?.( e.target.value ) }
			autoComplete="off"
			{ ...props }
		/>
	);
	if ( ! PhoneInput ) {
		return native;
	}
	return (
		<Suspense fallback={ native }>
			<PhoneInput
				value={ value ?? '' }
				onChange={ onChange }
				{ ...props }
			/>
		</Suspense>
	);
}

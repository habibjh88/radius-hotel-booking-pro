/**
 * A department or job title, picked from Settings → Staff. The value is the
 * name itself (staff files keep text), so a name not in the list — typed
 * before the list existed, or imported — still shows and stays chosen. With
 * an empty list it is a plain text box, as before.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Input,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
} from '@rtbp/ui';

import { useStaffOptions } from './api';

const NONE = '__none__';

/**
 * @param {Object}   props          Props.
 * @param {string}   props.list     `departments` or `job_titles`.
 * @param {string}   props.value    The name ('' for none).
 * @param {Function} props.onChange Called with the new name.
 * @param {boolean}  props.disabled Read-only.
 * @param {string}   props.id       Id (set by Field).
 * @return {JSX.Element} Picker.
 */
export default function NameSelect( { list, value, onChange, disabled, id } ) {
	const options = useStaffOptions();
	const names = options.data?.[ list ] || [];
	const current = String( value || '' );

	if ( ! options.isPending && ! names.length ) {
		return (
			<Input
				id={ id }
				value={ current }
				maxLength={ 100 }
				disabled={ disabled }
				autoComplete="off"
				onChange={ ( e ) => onChange( e.target.value ) }
			/>
		);
	}
	const listed = names.includes( current );
	return (
		<Select
			value={ current || NONE }
			onValueChange={ ( next ) => onChange( NONE === next ? '' : next ) }
			disabled={ disabled || options.isPending }
		>
			<SelectTrigger id={ id }>
				<SelectValue />
			</SelectTrigger>
			<SelectContent className="rtbp-root">
				<SelectItem value={ NONE }>
					{ __( 'Not set', 'radius-hotel-booking-pro' ) }
				</SelectItem>
				{ current && ! listed ? (
					<SelectItem value={ current }>
						{ sprintf(
							/* translators: %s: a name not in the Settings list. */
							__(
								'%s (not in Settings)',
								'radius-hotel-booking-pro'
							),
							current
						) }
					</SelectItem>
				) : null }
				{ names.map( ( name ) => (
					<SelectItem key={ name } value={ name }>
						{ name }
					</SelectItem>
				) ) }
			</SelectContent>
		</Select>
	);
}

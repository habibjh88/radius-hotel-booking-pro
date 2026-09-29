/**
 * A pricing rule's scope: which room types and rate plans it applies to
 * (none ticked = all), as checkboxes and in words.
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';

/**
 * "All room types", "Standard, VIP", … for a scope id list.
 *
 * @param {number[]} ids     Ids (empty = all).
 * @param {Object[]} options `{ id, name }`.
 * @param {string}   all     Text for "all".
 * @return {string} Text.
 */
export const scopeText = ( ids, options, all ) =>
	! ids.length
		? all
		: ids
				.map(
					( id ) =>
						options.find( ( o ) => o.id === id )?.name ?? `#${ id }`
				)
				.join( ', ' );

/**
 * A checkbox list for a rule's scope: none checked = all.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.legend   Legend.
 * @param {string}   props.allText  Shown when nothing is checked.
 * @param {Object[]} props.options  `{ id, name }`.
 * @param {number[]} props.value    Checked ids.
 * @param {Function} props.onChange Called with the ids.
 * @param {string}   props.error    Error.
 * @return {JSX.Element} Fieldset.
 */
export function ScopePicker( {
	legend,
	allText,
	options,
	value,
	onChange,
	error,
	ready = true,
} ) {
	// Ids of room types / plans deleted since the rule was saved: drop them
	// (the server refuses them) and tell the user what that means.
	const [ dropped, setDropped ] = useState( 0 );
	useEffect( () => {
		if ( ! ready ) {
			return;
		}
		const live = value.filter( ( id ) =>
			options.some( ( o ) => o.id === id )
		);
		if ( live.length !== value.length ) {
			setDropped( value.length - live.length );
			onChange( live );
		}
		// Only when the options arrive: a later untick is the user's choice.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ ready ] );

	const toggle = ( id ) =>
		onChange(
			value.includes( id )
				? value.filter( ( v ) => v !== id )
				: [ ...value, id ]
		);
	return (
		<fieldset className="m-0 space-y-2 border-0 p-0">
			<legend className="mb-1 p-0 text-sm font-semibold text-heading">
				{ legend }
			</legend>
			<p className="m-0 text-xs text-muted-foreground">
				{ value.length ? '' : allText }
				{ dropped && ! value.length
					? ' ' +
					  __(
							'Some it applied to were deleted: tick the ones it should cover, or it will cover all.',
							'radius-hotel-booking-pro'
					  )
					: '' }
			</p>
			<div className="grid gap-1.5 sm:grid-cols-2">
				{ options.map( ( option ) => (
					<label
						key={ option.id }
						className="flex items-center gap-2 text-sm text-heading"
					>
						<input
							type="checkbox"
							className="accent-primary"
							checked={ value.includes( option.id ) }
							onChange={ () => toggle( option.id ) }
						/>
						{ option.name }
					</label>
				) ) }
			</div>
			{ error ? (
				<p role="alert" className="m-0 text-xs text-destructive">
					{ error }
				</p>
			) : null }
		</fieldset>
	);
}

/**
 * "All room types · Overnight" for a rule.
 *
 * @param {Object} rule  Rule (`room_type_ids`, `rate_plan_ids`).
 * @param {Object} scope `{ roomTypes, ratePlans }`.
 * @return {string} Text.
 */
export const describeScope = ( rule, scope ) =>
	[
		scopeText(
			rule.room_type_ids,
			scope.roomTypes,
			__( 'All room types', 'radius-hotel-booking-pro' )
		),
		scopeText(
			rule.rate_plan_ids,
			scope.ratePlans,
			__( 'all rate plans', 'radius-hotel-booking-pro' )
		),
	].join( ' · ' );

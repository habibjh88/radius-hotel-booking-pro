/**
 * Profile-field control (features 13.11, 17.6): Allowed / Restricted per
 * field, with an optional "not set" choice (Default / Inherit) and the value
 * that applies now.
 */
import { __, sprintf } from '@wordpress/i18n';
import { SegmentedControl } from '@rtbp/ui';

/**
 * @param {Object}   props            Props.
 * @param {Array}    props.fields     `[ { key, label, default } ]`.
 * @param {Object}   props.value      Field => allow | restrict (missing = not set).
 * @param {Function} props.onChange   Called with ( field, value ); '' = not set.
 * @param {string}   props.unsetLabel Label of "not set"; omit to require a value.
 * @param {Object}   props.fallback   Field => value shown when not set (no unset option).
 * @param {Object}   props.effective  Field => `{ value, source }` shown as "Now".
 * @return {JSX.Element} List.
 */
export default function ProfileFieldsControl( {
	fields,
	value,
	onChange,
	unsetLabel,
	fallback,
	effective,
} ) {
	const options = [
		...( unsetLabel ? [ { value: '', label: unsetLabel } ] : [] ),
		{ value: 'allow', label: __( 'Allowed', 'radius-hotel-booking-pro' ) },
		{
			value: 'restrict',
			label: __( 'Restricted', 'radius-hotel-booking-pro' ),
		},
	];
	const sources = {
		override: __( 'Personal override', 'radius-hotel-booking-pro' ),
		role: __( 'Access role', 'radius-hotel-booking-pro' ),
		settings: __( 'Default', 'radius-hotel-booking-pro' ),
		default: __( 'Default', 'radius-hotel-booking-pro' ),
		admin: __( 'Administrator', 'radius-hotel-booking-pro' ),
	};

	return (
		<ul className="m-0 list-none divide-y divide-border p-0">
			{ fields.map( ( field ) => {
				const now = effective?.[ field.key ];
				return (
					<li
						key={ field.key }
						className="flex flex-col gap-2 py-3 md:flex-row md:items-center md:gap-4"
					>
						<div className="min-w-0 flex-1">
							<span className="text-sm text-heading">
								{ field.label }
							</span>
							{ now ? (
								<span className="mt-0.5 block text-xs text-muted-foreground">
									{ sprintf(
										/* translators: 1: Allowed or Restricted, 2: where it comes from. */
										__( 'Now: %1$s · %2$s', 'radius-hotel-booking-pro' ),
										'allow' === now.value
											? __( 'Allowed', 'radius-hotel-booking-pro' )
											: __( 'Restricted', 'radius-hotel-booking-pro' ),
										sources[ now.source ] || now.source
									) }
								</span>
							) : null }
						</div>
						<div className="max-w-full overflow-x-auto">
							<SegmentedControl
								options={ options }
								value={
									value?.[ field.key ] ??
									( unsetLabel
										? ''
										: fallback?.[ field.key ] ?? field.default )
								}
								onChange={ ( next ) => onChange( field.key, next ) }
								label={ field.label }
							/>
						</div>
					</li>
				);
			} ) }
		</ul>
	);
}

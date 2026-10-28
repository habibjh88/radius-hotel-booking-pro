/**
 * Settings → Pro features.
 *
 * Rendered by the free plugin's Settings screen, which owns loading and saving:
 * `value` is the `pro_features` section, `setField( key )( bool )` edits it and
 * `save()` sends `PUT settings/pro_features`. The server re-applies the
 * requirement rules, so this screen only mirrors them for immediate feedback.
 *
 * UI components come from the free plugin (`@rtbp/ui` → `window.rtbp.ui`), so
 * they match the rest of the app and use its compiled styles.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Badge,
	Button,
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
	Switch,
} from '@rtbp/ui';

const data = window.rtbpPro || { features: [], groups: {} };

const STATE_LABELS = {
	active: __( 'Active', 'radius-hotel-booking-pro' ),
	disabled: __( 'Off', 'radius-hotel-booking-pro' ),
	blocked: __( 'Waiting for a required feature', 'radius-hotel-booking-pro' ),
	needs_free: __(
		'Needs a newer Radius Hotel Booking',
		'radius-hotel-booking-pro'
	),
	in_development: __( 'In development', 'radius-hotel-booking-pro' ),
};

/**
 * Label of a feature by key.
 *
 * @param {string} key Feature key.
 * @return {string} Label.
 */
const labelOf = ( key ) =>
	data.features.find( ( feature ) => feature.key === key )?.label || key;

/**
 * One feature row.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.feature   Feature description from PHP.
 * @param {boolean}  props.enabled   Switch state.
 * @param {string[]} props.missing   Required features that are off.
 * @param {Function} props.onChange  Toggle handler.
 * @return {JSX.Element} Row.
 */
function FeatureRow( { feature, enabled, missing, onChange } ) {
	const id = `rtbp-pro-feature-${ feature.key }`;

	return (
		<div className="flex items-center justify-between gap-3 rounded-md border border-border p-3">
			<div className="min-w-0 flex-1 space-y-1">
				<div className="flex flex-wrap items-center gap-2">
					<label htmlFor={ id } className="m-0 text-sm font-medium">
						{ feature.label }
					</label>
					{ feature.state !== 'active' &&
					feature.state !== 'disabled' ? (
						<Badge
							variant="outline"
							className="text-muted-foreground"
						>
							{ STATE_LABELS[ feature.state ] || feature.state }
						</Badge>
					) : null }
				</div>
				<p className="m-0 text-xs text-muted-foreground">
					{ feature.description }
				</p>
				{ missing.length ? (
					<p className="m-0 text-xs text-muted-foreground">
						{ sprintf(
							/* translators: %s: comma-separated feature names. */
							__( 'Requires: %s', 'radius-hotel-booking-pro' ),
							missing.map( labelOf ).join( ', ' )
						) }
					</p>
				) : null }
			</div>
			<Switch
				id={ id }
				className="shrink-0"
				checked={ enabled }
				disabled={ missing.length > 0 }
				onCheckedChange={ onChange }
			/>
		</div>
	);
}

/**
 * The section.
 *
 * @param {Object}   props          Props from the free Settings screen.
 * @param {Object}   props.value    Current on/off map.
 * @param {Function} props.setField Field setter factory.
 * @param {Function} props.save     Saves the section.
 * @param {boolean}  props.saving   Whether a save is in flight.
 * @return {JSX.Element} Section.
 */
export default function ProFeaturesSection( {
	value,
	setField,
	save,
	saving,
} ) {
	const isOn = ( key ) => !! value?.[ key ];

	const missingFor = ( feature ) =>
		( feature.requires || [] ).filter( ( key ) => ! isOn( key ) );

	// Switching a feature off also switches off everything that requires it,
	// mirroring the server-side rule.
	const toggle = ( key, next ) => {
		setField( key )( next );
		if ( next ) {
			return;
		}
		data.features
			.filter( ( feature ) => ( feature.requires || [] ).includes( key ) )
			.forEach( ( feature ) => toggle( feature.key, false ) );
	};

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Pro features', 'radius-hotel-booking-pro' ) }
				</CardTitle>
				<CardDescription>
					{ __(
						'Switch each Pro feature on or off. A feature that is off adds nothing to the site, and its data is kept for when you switch it back on. Changes apply on the next page load.',
						'radius-hotel-booking-pro'
					) }
				</CardDescription>
			</CardHeader>
			<CardContent className="space-y-4">
				{ Object.entries( data.groups ).map(
					( [ group, groupLabel ] ) => {
						const features = data.features.filter(
							( feature ) => feature.group === group
						);

						if ( ! features.length ) {
							return null;
						}

						return (
							<section key={ group } className="space-y-2">
								<h3 className="m-0 text-sm font-semibold">
									{ groupLabel }
								</h3>
								{ features.map( ( feature ) => (
									<FeatureRow
										key={ feature.key }
										feature={ feature }
										enabled={ isOn( feature.key ) }
										missing={ missingFor( feature ) }
										onChange={ ( next ) =>
											toggle( feature.key, next )
										}
									/>
								) ) }
							</section>
						);
					}
				) }

				<Button disabled={ saving } onClick={ save }>
					{ saving
						? __( 'Saving…', 'radius-hotel-booking-pro' )
						: __( 'Save changes', 'radius-hotel-booking-pro' ) }
				</Button>
			</CardContent>
		</Card>
	);
}

/**
 * A grouped list of access keys, each with a level control. Used by the role
 * editor (Default / Open / Passcode / Locked) and the staff page (Inherit /
 * … with the effective level and its source next to it).
 */
import { useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Input, SegmentedControl, SettingsSection } from '@rtbp/ui';

/**
 * @param {Object}   props           Props.
 * @param {Object}   props.registry  `{ groups, keys }` from `access/registry`.
 * @param {Object}   props.value     Key => level ('' or missing = not set).
 * @param {Function} props.onChange  Called with ( key, level ); '' = not set.
 * @param {Array}    props.options   Segmented options, '' first.
 * @param {Function} props.aside     Optional ( key ) => node shown under the label.
 * @return {JSX.Element} Matrix.
 */
export default function LevelMatrix( {
	registry,
	value,
	onChange,
	options,
	aside,
} ) {
	const [ search, setSearch ] = useState( '' );
	const needle = search.trim().toLowerCase();

	const groups = useMemo(
		() =>
			registry.groups
				.map( ( group ) => ( {
					...group,
					keys: registry.keys.filter(
						( key ) =>
							key.group === group.key &&
							( ! needle ||
								key.label.toLowerCase().includes( needle ) ||
								key.key.includes( needle ) )
					),
				} ) )
				.filter( ( group ) => group.keys.length ),
		[ registry, needle ]
	);

	return (
		<div className="space-y-4">
			<Input
				type="search"
				value={ search }
				onChange={ ( event ) => setSearch( event.target.value ) }
				placeholder={ __(
					'Find a page or action',
					'radius-hotel-booking-pro'
				) }
				aria-label={ __(
					'Find a page or action',
					'radius-hotel-booking-pro'
				) }
				className="max-w-xs"
			/>
			{ groups.map( ( group ) => (
				<SettingsSection key={ group.key } title={ group.label }>
					<ul className="m-0 list-none divide-y divide-border p-0">
						{ group.keys.map( ( key ) => (
							<li
								key={ key.key }
								className="flex flex-col gap-2 py-3 md:flex-row md:items-center md:gap-4"
							>
								<div className="min-w-0 flex-1">
									<span className="text-sm text-heading">
										{ key.label }
									</span>
									{ aside ? aside( key ) : null }
								</div>
								<div className="max-w-full overflow-x-auto">
									<SegmentedControl
										options={ options }
										value={ value?.[ key.key ] ?? '' }
										onChange={ ( level ) =>
											onChange( key.key, level )
										}
										label={ sprintf(
											/* translators: %s: a page or action. */
											__(
												'Level for %s',
												'radius-hotel-booking-pro'
											),
											key.label
										) }
									/>
								</div>
							</li>
						) ) }
					</ul>
				</SettingsSection>
			) ) }
		</div>
	);
}

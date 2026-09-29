/**
 * Create or edit an access role: name, description, "everything else", and a
 * level per key (Default = not set: the global default applies).
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import {
	Badge,
	Button,
	Field,
	Input,
	SegmentedControl,
	SettingsSection,
	Textarea,
} from '@rtbp/ui';

import LevelMatrix from './LevelMatrix';
import { withUnset } from './levels';
import ProfileFieldsControl from './ProfileFieldsControl';
import { useProfileFields, useSaveRole } from './api';

/**
 * @param {Object}   props          Props.
 * @param {Object}   props.role     Role, or null for a new one.
 * @param {Object}   props.registry `access/registry`.
 * @param {Function} props.onDone   Called after saving or cancelling.
 * @return {JSX.Element} Editor.
 */
export default function RoleEditor( { role, registry, onDone } ) {
	const [ name, setName ] = useState( role?.name ?? '' );
	const [ description, setDescription ] = useState(
		role?.description ?? ''
	);
	const [ levels, setLevels ] = useState( { ...( role?.levels ?? {} ) } );
	const [ profileFields, setProfileFields ] = useState( {
		...( role?.profile_fields ?? {} ),
	} );
	const fields = useProfileFields();
	const [ errors, setErrors ] = useState( {} );
	const save = useSaveRole();

	useEffect( () => setErrors( {} ), [ name ] );

	const setLevel = ( key, level ) =>
		setLevels( ( current ) => {
			const next = { ...current };
			if ( level ) {
				next[ key ] = level;
			} else {
				delete next[ key ];
			}
			return next;
		} );

	const submit = () =>
		save.mutate(
			{
				id: role?.id,
				name,
				description,
				levels,
				profile_fields: profileFields,
			},
			{
				onSuccess: onDone,
				onError: ( error ) =>
					setErrors(
						Object.fromEntries(
							Object.entries( error.errors || {} ).map(
								( [ field, detail ] ) => [
									field,
									detail?.first_message || '',
								]
							)
						)
					),
			}
		);

	return (
		<div className="space-y-6">
			<SettingsSection
				title={
					role
						? __( 'Edit access role', 'radius-hotel-booking-pro' )
						: __( 'New access role', 'radius-hotel-booking-pro' )
				}
				description={ __(
					'Everyone with this role gets these permissions. A key left on Default follows Settings → Permissions.',
					'radius-hotel-booking-pro'
				) }
				actions={
					role?.is_template ? (
						<Badge variant="outline">
							{ __( 'Template', 'radius-hotel-booking-pro' ) }
						</Badge>
					) : null
				}
			>
				<Field
					label={ __( 'Name', 'radius-hotel-booking-pro' ) }
					error={ errors.name }
					required
				>
					<Input
						value={ name }
						maxLength={ 100 }
						onChange={ ( event ) => setName( event.target.value ) }
					/>
				</Field>
				<Field
					label={ __( 'Description', 'radius-hotel-booking-pro' ) }
				>
					<Textarea
						rows={ 2 }
						maxLength={ 500 }
						value={ description }
						onChange={ ( event ) =>
							setDescription( event.target.value )
						}
					/>
				</Field>
				<Field
					label={ __( 'Everything else', 'radius-hotel-booking-pro' ) }
					description={ __(
						'The level of every page and action this role does not set below, including ones added later.',
						'radius-hotel-booking-pro'
					) }
				>
					<div>
						<SegmentedControl
							label={ __(
								'Everything else',
								'radius-hotel-booking-pro'
							) }
							options={ withUnset(
								__( 'Default', 'radius-hotel-booking-pro' )
							) }
							value={ levels[ '*' ] ?? '' }
							onChange={ ( level ) => setLevel( '*', level ) }
						/>
					</div>
				</Field>
			</SettingsSection>

			<LevelMatrix
				registry={ registry }
				value={ levels }
				onChange={ setLevel }
				options={ withUnset(
					__( 'Default', 'radius-hotel-booking-pro' )
				) }
			/>

			{ fields.data ? (
				<SettingsSection
					title={ __( 'Profile fields', 'radius-hotel-booking-pro' ) }
					description={ __(
						'Which fields people with this role may change on their own profile. Default follows Settings → Passcodes.',
						'radius-hotel-booking-pro'
					) }
				>
					<ProfileFieldsControl
						fields={ fields.data.fields }
						value={ profileFields }
						unsetLabel={ __( 'Default', 'radius-hotel-booking-pro' ) }
						onChange={ ( key, next ) =>
							setProfileFields( ( current ) => {
								const updated = { ...current };
								if ( next ) {
									updated[ key ] = next;
								} else {
									delete updated[ key ];
								}
								return updated;
							} )
						}
					/>
				</SettingsSection>
			) : null }

			<div className="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-border bg-background py-3">
				<Button
					variant="outline"
					onClick={ onDone }
					disabled={ save.isPending }
				>
					{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
				</Button>
				<Button onClick={ submit } disabled={ save.isPending }>
					{ save.isPending
						? __( 'Saving…', 'radius-hotel-booking-pro' )
						: __( 'Save role', 'radius-hotel-booking-pro' ) }
				</Button>
			</div>
		</div>
	);
}

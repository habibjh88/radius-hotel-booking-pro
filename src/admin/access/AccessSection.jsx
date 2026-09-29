/**
 * Settings → Passcodes (M13 T4b, features 13.7–13.10, 17.4).
 *
 * Rendered by the free Settings screen, which loads and saves the
 * `pro_access` section. The fallback PIN is write-only: it is never loaded,
 * and it is saved on its own with `POST access/fallback-pin`.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
	Badge,
	Button,
	Field,
	Input,
	NumberInput,
	SegmentedControl,
	SettingsSection,
	Textarea,
	ToggleRow,
} from '@rtbp/ui';
import { api, toast, toastError } from '@rtbp/lib';

import ProfileFieldsControl from './ProfileFieldsControl';
import { useProfileFields } from './api';

const STATUS_KEY = [ 'access', 'passcode' ];

/**
 * The fallback PIN: set, replace or remove. Never shown.
 *
 * @return {JSX.Element} Control.
 */
function FallbackPin() {
	const queryClient = useQueryClient();
	const [ pin, setPin ] = useState( '' );
	const status = useQuery( {
		queryKey: STATUS_KEY,
		queryFn: () => api.get( 'access/passcode' ).then( ( r ) => r.data ),
	} );
	const save = useMutation( {
		mutationFn: ( value ) =>
			api.post( 'access/fallback-pin', { pin: value } ),
		onSuccess: ( { message } ) => {
			setPin( '' );
			toast.success( message );
			queryClient.invalidateQueries( { queryKey: STATUS_KEY } );
		},
		// Not `onError: toastError`: its second argument would be the PIN.
		onError: ( error ) => toastError( error ),
	} );

	const isSet = !! status.data?.has_fallback;
	const valid = /^\d{4,8}$/.test( pin );

	return (
		<Field
			label={ __( 'Fallback PIN', 'radius-hotel-booking-pro' ) }
			description={ __(
				'4 to 8 digits. For administrators and staff without a personal PIN. It is stored securely and never shown again.',
				'radius-hotel-booking-pro'
			) }
		>
			<div className="flex flex-wrap items-center gap-2">
				<Input
					type="password"
					inputMode="numeric"
					autoComplete="new-password"
					maxLength={ 8 }
					value={ pin }
					placeholder={ isSet ? '••••' : '' }
					onChange={ ( event ) =>
						setPin( event.target.value.replace( /\D/g, '' ) )
					}
					className="w-32"
				/>
				<Button
					type="button"
					size="sm"
					disabled={ ! valid || save.isPending }
					onClick={ () => save.mutate( pin ) }
				>
					{ isSet
						? __( 'Replace', 'radius-hotel-booking-pro' )
						: __( 'Set PIN', 'radius-hotel-booking-pro' ) }
				</Button>
				{ isSet ? (
					<Button
						type="button"
						size="sm"
						variant="ghost"
						disabled={ save.isPending }
						onClick={ () => save.mutate( null ) }
					>
						{ __( 'Remove', 'radius-hotel-booking-pro' ) }
					</Button>
				) : null }
				{ status.data ? (
					<Badge variant="outline">
						{ isSet
							? __( 'Set', 'radius-hotel-booking-pro' )
							: __( 'Not set', 'radius-hotel-booking-pro' ) }
					</Badge>
				) : null }
			</div>
		</Field>
	);
}

/**
 * The global profile-field defaults (stored partially: a field set back to
 * its code default is removed).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.value    Field => allow | restrict.
 * @param {Function} props.onChange Called with the new map.
 * @param {string}   props.error    Server message.
 * @return {JSX.Element} Control.
 */
function ProfileDefaults( { value, onChange, error } ) {
	const fields = useProfileFields();
	if ( fields.isPending || fields.error ) {
		return null;
	}
	const map = Array.isArray( value ) ? {} : value || {};
	return (
		<>
			<ProfileFieldsControl
				fields={ fields.data.fields }
				value={ map }
				onChange={ ( key, next ) => {
					const code = fields.data.fields.find( ( f ) => f.key === key )?.default;
					const updated = { ...map };
					if ( next === code ) {
						delete updated[ key ];
					} else {
						updated[ key ] = next;
					}
					onChange( updated );
				} }
			/>
			{ error ? (
				<p role="alert" className="m-0 text-sm text-destructive">
					{ error }
				</p>
			) : null }
		</>
	);
}

/**
 * @param {Object}   props          Props from the free Settings screen.
 * @param {Object}   props.value    Section values.
 * @param {Function} props.setField `setField( key )( value )`.
 * @param {Object}   props.errors   Key => server message.
 * @param {Object}   props.schema   Key => schema (limits).
 * @return {JSX.Element} Tab.
 */
export default function AccessSection( { value, setField, errors, schema } ) {
	return (
		<>
			<SettingsSection
				title={ __( 'PIN confirmation', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Pages and actions set to Passcode ask for a PIN before they go through.',
					'radius-hotel-booking-pro'
				) }
			>
				<Field
					label={ __( 'A PIN stays valid for', 'radius-hotel-booking-pro' ) }
					error={ errors.passcodeValidity }
				>
					<NumberInput
						value={ value.passcodeValidity }
						onChange={ setField( 'passcodeValidity' ) }
						unit={ __( 'minutes', 'radius-hotel-booking-pro' ) }
						limits={ schema.passcodeValidity }
					/>
				</Field>

				<Field
					label={ __( 'One PIN covers', 'radius-hotel-booking-pro' ) }
					error={ errors.passcodeScope }
				>
					<div>
						<SegmentedControl
							label={ __( 'One PIN covers', 'radius-hotel-booking-pro' ) }
							value={ value.passcodeScope }
							onChange={ setField( 'passcodeScope' ) }
							options={ [
								{
									value: 'global',
									label: __(
										'Everything',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'per_key',
									label: __(
										'One page or action',
										'radius-hotel-booking-pro'
									),
								},
							] }
						/>
					</div>
				</Field>

				<ToggleRow
					label={ __(
						'Allow the fallback PIN',
						'radius-hotel-booking-pro'
					) }
					description={ __(
						'Staff without a personal PIN, and administrators, may confirm with a shared PIN.',
						'radius-hotel-booking-pro'
					) }
					checked={ value.fallbackEnabled }
					onChange={ setField( 'fallbackEnabled' ) }
					error={ errors.fallbackEnabled }
				>
					<FallbackPin />
				</ToggleRow>
			</SettingsSection>

			<SettingsSection
				title={ __( 'Profile fields', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Which fields staff may change on their own profile. An access role or a person can differ.',
					'radius-hotel-booking-pro'
				) }
			>
				<ProfileDefaults
					value={ value.profileFields }
					onChange={ setField( 'profileFields' ) }
					error={ errors.profileFields }
				/>
			</SettingsSection>

			<SettingsSection
				title={ __( 'Locked message', 'radius-hotel-booking-pro' ) }
				description={ __(
					'What staff see when they reach something they may not use.',
					'radius-hotel-booking-pro'
				) }
			>
				<Field error={ errors.lockedMessage }>
					<Textarea
						rows={ 3 }
						maxLength={ schema.lockedMessage?.maxLength }
						value={ value.lockedMessage ?? '' }
						placeholder={ __(
							'You do not have access to this. Ask a manager if you need it.',
							'radius-hotel-booking-pro'
						) }
						onChange={ ( event ) =>
							setField( 'lockedMessage' )( event.target.value )
						}
					/>
				</Field>
			</SettingsSection>
		</>
	);
}

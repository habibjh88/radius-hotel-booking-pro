/**
 * My profile (`#/me/profile`, M16 16.1, 16.2, 16.5, 16.6), opened from the
 * account menu: the signed-in user's own name, personal details, contact
 * and emergency contact, then password and PIN (16.3, 16.4). The fields the
 * hotel restricts (M13's profile-field map) are read-only with a lock; the
 * server refuses them anyway. Only the changed fields are sent.
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Lock, UserRound } from 'lucide-react';
import {
	Button,
	EmptyState,
	Field,
	Input,
	Panel,
	SettingsSection,
	Skeleton,
	Textarea,
} from '@rtbp/ui';
import { toast, toastError } from '@rtbp/lib';

import { ChoiceSelect } from '../staff/FileTabs';
import { useProfile, useSaveProfile } from './api';
import SecuritySection from './SecuritySection';

/**
 * The form's values from the profile (strings, the emergency contact as an object).
 *
 * @param {Object} values Profile values.
 * @return {Object} Form values.
 */
const toForm = ( values ) =>
	Object.fromEntries(
		Object.entries( values || {} ).map( ( [ key, value ] ) => [
			key,
			'emergency_contact' === key
				? { ...( value || {} ) }
				: String( value ?? '' ),
		] )
	);

/**
 * Initials for the header.
 *
 * @param {string} name Name.
 * @return {string} Up to two letters.
 */
const initials = ( name ) =>
	name
		.split( /\s+/ )
		.filter( Boolean )
		.slice( 0, 2 )
		.map( ( part ) => part[ 0 ].toUpperCase() )
		.join( '' ) || '?';

/**
 * The profile form.
 *
 * @param {Object} props         Props.
 * @param {Object} props.profile Profile from the server.
 * @return {JSX.Element} Form.
 */
function ProfileForm( { profile } ) {
	const save = useSaveProfile();
	const original = toForm( profile.values );
	const stamp = JSON.stringify( original );
	const [ values, setValues ] = useState( original );
	const [ errors, setErrors ] = useState( {} );

	// Follow the profile when it changes (after a save).
	useEffect( () => {
		setValues( JSON.parse( stamp ) );
		setErrors( {} );
	}, [ stamp ] );

	const fields = Object.fromEntries(
		profile.fields.map( ( field ) => [ field.key, field ] )
	);
	const has = ( key ) => !! fields[ key ];
	const editable = ( key ) => !! fields[ key ]?.editable;
	const changed = Object.keys( fields ).filter(
		( key ) =>
			editable( key ) &&
			JSON.stringify( values[ key ] ) !==
				JSON.stringify( original[ key ] )
	);

	const set = ( key, value ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setErrors( ( prev ) => ( { ...prev, [ key ]: undefined } ) );
	};
	const contact = values.emergency_contact || {};
	const setContact = ( part, value ) =>
		set( 'emergency_contact', { ...contact, [ part ]: value } );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! changed.length ) {
			return;
		}
		try {
			await save.mutateAsync(
				Object.fromEntries(
					changed.map( ( key ) => [ key, values[ key ] ] )
				)
			);
			toast.success(
				__( 'Your profile is saved.', 'radius-hotel-booking-pro' )
			);
		} catch ( err ) {
			const mapped = {};
			Object.entries( err?.errors || {} ).forEach( ( [ key, value ] ) => {
				// The file's emergency column is the profile's emergency contact.
				mapped[ key.replace( /^emergency\b/, 'emergency_contact' ) ] =
					value?.first_message;
			} );
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	/**
	 * One field: label (with a lock when restricted), the control, its error.
	 *
	 * @param {string}      key      Profile field.
	 * @param {JSX.Element} control  The control.
	 * @param {Object}      extra    Field props (required, className, label).
	 * @return {JSX.Element|null} Field.
	 */
	const row = ( key, control, extra = {} ) => {
		const base = key.split( '.' )[ 0 ];
		if ( ! has( base ) ) {
			return null;
		}
		const locked = ! editable( base );
		const label = extra.label || fields[ base ].label;
		return (
			<Field
				label={
					locked ? (
						<span className="inline-flex items-center gap-1.5">
							{ label }
							<Lock
								className="h-3.5 w-3.5 text-muted-foreground"
								aria-label={ __(
									'Locked',
									'radius-hotel-booking-pro'
								) }
							/>
						</span>
					) : (
						label
					)
				}
				description={
					locked && ! extra.quiet
						? __(
								'Only a manager can change this.',
								'radius-hotel-booking-pro'
						  )
						: extra.description
				}
				error={ errors[ key ] }
				required={ ! locked && extra.required }
				className={ extra.className }
			>
				{ control( locked ) }
			</Field>
		);
	};
	const text =
		( key, props = {} ) =>
		( locked ) => (
			<Input
				value={ values[ key ] ?? '' }
				onChange={ ( e ) => set( key, e.target.value ) }
				disabled={ locked }
				autoComplete="off"
				{ ...props }
			/>
		);

	const lockedContact = ! editable( 'emergency_contact' );

	return (
		<form className="space-y-4" onSubmit={ submit } noValidate>
			<SettingsSection
				title={ __( 'About you', 'radius-hotel-booking-pro' ) }
			>
				<div className="grid gap-4 sm:grid-cols-2">
					{ row(
						'first_name',
						text( 'first_name', { maxLength: 100 } ),
						{
							required: true,
						}
					) }
					{ row(
						'last_name',
						text( 'last_name', { maxLength: 100 } ),
						{
							required: true,
						}
					) }
					{ row( 'dob', text( 'dob', { type: 'date' } ) ) }
					{ row( 'marital_status', ( locked ) => (
						<ChoiceSelect
							value={ values.marital_status }
							choices={ profile.options?.marital }
							onChange={ ( value ) =>
								set( 'marital_status', value )
							}
							disabled={ locked }
						/>
					) ) }
					{ row(
						'num_children',
						text( 'num_children', {
							type: 'number',
							min: 0,
							max: 30,
							inputMode: 'numeric',
						} )
					) }
				</div>
				{ row( 'address', ( locked ) => (
					<Textarea
						rows={ 3 }
						value={ values.address ?? '' }
						onChange={ ( e ) => set( 'address', e.target.value ) }
						disabled={ locked }
					/>
				) ) }
			</SettingsSection>

			<SettingsSection
				title={ __( 'Contact', 'radius-hotel-booking-pro' ) }
			>
				<div className="grid gap-4 sm:grid-cols-2">
					{ row( 'phone', text( 'phone', { type: 'tel' } ), {
						required: true,
					} ) }
					{ row( 'email', text( 'email', { type: 'email' } ), {
						description: __(
							'Also the e-mail of your account: password links go here.',
							'radius-hotel-booking-pro'
						),
					} ) }
				</div>
			</SettingsSection>

			{ has( 'emergency_contact' ) ? (
				<SettingsSection
					title={ fields.emergency_contact.label }
					description={
						lockedContact
							? __(
									'Only a manager can change your emergency contact.',
									'radius-hotel-booking-pro'
							  )
							: __(
									'Who the hotel calls if something happens to you at work.',
									'radius-hotel-booking-pro'
							  )
					}
					actions={
						lockedContact ? (
							<Lock
								className="h-4 w-4 text-muted-foreground"
								aria-label={ __(
									'Locked',
									'radius-hotel-booking-pro'
								) }
							/>
						) : null
					}
				>
					<div className="grid gap-4 sm:grid-cols-2">
						{ row(
							'emergency_contact.full_name',
							( locked ) => (
								<Input
									value={ contact.full_name || '' }
									onChange={ ( e ) =>
										setContact(
											'full_name',
											e.target.value
										)
									}
									disabled={ locked }
									autoComplete="off"
								/>
							),
							{
								label: __(
									'Full name',
									'radius-hotel-booking-pro'
								),
								quiet: true,
							}
						) }
						{ row(
							'emergency_contact.relationship',
							( locked ) => (
								<ChoiceSelect
									value={ contact.relationship || '' }
									choices={ profile.options?.relationships }
									onChange={ ( value ) =>
										setContact( 'relationship', value )
									}
									disabled={ locked }
								/>
							),
							{
								label: __(
									'Relationship',
									'radius-hotel-booking-pro'
								),
								quiet: true,
							}
						) }
						{ row(
							'emergency_contact.phone',
							( locked ) => (
								<Input
									type="tel"
									value={ contact.phone || '' }
									onChange={ ( e ) =>
										setContact( 'phone', e.target.value )
									}
									disabled={ locked }
									autoComplete="off"
								/>
							),
							{
								label: __(
									'Phone',
									'radius-hotel-booking-pro'
								),
								quiet: true,
							}
						) }
					</div>
					{ row(
						'emergency_contact.address',
						( locked ) => (
							<Textarea
								rows={ 3 }
								value={ contact.address || '' }
								onChange={ ( e ) =>
									setContact( 'address', e.target.value )
								}
								disabled={ locked }
							/>
						),
						{
							label: __( 'Address', 'radius-hotel-booking-pro' ),
							quiet: true,
						}
					) }
					{ errors.emergency_contact ? (
						<p className="m-0 text-sm text-destructive">
							{ errors.emergency_contact }
						</p>
					) : null }
				</SettingsSection>
			) : null }

			{ changed.length ? (
				<div className="sticky bottom-[4.5rem] z-10 flex flex-wrap items-center justify-end gap-2 rounded-xl border border-border bg-card px-4 py-3 shadow-lg md:bottom-4">
					<Button
						type="button"
						variant="ghost"
						disabled={ save.isPending }
						onClick={ () => {
							setValues( original );
							setErrors( {} );
						} }
					>
						{ __( 'Undo changes', 'radius-hotel-booking-pro' ) }
					</Button>
					<Button type="submit" disabled={ save.isPending }>
						{ __( 'Save', 'radius-hotel-booking-pro' ) }
					</Button>
				</div>
			) : null }
		</form>
	);
}

/**
 * The screen.
 *
 * @return {JSX.Element} Screen.
 */
export default function ProfileScreen() {
	const { data: profile, isPending, error, refetch } = useProfile();

	if ( isPending ) {
		return (
			<div className="mx-auto max-w-3xl space-y-4">
				<Skeleton className="h-24 w-full rounded-xl" />
				<Skeleton className="h-72 w-full rounded-xl" />
			</div>
		);
	}
	if ( error || ! profile ) {
		return (
			<div className="mx-auto max-w-3xl">
				<Panel>
					<EmptyState
						icon={ UserRound }
						title={ __(
							'Your profile could not be loaded',
							'radius-hotel-booking-pro'
						) }
						description={ error?.message }
						action={
							<Button
								variant="outline"
								onClick={ () => refetch() }
							>
								{ __(
									'Try again',
									'radius-hotel-booking-pro'
								) }
							</Button>
						}
						className="border-0"
					/>
				</Panel>
			</div>
		);
	}

	const name = [ profile.values.first_name, profile.values.last_name ]
		.filter( Boolean )
		.join( ' ' );
	const job = profile.employee
		? [
				profile.employee.job_title,
				profile.employee.department,
				profile.employee.employee_no,
		  ]
				.filter( Boolean )
				.join( ' · ' )
		: '';

	return (
		<div className="mx-auto max-w-3xl space-y-4">
			<Panel bodyClassName="flex items-center gap-4">
				<span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-primary-soft text-lg font-semibold text-primary">
					{ initials( name || profile.username ) }
				</span>
				<div className="min-w-0">
					<h2 className="m-0 truncate text-lg font-bold text-heading">
						{ name || profile.username }
					</h2>
					<p className="m-0 truncate text-sm text-muted-foreground">
						{ profile.username }
						{ job ? ` · ${ job }` : '' }
					</p>
				</div>
			</Panel>
			<ProfileForm profile={ profile } />
			<SecuritySection security={ profile.security } />
		</div>
	);
}

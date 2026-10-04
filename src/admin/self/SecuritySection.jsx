/**
 * Password and PIN on *My profile* (M16 16.3, 16.4). Each asks for proof
 * first: the current password, or for the PIN the current PIN (or the
 * password). The fields the hotel restricts show a lock instead of a form;
 * the server refuses them anyway.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Lock } from 'lucide-react';
import { Button, Field, Input, SettingsSection } from '@rtbp/ui';
import { toast, toastError } from '@rtbp/lib';

import { useChangePassword, useChangePin } from './api';

/**
 * Field errors from an API error, or null when it has none.
 *
 * @param {Error} err API error.
 * @return {Object|null} Field => message.
 */
const fieldErrors = ( err ) => {
	const entries = Object.entries( err?.errors || {} ).map(
		( [ key, value ] ) => [ key, value?.first_message ]
	);
	return entries.length ? Object.fromEntries( entries ) : null;
};

/**
 * The "a manager decides" line in place of a form.
 *
 * @param {Object} props      Props.
 * @param {string} props.text Text.
 * @return {JSX.Element} Note.
 */
const LockedNote = ( { text } ) => (
	<p className="m-0 flex items-center gap-2 text-sm text-muted-foreground">
		<Lock className="h-4 w-4 shrink-0" aria-hidden="true" />
		{ text }
	</p>
);

/**
 * Change my password.
 *
 * @param {Object}  props          Props.
 * @param {boolean} props.editable Whether the hotel lets me.
 * @return {JSX.Element} Section.
 */
function PasswordCard( { editable } ) {
	const change = useChangePassword();
	const empty = { current: '', next: '', confirm: '' };
	const [ values, setValues ] = useState( empty );
	const [ errors, setErrors ] = useState( {} );

	const set = ( key ) => ( event ) => {
		const value = event.target.value;
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setErrors( ( prev ) => ( { ...prev, [ key ]: undefined } ) );
	};

	const submit = async ( event ) => {
		event.preventDefault();
		if ( values.next !== values.confirm ) {
			setErrors( {
				confirm: __(
					'The two passwords do not match.',
					'radius-hotel-booking-pro'
				),
			} );
			return;
		}
		try {
			const { message } = await change.mutateAsync( {
				current_password: values.current,
				new_password: values.next,
			} );
			setValues( empty );
			setErrors( {} );
			toast.success( message );
		} catch ( err ) {
			const mapped = fieldErrors( err );
			if ( mapped ) {
				setErrors( {
					current: mapped.current_password,
					next: mapped.new_password,
				} );
			} else {
				toastError( err );
			}
		}
	};

	return (
		<SettingsSection
			title={ __( 'Password', 'radius-hotel-booking-pro' ) }
			description={ __(
				'Changing it signs you out on every other device.',
				'radius-hotel-booking-pro'
			) }
		>
			{ editable ? (
				<form className="space-y-4" onSubmit={ submit } noValidate>
					<Field
						label={ __(
							'Current password',
							'radius-hotel-booking-pro'
						) }
						error={ errors.current }
						required
					>
						<Input
							type="password"
							value={ values.current }
							onChange={ set( 'current' ) }
							autoComplete="current-password"
						/>
					</Field>
					<div className="grid gap-4 sm:grid-cols-2">
						<Field
							label={ __(
								'New password',
								'radius-hotel-booking-pro'
							) }
							description={ __(
								'At least 8 characters.',
								'radius-hotel-booking-pro'
							) }
							error={ errors.next }
							required
						>
							<Input
								type="password"
								value={ values.next }
								onChange={ set( 'next' ) }
								autoComplete="new-password"
							/>
						</Field>
						<Field
							label={ __(
								'Repeat the new password',
								'radius-hotel-booking-pro'
							) }
							error={ errors.confirm }
							required
						>
							<Input
								type="password"
								value={ values.confirm }
								onChange={ set( 'confirm' ) }
								autoComplete="new-password"
							/>
						</Field>
					</div>
					<div className="flex justify-end">
						<Button
							type="submit"
							disabled={
								change.isPending ||
								! values.current ||
								! values.next
							}
						>
							{ __(
								'Change password',
								'radius-hotel-booking-pro'
							) }
						</Button>
					</div>
				</form>
			) : (
				<LockedNote
					text={ __(
						'Only a manager can change your password.',
						'radius-hotel-booking-pro'
					) }
				/>
			) }
		</SettingsSection>
	);
}

/**
 * Set my PIN.
 *
 * @param {Object}  props          Props.
 * @param {boolean} props.editable Whether the hotel lets me.
 * @param {boolean} props.hasPin   Whether I have a PIN already.
 * @return {JSX.Element} Section.
 */
function PinCard( { editable, hasPin } ) {
	const change = useChangePin();
	const empty = { proof: '', pin: '', confirm: '' };
	const [ values, setValues ] = useState( empty );
	const [ errors, setErrors ] = useState( {} );
	// With a PIN, prove it with the PIN (or the password, if forgotten).
	const [ usePassword, setUsePassword ] = useState( false );
	const byPassword = ! hasPin || usePassword;

	const set = ( key, digits ) => ( event ) => {
		const value = digits
			? event.target.value.replace( /\D/g, '' ).slice( 0, 8 )
			: event.target.value;
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setErrors( ( prev ) => ( { ...prev, [ key ]: undefined } ) );
	};

	const submit = async ( event ) => {
		event.preventDefault();
		if ( values.pin !== values.confirm ) {
			setErrors( {
				confirm: __(
					'The two PINs do not match.',
					'radius-hotel-booking-pro'
				),
			} );
			return;
		}
		try {
			await change.mutateAsync( {
				pin: values.pin,
				...( byPassword
					? { current_password: values.proof }
					: { current_pin: values.proof } ),
			} );
			setValues( empty );
			setErrors( {} );
			setUsePassword( false );
			toast.success(
				__( 'Your PIN is changed.', 'radius-hotel-booking-pro' )
			);
		} catch ( err ) {
			const mapped = fieldErrors( err );
			if ( mapped ) {
				setErrors( {
					proof: mapped.current_pin || mapped.current_password,
					pin: mapped.pin,
				} );
			} else {
				toastError( err );
			}
		}
	};

	const pinInput = ( key ) => (
		<Input
			type="password"
			inputMode="numeric"
			pattern="[0-9]*"
			maxLength={ 8 }
			value={ values[ key ] }
			onChange={ set( key, true ) }
			autoComplete="off"
		/>
	);

	return (
		<SettingsSection
			title={ __( 'Personal PIN', 'radius-hotel-booking-pro' ) }
			description={
				hasPin
					? __(
							'You confirm protected actions with it. Changing it ends any PIN confirmation still open.',
							'radius-hotel-booking-pro'
					  )
					: __(
							'You do not have a PIN yet. Choose one to confirm protected actions.',
							'radius-hotel-booking-pro'
					  )
			}
		>
			{ editable ? (
				<form className="space-y-4" onSubmit={ submit } noValidate>
					<Field
						label={
							byPassword
								? __(
										'Your password',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Current PIN',
										'radius-hotel-booking-pro'
								  )
						}
						error={ errors.proof }
						required
					>
						{ byPassword ? (
							<Input
								type="password"
								value={ values.proof }
								onChange={ set( 'proof', false ) }
								autoComplete="current-password"
							/>
						) : (
							pinInput( 'proof' )
						) }
					</Field>
					{ hasPin ? (
						<button
							type="button"
							className="text-sm font-medium text-primary hover:underline"
							onClick={ () => {
								setUsePassword( ! usePassword );
								setValues( ( prev ) => ( {
									...prev,
									proof: '',
								} ) );
								setErrors( {} );
							} }
						>
							{ usePassword
								? __(
										'Use my current PIN instead',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Forgot your PIN? Use your password',
										'radius-hotel-booking-pro'
								  ) }
						</button>
					) : null }
					<div className="grid gap-4 sm:grid-cols-2">
						<Field
							label={ __(
								'New PIN',
								'radius-hotel-booking-pro'
							) }
							description={ __(
								'4 to 8 digits.',
								'radius-hotel-booking-pro'
							) }
							error={ errors.pin }
							required
						>
							{ pinInput( 'pin' ) }
						</Field>
						<Field
							label={ __(
								'Repeat the new PIN',
								'radius-hotel-booking-pro'
							) }
							error={ errors.confirm }
							required
						>
							{ pinInput( 'confirm' ) }
						</Field>
					</div>
					<div className="flex justify-end">
						<Button
							type="submit"
							disabled={
								change.isPending ||
								! values.proof ||
								values.pin.length < 4
							}
						>
							{ hasPin
								? __( 'Change PIN', 'radius-hotel-booking-pro' )
								: __( 'Set PIN', 'radius-hotel-booking-pro' ) }
						</Button>
					</div>
				</form>
			) : (
				<LockedNote
					text={ __(
						'Only a manager can change your PIN.',
						'radius-hotel-booking-pro'
					) }
				/>
			) }
		</SettingsSection>
	);
}

/**
 * Password and PIN cards.
 *
 * @param {Object} props          Props.
 * @param {Object} props.security `{ password: { editable }, pin: { available, editable, has_pin } }`.
 * @return {JSX.Element|null} Cards.
 */
export default function SecuritySection( { security } ) {
	if ( ! security ) {
		return null;
	}
	return (
		<>
			<PasswordCard editable={ !! security.password?.editable } />
			{ security.pin?.available ? (
				<PinCard
					editable={ !! security.pin.editable }
					hasPin={ !! security.pin.has_pin }
				/>
			) : null }
		</>
	);
}

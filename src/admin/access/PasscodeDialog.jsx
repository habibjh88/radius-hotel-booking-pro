/**
 * The PIN prompt (M13 T4b). Opened by ./passcode.js when a request answers
 * `passcode_required`; it calls `POST access/unlock` and hands the grant back.
 * Screens never ask for PINs themselves.
 */
import { Suspense, useEffect, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { KeyRound } from 'lucide-react';
import {
	Button,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	Input,
} from '@rtbp/ui';
import { api } from '@rtbp/lib';

// Errors after which entering a PIN cannot help.
const BLOCKING = [ 'pin_locked', 'pin_not_set' ];

// Worth another try in a moment (another PIN check of the same person ran).
const RETRY = [ 'pin_busy' ];

/**
 * @param {Object}   props            Props.
 * @param {string[]} props.accessKeys The keys being unlocked.
 * @param {Function} props.onUnlocked Called with `{ token, expires_at, scope }`.
 * @param {Function} props.onCancel   Called when the prompt is closed.
 * @return {JSX.Element} Dialog.
 */
export default function PasscodeDialog( { accessKeys, onUnlocked, onCancel } ) {
	const [ pin, setPin ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ blocked, setBlocked ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const input = useRef( null );

	// Say up front when there is no PIN to enter, or the user is locked out.
	useEffect( () => {
		api.get( 'access/passcode' )
			.then( ( { data } ) => {
				if ( data?.locked_until ) {
					setBlocked( true );
					setError(
						__(
							'Too many wrong PINs. Try again later.',
							'radius-hotel-booking-pro'
						)
					);
				} else if ( data && ! data.can_enter_pin ) {
					setBlocked( true );
					setError(
						__(
							'You do not have a PIN yet. Ask a manager to set one for you.',
							'radius-hotel-booking-pro'
						)
					);
				}
			} )
			.catch( () => {} );
	}, [] );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( busy || blocked || ! /^\d{4,8}$/.test( pin ) ) {
			setError(
				__( 'A PIN is 4 to 8 digits.', 'radius-hotel-booking-pro' )
			);
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			const { data } = await api.post( 'access/unlock', {
				pin,
				keys: accessKeys,
			} );
			onUnlocked( data );
		} catch ( failure ) {
			setError( failure.message );
			setBlocked( BLOCKING.includes( failure.code ) );
			if ( ! RETRY.includes( failure.code ) ) {
				setPin( '' );
			}
			input.current?.focus();
		} finally {
			setBusy( false );
		}
	};

	return (
		<Suspense fallback={ null }>
			<Dialog
				open
				onOpenChange={ ( open ) => {
					if ( ! open && ! busy ) {
						onCancel();
					}
				} }
			>
				<DialogContent className="rtbp-root sm:max-w-sm">
					<form onSubmit={ submit } className="space-y-4">
						<DialogHeader>
							<DialogTitle className="flex items-center gap-2">
								<KeyRound
									className="h-5 w-5 text-primary"
									aria-hidden="true"
								/>
								{ __(
									'Enter your PIN',
									'radius-hotel-booking-pro'
								) }
							</DialogTitle>
							<DialogDescription>
								{ __(
									'This action is protected. Confirm it with your personal PIN.',
									'radius-hotel-booking-pro'
								) }
							</DialogDescription>
						</DialogHeader>

						<Input
							ref={ input }
							type="password"
							inputMode="numeric"
							autoComplete="off"
							autoFocus
							maxLength={ 8 }
							value={ pin }
							disabled={ blocked || busy }
							aria-label={ __( 'PIN', 'radius-hotel-booking-pro' ) }
							aria-invalid={ error ? true : undefined }
							onChange={ ( event ) =>
								setPin(
									event.target.value.replace( /\D/g, '' )
								)
							}
							className="text-center text-lg tracking-widest"
						/>

						{ error ? (
							<p
								role="alert"
								className="m-0 text-sm text-destructive"
							>
								{ error }
							</p>
						) : null }

						<DialogFooter className="gap-2">
							<Button
								type="button"
								variant="outline"
								onClick={ onCancel }
								disabled={ busy }
							>
								{ blocked
									? __( 'Close', 'radius-hotel-booking-pro' )
									: __( 'Cancel', 'radius-hotel-booking-pro' ) }
							</Button>
							{ blocked ? null : (
								<Button
									type="submit"
									disabled={ busy || pin.length < 4 }
								>
									{ busy
										? __(
												'Checking…',
												'radius-hotel-booking-pro'
										  )
										: __(
												'Confirm',
												'radius-hotel-booking-pro'
										  ) }
								</Button>
							) }
						</DialogFooter>
					</form>
				</DialogContent>
			</Dialog>
		</Suspense>
	);
}

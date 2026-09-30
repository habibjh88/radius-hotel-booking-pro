/**
 * The account of an employee (12.5): the user name (fixed), a new password
 * or an e-mailed link to choose one, the access role and the PIN. Each part
 * saves on its own; PINs and roles need Pro's advanced staff access and the
 * "Manage permissions" key (`access.manage`), and your own role is not
 * changed here.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Eye, EyeOff, KeyRound, Mail, ShieldCheck } from 'lucide-react';
import {
	Button,
	Field,
	Input,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
} from '@rtbp/ui';
import { access, toast, toastError } from '@rtbp/lib';

import { sendPasswordLink, useStaffOptions, useUpdateAccount } from './api';

/**
 * A titled block of the tab.
 *
 * @param {Object}      props          Props.
 * @param {Object}      props.icon     Lucide icon.
 * @param {string}      props.title    Title.
 * @param {JSX.Element} props.children Body.
 * @return {JSX.Element} Block.
 */
function Block( { icon: Icon, title, children } ) {
	return (
		<section className="space-y-3 rounded-lg border border-border p-4">
			<h3 className="m-0 flex items-center gap-2 text-sm font-semibold text-heading">
				<Icon
					className="h-4 w-4 text-muted-foreground"
					aria-hidden="true"
				/>
				{ title }
			</h3>
			{ children }
		</section>
	);
}

/**
 * @param {Object}  props          Props.
 * @param {Object}  props.employee Employee (detail shape).
 * @param {boolean} props.canEdit  Viewer has `staff.edit`.
 * @return {JSX.Element} Tab.
 */
export default function AccountTab( { employee, canEdit } ) {
	const options = useStaffOptions();
	const update = useUpdateAccount( employee.id );
	const [ password, setPassword ] = useState( '' );
	const [ showPassword, setShowPassword ] = useState( false );
	const [ pin, setPin ] = useState( '' );
	const [ role, setRole ] = useState(
		employee.access_role ? String( employee.access_role.id ) : 'none'
	);
	const [ errors, setErrors ] = useState( {} );
	const [ sending, setSending ] = useState( false );

	const save = async ( fields, done ) => {
		try {
			await update.mutateAsync( fields );
			toast.success( done );
			return true;
		} catch ( err ) {
			const mapped = {};
			Object.entries( err?.errors || {} ).forEach(
				( [ field, value ] ) => {
					mapped[ field ] = value?.first_message;
				}
			);
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
			return false;
		}
	};

	// Roles and PINs are M13's "Manage permissions" (access.manage), and nobody changes their own role.
	const manageLevel = access?.useAccess
		? access.useAccess( 'access.manage' )
		: 'locked';
	const advanced = options.data?.advanced_access && 'locked' !== manageLevel;
	const isSelf =
		Number( window.radius_hotel_booking_param?.current_user?.id ) ===
		Number( employee.user_id );

	return (
		<div className="space-y-4">
			<Block
				icon={ KeyRound }
				title={ __( 'Sign-in', 'radius-hotel-booking-pro' ) }
			>
				<dl className="m-0 grid gap-3 sm:grid-cols-2">
					<div>
						<dt className="text-xs text-muted-foreground">
							{ __( 'User name', 'radius-hotel-booking-pro' ) }
						</dt>
						<dd className="m-0 font-mono text-sm text-heading">
							{ employee.user_login }
						</dd>
					</div>
					<div>
						<dt className="text-xs text-muted-foreground">
							{ __( 'E-mail', 'radius-hotel-booking-pro' ) }
						</dt>
						<dd className="m-0 break-words text-sm text-heading">
							{ employee.email ||
								__( 'None', 'radius-hotel-booking-pro' ) }
						</dd>
					</div>
				</dl>
				{ canEdit ? (
					<form
						noValidate
						onSubmit={ async ( event ) => {
							event.preventDefault();
							if ( password.length < 8 ) {
								setErrors( {
									password: __(
										'Use at least 8 characters.',
										'radius-hotel-booking-pro'
									),
								} );
								return;
							}
							if (
								await save(
									{ password },
									__(
										'Password changed.',
										'radius-hotel-booking-pro'
									)
								)
							) {
								setPassword( '' );
								setShowPassword( false );
							}
						} }
						className="space-y-3"
					>
						<Field
							label={ __(
								'New password',
								'radius-hotel-booking-pro'
							) }
							error={ errors.password }
						>
							<div className="flex gap-2">
								<Input
									type={ showPassword ? 'text' : 'password' }
									value={ password }
									onChange={ ( e ) => {
										setPassword( e.target.value );
										setErrors( {} );
									} }
									autoComplete="new-password"
									className="min-w-0 flex-1"
								/>
								<Button
									type="button"
									variant="outline"
									size="icon"
									onClick={ () =>
										setShowPassword( ( v ) => ! v )
									}
									aria-label={
										showPassword
											? __(
													'Hide password',
													'radius-hotel-booking-pro'
											  )
											: __(
													'Show password',
													'radius-hotel-booking-pro'
											  )
									}
								>
									{ showPassword ? (
										<EyeOff
											className="h-4 w-4"
											aria-hidden="true"
										/>
									) : (
										<Eye
											className="h-4 w-4"
											aria-hidden="true"
										/>
									) }
								</Button>
							</div>
						</Field>
						<div className="flex flex-wrap gap-2">
							<Button
								type="submit"
								size="sm"
								disabled={ update.isPending || ! password }
							>
								{ __(
									'Set password',
									'radius-hotel-booking-pro'
								) }
							</Button>
							<Button
								type="button"
								size="sm"
								variant="outline"
								disabled={ sending || ! employee.email }
								onClick={ () => {
									setSending( true );
									sendPasswordLink( employee.id )
										.then( () =>
											toast.success(
												sprintf(
													/* translators: %s: e-mail address. */
													__(
														'Link sent to %s.',
														'radius-hotel-booking-pro'
													),
													employee.email
												)
											)
										)
										.catch( toastError )
										.finally( () => setSending( false ) );
								} }
							>
								<Mail className="h-4 w-4" aria-hidden="true" />
								{ __(
									'E-mail a password link',
									'radius-hotel-booking-pro'
								) }
							</Button>
						</div>
					</form>
				) : null }
			</Block>

			{ advanced ? (
				<Block
					icon={ ShieldCheck }
					title={ __( 'Access and PIN', 'radius-hotel-booking-pro' ) }
				>
					<div className="grid gap-4 sm:grid-cols-2">
						<div className="space-y-3">
							<Field
								label={ __(
									'Access role',
									'radius-hotel-booking-pro'
								) }
								error={ errors.access_role }
								description={
									isSelf
										? __(
												'You cannot change your own access role.',
												'radius-hotel-booking-pro'
										  )
										: ''
								}
							>
								<Select
									value={ role }
									onValueChange={ setRole }
									disabled={ ! canEdit || isSelf }
								>
									<SelectTrigger>
										<SelectValue />
									</SelectTrigger>
									<SelectContent>
										<SelectItem value="none">
											{ __(
												'Staff defaults',
												'radius-hotel-booking-pro'
											) }
										</SelectItem>
										{ (
											options.data?.access_roles || []
										).map( ( option ) => (
											<SelectItem
												key={ option.id }
												value={ String( option.id ) }
											>
												{ option.name }
											</SelectItem>
										) ) }
									</SelectContent>
								</Select>
							</Field>
							{ canEdit && ! isSelf ? (
								<Button
									type="button"
									size="sm"
									disabled={
										update.isPending ||
										role ===
											( employee.access_role
												? String(
														employee.access_role.id
												  )
												: 'none' )
									}
									onClick={ () =>
										save(
											{
												access_role:
													'none' === role
														? null
														: Number( role ),
											},
											__(
												'Access role saved.',
												'radius-hotel-booking-pro'
											)
										)
									}
								>
									{ __(
										'Save role',
										'radius-hotel-booking-pro'
									) }
								</Button>
							) : null }
						</div>
						<div className="space-y-3">
							<div className="space-y-1.5">
								<p className="m-0 text-sm font-semibold text-heading">
									{ __( 'PIN', 'radius-hotel-booking-pro' ) }
								</p>
								<p className="m-0 text-sm text-muted-foreground">
									{ employee.has_pin
										? __(
												'A personal PIN is set.',
												'radius-hotel-booking-pro'
										  )
										: __(
												'No personal PIN.',
												'radius-hotel-booking-pro'
										  ) }
								</p>
							</div>
							{ canEdit ? (
								<div
									className="flex flex-wrap gap-3"
									// Buttons line up with the input, not its label (no items-end in the shared CSS).
									style={ { alignItems: 'flex-end' } }
								>
									<Field
										label={
											employee.has_pin
												? __(
														'New PIN',
														'radius-hotel-booking-pro'
												  )
												: __(
														'Set a PIN',
														'radius-hotel-booking-pro'
												  )
										}
										error={ errors.pin }
										className="w-40"
									>
										<Input
											type="password"
											inputMode="numeric"
											maxLength={ 8 }
											value={ pin }
											autoComplete="new-password"
											onChange={ ( e ) => {
												setPin(
													e.target.value.replace(
														/\D+/g,
														''
													)
												);
												setErrors( {} );
											} }
										/>
									</Field>
									<Button
										type="button"
										size="sm"
										variant="outline"
										disabled={
											update.isPending || pin.length < 4
										}
										onClick={ async () => {
											if (
												await save(
													{ pin },
													__(
														'PIN saved.',
														'radius-hotel-booking-pro'
													)
												)
											) {
												setPin( '' );
											}
										} }
									>
										{ __(
											'Save PIN',
											'radius-hotel-booking-pro'
										) }
									</Button>
									{ employee.has_pin ? (
										<Button
											type="button"
											size="sm"
											variant="ghost"
											className="text-destructive"
											disabled={ update.isPending }
											onClick={ () =>
												save(
													{ remove_pin: true },
													__(
														'PIN removed.',
														'radius-hotel-booking-pro'
													)
												)
											}
										>
											{ __(
												'Remove PIN',
												'radius-hotel-booking-pro'
											) }
										</Button>
									) : null }
								</div>
							) : null }
						</div>
					</div>
				</Block>
			) : null }
		</div>
	);
}

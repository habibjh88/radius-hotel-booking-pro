/**
 * Add an employee (`#/staff/new`, 12.2): two steps — the person (name,
 * phone, e-mail, job), then the account (user name, password or a link to
 * set it, access role, PIN). One request creates the WordPress user and the
 * employee file together; the detail screen opens afterwards.
 */
import { Suspense, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, Eye, EyeOff, Wand2 } from 'lucide-react';
import {
	Button,
	Field,
	Input,
	Panel,
	SegmentedControl,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
} from '@rtbp/ui';
import { access, toast, toastError } from '@rtbp/lib';

import { sendPasswordLink, useCreateEmployee, useStaffOptions } from './api';

const STEP_ONE = [
	'first_name',
	'last_name',
	'phone',
	'email',
	'job_title',
	'department',
	'shift',
];

/**
 * A readable user name from the name: `aya.kouame`.
 *
 * @param {string} first First name.
 * @param {string} last  Last name.
 * @return {string} User name.
 */
const suggestUsername = ( first, last ) =>
	[ first, last ]
		.map( ( part ) =>
			part
				.normalize( 'NFD' )
				.replace( /[̀-ͯ]/g, '' )
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, '' )
		)
		.filter( Boolean )
		.join( '.' );

/**
 * A random password of 16 characters.
 *
 * @return {string} Password.
 */
const randomPassword = () => {
	const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789-_';
	const bytes = new Uint32Array( 16 );
	window.crypto.getRandomValues( bytes );
	return Array.from( bytes, ( n ) => chars[ n % chars.length ] ).join( '' );
};

/**
 * @return {JSX.Element} Screen.
 */
export default function CreateEmployeeScreen() {
	const navigate = useNavigate();
	const options = useStaffOptions();
	const create = useCreateEmployee();
	const [ step, setStep ] = useState( 1 );
	const [ errors, setErrors ] = useState( {} );
	const [ showPassword, setShowPassword ] = useState( false );
	const [ values, setValues ] = useState( {
		first_name: '',
		last_name: '',
		phone: '',
		email: '',
		job_title: '',
		department: '',
		shift: '',
		username: '',
		password_mode: 'set',
		password: '',
		access_role: '',
		pin: '',
	} );
	const [ usernameTouched, setUsernameTouched ] = useState( false );

	const set = ( field, value ) => {
		setValues( ( prev ) => {
			const next = { ...prev, [ field ]: value };
			if (
				! usernameTouched &&
				( 'first_name' === field || 'last_name' === field )
			) {
				next.username = suggestUsername(
					next.first_name,
					next.last_name
				);
			}
			return next;
		} );
		setErrors( ( prev ) => ( { ...prev, [ field ]: undefined } ) );
	};

	const input = ( name, props = {} ) => (
		<Input
			value={ values[ name ] }
			onChange={ ( e ) => set( name, e.target.value ) }
			autoComplete="off"
			{ ...props }
		/>
	);

	const next = ( event ) => {
		event.preventDefault();
		const missing = {};
		if ( ! values.first_name.trim() ) {
			missing.first_name = __(
				'Enter the first name.',
				'radius-hotel-booking-pro'
			);
		}
		if ( ! values.last_name.trim() ) {
			missing.last_name = __(
				'Enter the last name.',
				'radius-hotel-booking-pro'
			);
		}
		if ( values.phone.replace( /\D+/g, '' ).length < 8 ) {
			missing.phone = __(
				'Enter the phone number.',
				'radius-hotel-booking-pro'
			);
		}
		setErrors( missing );
		if ( ! Object.keys( missing ).length ) {
			setStep( 2 );
		}
	};

	const submit = async ( event ) => {
		event.preventDefault();
		const body = { ...values };
		const wantsLink = 'link' === values.password_mode;
		if ( wantsLink ) {
			body.password = '';
			if ( ! values.email.trim() ) {
				setErrors( {
					email: __(
						'Add an e-mail address: the link is sent there.',
						'radius-hotel-booking-pro'
					),
				} );
				setStep( 1 );
				return;
			}
		}
		delete body.password_mode;
		body.access_role = values.access_role
			? Number( values.access_role )
			: null;
		try {
			const employee = await create.mutateAsync( body );
			toast.success(
				__( 'Employee added.', 'radius-hotel-booking-pro' )
			);
			if ( wantsLink ) {
				sendPasswordLink( employee.id )
					.then( () =>
						toast.success(
							__(
								'A link to set the password was e-mailed.',
								'radius-hotel-booking-pro'
							)
						)
					)
					.catch( toastError );
			}
			navigate( `/staff/${ employee.id }`, { replace: true } );
		} catch ( err ) {
			const mapped = {};
			Object.entries( err?.errors || {} ).forEach(
				( [ field, value ] ) => {
					mapped[ field ] = value?.first_message;
				}
			);
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
				if ( STEP_ONE.some( ( field ) => mapped[ field ] ) ) {
					setStep( 1 );
				}
			} else {
				toastError( err );
			}
		}
	};

	const roles = options.data?.access_roles || [];
	// Choosing a role or PIN needs "Manage permissions" (access.manage).
	const manageLevel = access?.useAccess
		? access.useAccess( 'access.manage' )
		: 'locked';

	return (
		// Select parts load on demand (window.rtbp.ui).
		<Suspense fallback={ <Skeleton className="h-96 w-full rounded-xl" /> }>
			<div className="mx-auto max-w-3xl space-y-4">
				<Link
					to="/staff"
					className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-heading"
				>
					<ArrowLeft className="h-4 w-4" aria-hidden="true" />
					{ __( 'All staff', 'radius-hotel-booking-pro' ) }
				</Link>
				<Panel
					title={ __(
						'Add an employee',
						'radius-hotel-booking-pro'
					) }
					description={
						1 === step
							? __(
									'Step 1 of 2 — who they are.',
									'radius-hotel-booking-pro'
							  )
							: __(
									'Step 2 of 2 — how they sign in.',
									'radius-hotel-booking-pro'
							  )
					}
				>
					{ 1 === step ? (
						<form
							onSubmit={ next }
							noValidate
							className="space-y-5"
						>
							<div className="grid gap-4 sm:grid-cols-2">
								<Field
									label={ __(
										'First name',
										'radius-hotel-booking-pro'
									) }
									error={ errors.first_name }
									required
								>
									{ input( 'first_name', {
										maxLength: 100,
									} ) }
								</Field>
								<Field
									label={ __(
										'Last name',
										'radius-hotel-booking-pro'
									) }
									error={ errors.last_name }
									required
								>
									{ input( 'last_name', { maxLength: 100 } ) }
								</Field>
								<Field
									label={ __(
										'Phone',
										'radius-hotel-booking-pro'
									) }
									error={ errors.phone }
									required
								>
									{ input( 'phone', {
										type: 'tel',
										inputMode: 'tel',
										placeholder: '07 07 12 34 56',
									} ) }
								</Field>
								<Field
									label={ __(
										'E-mail',
										'radius-hotel-booking-pro'
									) }
									error={ errors.email }
									description={ __(
										'Needed to e-mail them a password link.',
										'radius-hotel-booking-pro'
									) }
								>
									{ input( 'email', { type: 'email' } ) }
								</Field>
								<Field
									label={ __(
										'Job title',
										'radius-hotel-booking-pro'
									) }
									error={ errors.job_title }
								>
									{ input( 'job_title', { maxLength: 100 } ) }
								</Field>
								<Field
									label={ __(
										'Department',
										'radius-hotel-booking-pro'
									) }
									error={ errors.department }
								>
									{ input( 'department', {
										maxLength: 100,
									} ) }
								</Field>
								<Field
									label={ __(
										'Work shift',
										'radius-hotel-booking-pro'
									) }
									error={ errors.shift }
									description={ __(
										'For example Morning, Day or Night.',
										'radius-hotel-booking-pro'
									) }
								>
									{ input( 'shift', { maxLength: 60 } ) }
								</Field>
							</div>
							<div className="flex justify-end gap-2">
								<Button asChild variant="ghost">
									<Link to="/staff">
										{ __(
											'Cancel',
											'radius-hotel-booking-pro'
										) }
									</Link>
								</Button>
								<Button type="submit">
									{ __( 'Next', 'radius-hotel-booking-pro' ) }
								</Button>
							</div>
						</form>
					) : (
						<form
							onSubmit={ submit }
							noValidate
							className="space-y-5"
						>
							<Field
								label={ __(
									'User name',
									'radius-hotel-booking-pro'
								) }
								error={ errors.username }
								description={ __(
									'They sign in with it. It cannot be changed later.',
									'radius-hotel-booking-pro'
								) }
								required
							>
								<Input
									value={ values.username }
									onChange={ ( e ) => {
										setUsernameTouched( true );
										set( 'username', e.target.value );
									} }
									autoComplete="off"
									maxLength={ 60 }
								/>
							</Field>

							<div className="space-y-2">
								<SegmentedControl
									label={ __(
										'Password',
										'radius-hotel-booking-pro'
									) }
									value={ values.password_mode }
									onChange={ ( mode ) =>
										set( 'password_mode', mode )
									}
									options={ [
										{
											value: 'set',
											label: __(
												'Set a password',
												'radius-hotel-booking-pro'
											),
										},
										{
											value: 'link',
											label: __(
												'E-mail them a link',
												'radius-hotel-booking-pro'
											),
										},
									] }
								/>
								{ 'set' === values.password_mode ? (
									<Field
										label={ __(
											'Password',
											'radius-hotel-booking-pro'
										) }
										error={ errors.password }
										description={ __(
											'At least 8 characters. Give it to them in person.',
											'radius-hotel-booking-pro'
										) }
										required
									>
										<div className="flex gap-2">
											<Input
												type={
													showPassword
														? 'text'
														: 'password'
												}
												value={ values.password }
												onChange={ ( e ) =>
													set(
														'password',
														e.target.value
													)
												}
												autoComplete="new-password"
												className="min-w-0 flex-1"
											/>
											<Button
												type="button"
												variant="outline"
												size="icon"
												onClick={ () =>
													setShowPassword(
														( v ) => ! v
													)
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
											<Button
												type="button"
												variant="outline"
												size="icon"
												onClick={ () => {
													set(
														'password',
														randomPassword()
													);
													setShowPassword( true );
												} }
												aria-label={ __(
													'Suggest a password',
													'radius-hotel-booking-pro'
												) }
											>
												<Wand2
													className="h-4 w-4"
													aria-hidden="true"
												/>
											</Button>
										</div>
									</Field>
								) : (
									<p className="m-0 text-sm text-muted-foreground">
										{ values.email
											? __(
													'After saving, a link to choose their password is sent to their e-mail.',
													'radius-hotel-booking-pro'
											  )
											: __(
													'Add their e-mail in step 1 to send the link.',
													'radius-hotel-booking-pro'
											  ) }
									</p>
								) }
							</div>

							{ options.data?.advanced_access &&
							'locked' !== manageLevel ? (
								<div className="grid gap-4 sm:grid-cols-2">
									<Field
										label={ __(
											'Access role',
											'radius-hotel-booking-pro'
										) }
										error={ errors.access_role }
										description={ __(
											'What they may open and do. Without one, the staff defaults apply.',
											'radius-hotel-booking-pro'
										) }
									>
										<Select
											value={
												values.access_role || 'none'
											}
											onValueChange={ ( value ) =>
												set(
													'access_role',
													'none' === value
														? ''
														: value
												)
											}
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
												{ roles.map( ( role ) => (
													<SelectItem
														key={ role.id }
														value={ String(
															role.id
														) }
													>
														{ role.name }
													</SelectItem>
												) ) }
											</SelectContent>
										</Select>
									</Field>
									<Field
										label={ __(
											'PIN (optional)',
											'radius-hotel-booking-pro'
										) }
										error={ errors.pin }
										description={ __(
											'4 to 8 digits, to confirm protected actions.',
											'radius-hotel-booking-pro'
										) }
									>
										{ input( 'pin', {
											inputMode: 'numeric',
											type: 'password',
											maxLength: 8,
											autoComplete: 'new-password',
										} ) }
									</Field>
								</div>
							) : null }

							<div className="flex justify-between gap-2">
								<Button
									type="button"
									variant="ghost"
									onClick={ () => setStep( 1 ) }
								>
									{ __( 'Back', 'radius-hotel-booking-pro' ) }
								</Button>
								<Button
									type="submit"
									disabled={ create.isPending }
								>
									{ __(
										'Add employee',
										'radius-hotel-booking-pro'
									) }
								</Button>
							</div>
						</form>
					) }
				</Panel>
			</div>
		</Suspense>
	);
}

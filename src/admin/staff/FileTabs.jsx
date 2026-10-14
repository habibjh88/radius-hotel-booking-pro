/**
 * The personal parts of an employee file (12.3, 12.6–12.8, 12.10): the
 * Personal tab (name, date of birth, family, address and the statutory
 * numbers), Contact (phone, e-mail) and Emergency contact. Each tab saves
 * only the fields that changed through `PUT employees/{id}`; without
 * `staff.edit` the fields are read-only.
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Eye, EyeOff, Landmark, Pencil } from 'lucide-react';
import {
	Button,
	Field,
	Input,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Textarea,
} from '@rtbp/ui';
import { format, toast, toastError } from '@rtbp/lib';

import { DateField, PhoneField } from '../pickers';
import { revealIdentifiers, useStaffOptions, useUpdateEmployee } from './api';

// Revealed numbers hide again after a minute.
const SHOWN_FOR_MS = 60 * 1000;

/**
 * The values a form edits, taken from the employee.
 *
 * @param {Object}   employee Employee (detail shape).
 * @param {string[]} fields   Field names.
 * @return {Object} Values.
 */
const pick = ( employee, fields ) =>
	Object.fromEntries(
		fields.map( ( field ) => [
			field,
			'emergency' === field
				? { ...( employee.emergency || {} ) }
				: String( employee[ field ] ?? '' ),
		] )
	);

/**
 * A form over some fields of the file: local values, the changed ones sent
 * on save, server errors on the fields.
 *
 * @param {Object}   employee Employee.
 * @param {string[]} fields   Field names.
 * @return {Object} `{ values, set, errors, changed, saving, save, reset }`.
 */
export function useFileForm( employee, fields ) {
	const update = useUpdateEmployee( employee.id );
	const [ values, setValues ] = useState( () => pick( employee, fields ) );
	const [ errors, setErrors ] = useState( {} );
	const original = pick( employee, fields );
	const changed = fields.filter(
		( field ) =>
			JSON.stringify( values[ field ] ) !==
			JSON.stringify( original[ field ] )
	);

	// Follow the file when it changes elsewhere (another tab saved).
	const stamp = JSON.stringify( original );
	useEffect( () => {
		setValues( JSON.parse( stamp ) );
		setErrors( {} );
	}, [ stamp ] );

	const set = ( field, value ) => {
		setValues( ( prev ) => ( { ...prev, [ field ]: value } ) );
		setErrors( ( prev ) => ( { ...prev, [ field ]: undefined } ) );
	};

	const save = async ( event ) => {
		event.preventDefault();
		if ( ! changed.length ) {
			return;
		}
		try {
			await update.mutateAsync(
				Object.fromEntries(
					changed.map( ( field ) => [ field, values[ field ] ] )
				)
			);
			toast.success( __( 'Saved.', 'radius-hotel-booking-pro' ) );
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
		}
	};

	return {
		values,
		set,
		errors,
		changed: changed.length > 0,
		saving: update.isPending,
		save,
		reset: () => {
			setValues( original );
			setErrors( {} );
		},
	};
}

/**
 * Save / Undo under a form (hidden when read-only).
 *
 * @param {Object}  props         Props.
 * @param {Object}  props.form    useFileForm() result.
 * @param {boolean} props.canEdit Viewer has `staff.edit`.
 * @return {JSX.Element|null} Buttons.
 */
export function FormActions( { form, canEdit } ) {
	if ( ! canEdit ) {
		return null;
	}
	return (
		<div className="flex flex-wrap justify-end gap-2">
			{ form.changed ? (
				<Button
					type="button"
					variant="ghost"
					disabled={ form.saving }
					onClick={ form.reset }
				>
					{ __( 'Undo changes', 'radius-hotel-booking-pro' ) }
				</Button>
			) : null }
			<Button type="submit" disabled={ ! form.changed || form.saving }>
				{ __( 'Save', 'radius-hotel-booking-pro' ) }
			</Button>
		</div>
	);
}

/**
 * A select over `{ value: label }` with a "not given" choice.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.value    Value ('' for none).
 * @param {Object}   props.choices  Value => label.
 * @param {Function} props.onChange Called with the new value.
 * @param {boolean}  props.disabled Read-only.
 * @return {JSX.Element} Select.
 */
export function ChoiceSelect( {
	value,
	choices,
	onChange,
	disabled,
	...rest
} ) {
	return (
		<Select
			value={ value || 'none' }
			onValueChange={ ( next ) =>
				onChange( 'none' === next ? '' : next )
			}
			disabled={ disabled }
		>
			<SelectTrigger { ...rest }>
				<SelectValue />
			</SelectTrigger>
			<SelectContent className="rtbp-root">
				<SelectItem value="none">
					{ __( 'Not given', 'radius-hotel-booking-pro' ) }
				</SelectItem>
				{ Object.entries( choices || {} ).map( ( [ key, label ] ) => (
					<SelectItem key={ key } value={ key }>
						{ label }
					</SelectItem>
				) ) }
			</SelectContent>
		</Select>
	);
}

/**
 * The social security and tax numbers (12.10): masked; revealed on request (logged)
 * for `staff.view_ids`, and changed from the revealed values.
 *
 * @param {Object}  props          Props.
 * @param {Object}  props.employee Employee.
 * @param {boolean} props.canEdit  Viewer has `staff.edit`.
 * @return {JSX.Element} Block.
 */
function StatutoryNumbers( { employee, canEdit } ) {
	const update = useUpdateEmployee( employee.id );
	const [ numbers, setNumbers ] = useState( null );
	const [ editing, setEditing ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ errors, setErrors ] = useState( {} );

	useEffect( () => {
		if ( null === numbers || editing ) {
			return undefined;
		}
		const timer = setTimeout( () => setNumbers( null ), SHOWN_FOR_MS );
		return () => clearTimeout( timer );
	}, [ numbers, editing ] );
	useEffect( () => {
		setNumbers( null );
		setEditing( false );
	}, [ employee.id, employee.cnps_no_masked, employee.tax_no_masked ] );

	const reveal = async ( edit ) => {
		setLoading( true );
		try {
			setNumbers( await revealIdentifiers( employee.id ) );
			setEditing( edit );
		} catch ( err ) {
			toastError( err );
		} finally {
			setLoading( false );
		}
	};

	const save = async ( event ) => {
		event.preventDefault();
		try {
			await update.mutateAsync( numbers );
			toast.success( __( 'Saved.', 'radius-hotel-booking-pro' ) );
			setEditing( false );
			setNumbers( null );
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
		}
	};

	const rows = [
		{
			key: 'cnps_no',
			label: __( 'Social security number', 'radius-hotel-booking-pro' ),
			masked: employee.cnps_no_masked,
		},
		{
			key: 'tax_no',
			label: __( 'Tax number', 'radius-hotel-booking-pro' ),
			masked: employee.tax_no_masked,
		},
	];

	return (
		<section className="space-y-3 rounded-lg border border-border p-4">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<h3 className="m-0 flex items-center gap-2 text-sm font-semibold text-heading">
					<Landmark
						className="h-4 w-4 text-muted-foreground"
						aria-hidden="true"
					/>
					{ __( 'Statutory numbers', 'radius-hotel-booking-pro' ) }
				</h3>
				{ employee.can_view_ids && ! editing ? (
					<div className="flex flex-wrap gap-2">
						{ employee.has_ids ? (
							<Button
								type="button"
								size="sm"
								variant="outline"
								disabled={ loading }
								onClick={ () =>
									numbers
										? setNumbers( null )
										: reveal( false )
								}
							>
								{ numbers ? (
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
								{ numbers
									? __( 'Hide', 'radius-hotel-booking-pro' )
									: __(
											'Show numbers',
											'radius-hotel-booking-pro'
									  ) }
							</Button>
						) : null }
						{ canEdit ? (
							<Button
								type="button"
								size="sm"
								variant="outline"
								disabled={ loading }
								onClick={ () => reveal( true ) }
							>
								<Pencil
									className="h-4 w-4"
									aria-hidden="true"
								/>
								{ __( 'Change', 'radius-hotel-booking-pro' ) }
							</Button>
						) : null }
					</div>
				) : null }
			</div>

			{ editing ? (
				<form className="space-y-3" onSubmit={ save }>
					<div className="grid gap-4 sm:grid-cols-2">
						{ rows.map( ( row ) => (
							<Field
								key={ row.key }
								label={ row.label }
								error={ errors[ row.key ] }
							>
								<Input
									value={ numbers?.[ row.key ] ?? '' }
									maxLength={ 40 }
									autoComplete="off"
									onChange={ ( e ) => {
										setNumbers( ( prev ) => ( {
											...prev,
											[ row.key ]: e.target.value,
										} ) );
										setErrors( {} );
									} }
								/>
							</Field>
						) ) }
					</div>
					<div className="flex flex-wrap justify-end gap-2">
						<Button
							type="button"
							variant="ghost"
							disabled={ update.isPending }
							onClick={ () => {
								setEditing( false );
								setNumbers( null );
								setErrors( {} );
							} }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button type="submit" disabled={ update.isPending }>
							{ __( 'Save', 'radius-hotel-booking-pro' ) }
						</Button>
					</div>
				</form>
			) : (
				<dl className="m-0 grid gap-4 sm:grid-cols-2">
					{ rows.map( ( row ) => (
						<div key={ row.key } className="min-w-0">
							<dt className="text-xs text-muted-foreground">
								{ row.label }
							</dt>
							<dd
								className="m-0 font-mono text-sm font-semibold text-heading"
								aria-live="polite"
							>
								{ ( numbers
									? numbers[ row.key ]
									: row.masked ) || '—' }
							</dd>
						</div>
					) ) }
				</dl>
			) }
		</section>
	);
}

/**
 * Personal: name, date of birth, marital status, children, address, and
 * the statutory numbers.
 *
 * @param {Object}  props          Props.
 * @param {Object}  props.employee Employee (detail shape).
 * @param {boolean} props.canEdit  Viewer has `staff.edit`.
 * @return {JSX.Element} Tab.
 */
export function PersonalTab( { employee, canEdit } ) {
	const options = useStaffOptions();
	const form = useFileForm( employee, [
		'first_name',
		'last_name',
		'dob',
		'marital_status',
		'num_children',
		'address',
	] );
	const input = ( name, props = {} ) => (
		<Input
			value={ form.values[ name ] }
			onChange={ ( e ) => form.set( name, e.target.value ) }
			disabled={ ! canEdit }
			autoComplete="off"
			{ ...props }
		/>
	);

	return (
		<div className="space-y-4">
			<form className="space-y-4" onSubmit={ form.save }>
				<div className="grid gap-4 sm:grid-cols-2">
					<Field
						label={ __( 'First name', 'radius-hotel-booking-pro' ) }
						error={ form.errors.first_name }
						required
					>
						{ input( 'first_name', { maxLength: 100 } ) }
					</Field>
					<Field
						label={ __( 'Last name', 'radius-hotel-booking-pro' ) }
						error={ form.errors.last_name }
						required
					>
						{ input( 'last_name', { maxLength: 100 } ) }
					</Field>
					<Field
						label={ __(
							'Date of birth',
							'radius-hotel-booking-pro'
						) }
						error={ form.errors.dob }
					>
						<DateField
							value={ form.values.dob }
							onChange={ ( day ) => form.set( 'dob', day ) }
							disabled={ ! canEdit }
							max={ format.siteToday() }
							yearDropdown
							clearable
						/>
					</Field>
					<Field
						label={ __(
							'Marital status',
							'radius-hotel-booking-pro'
						) }
						error={ form.errors.marital_status }
					>
						<ChoiceSelect
							value={ form.values.marital_status }
							choices={ options.data?.marital }
							onChange={ ( value ) =>
								form.set( 'marital_status', value )
							}
							disabled={ ! canEdit }
						/>
					</Field>
					<Field
						label={ __(
							'Number of children',
							'radius-hotel-booking-pro'
						) }
						error={ form.errors.num_children }
					>
						{ input( 'num_children', {
							type: 'number',
							min: 0,
							max: 30,
							inputMode: 'numeric',
						} ) }
					</Field>
				</div>
				<Field
					label={ __( 'Address', 'radius-hotel-booking-pro' ) }
					error={ form.errors.address }
				>
					<Textarea
						rows={ 3 }
						value={ form.values.address }
						onChange={ ( e ) =>
							form.set( 'address', e.target.value )
						}
						disabled={ ! canEdit }
					/>
				</Field>
				<FormActions form={ form } canEdit={ canEdit } />
			</form>
			<StatutoryNumbers employee={ employee } canEdit={ canEdit } />
		</div>
	);
}

/**
 * Contact: phone (required) and e-mail. The e-mail is also the account's.
 *
 * @param {Object}  props          Props.
 * @param {Object}  props.employee Employee (detail shape).
 * @param {boolean} props.canEdit  Viewer has `staff.edit`.
 * @return {JSX.Element} Tab.
 */
export function ContactTab( { employee, canEdit } ) {
	const form = useFileForm( employee, [ 'phone', 'email' ] );
	return (
		<form className="space-y-4" onSubmit={ form.save }>
			<div className="grid gap-4 sm:grid-cols-2">
				<Field
					label={ __( 'Phone', 'radius-hotel-booking-pro' ) }
					error={ form.errors.phone }
					required
				>
					<PhoneField
						value={ form.values.phone }
						onChange={ ( phone ) => form.set( 'phone', phone ) }
						disabled={ ! canEdit }
					/>
				</Field>
				<Field
					label={ __( 'E-mail', 'radius-hotel-booking-pro' ) }
					error={ form.errors.email }
					description={ __(
						'Also the e-mail of their account: password links go here.',
						'radius-hotel-booking-pro'
					) }
				>
					<Input
						type="email"
						value={ form.values.email }
						onChange={ ( e ) =>
							form.set( 'email', e.target.value )
						}
						disabled={ ! canEdit }
						autoComplete="off"
					/>
				</Field>
			</div>
			<FormActions form={ form } canEdit={ canEdit } />
		</form>
	);
}

/**
 * Emergency contact (12.8): full name, relationship, phone, address.
 *
 * @param {Object}  props          Props.
 * @param {Object}  props.employee Employee (detail shape).
 * @param {boolean} props.canEdit  Viewer has `staff.edit`.
 * @return {JSX.Element} Tab.
 */
export function EmergencyTab( { employee, canEdit } ) {
	const options = useStaffOptions();
	const form = useFileForm( employee, [ 'emergency' ] );
	const contact = form.values.emergency || {};
	const set = ( key, value ) =>
		form.set( 'emergency', { ...contact, [ key ]: value } );

	return (
		<form className="space-y-4" onSubmit={ form.save }>
			<div className="grid gap-4 sm:grid-cols-2">
				<Field label={ __( 'Full name', 'radius-hotel-booking-pro' ) }>
					<Input
						value={ contact.full_name || '' }
						onChange={ ( e ) => set( 'full_name', e.target.value ) }
						disabled={ ! canEdit }
						autoComplete="off"
					/>
				</Field>
				<Field
					label={ __( 'Relationship', 'radius-hotel-booking-pro' ) }
					error={ form.errors[ 'emergency.relationship' ] }
				>
					<ChoiceSelect
						value={ contact.relationship || '' }
						choices={ options.data?.relationships }
						onChange={ ( value ) => set( 'relationship', value ) }
						disabled={ ! canEdit }
					/>
				</Field>
				<Field label={ __( 'Phone', 'radius-hotel-booking-pro' ) }>
					<PhoneField
						value={ contact.phone || '' }
						onChange={ ( phone ) => set( 'phone', phone ) }
						disabled={ ! canEdit }
					/>
				</Field>
			</div>
			<Field label={ __( 'Address', 'radius-hotel-booking-pro' ) }>
				<Textarea
					rows={ 3 }
					value={ contact.address || '' }
					onChange={ ( e ) => set( 'address', e.target.value ) }
					disabled={ ! canEdit }
				/>
			</Field>
			<FormActions form={ form } canEdit={ canEdit } />
		</form>
	);
}

/**
 * Employment (12.9, 12.11): the current job (title, department, work
 * shift — a plain label until shifts get their own list) and the contract
 * history, newest first. Adding an active contract ends the one before it
 * on the day before the new start; the active contract sets the job title
 * and department. Contracts need `staff.contracts`; ending one does not
 * deactivate the employee.
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { BriefcaseBusiness, FilePenLine, Plus } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	EmptyState,
	Field,
	Input,
	SegmentedControl,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
	StatusBadge,
	Textarea,
} from '@rtbp/ui';
import { format, toast, toastError } from '@rtbp/lib';

import { useContractMutation, useContracts } from './api';
import { FormActions, useFileForm } from './FileTabs';

const EMPTY = {
	status: 'active',
	job_type: 'full_time',
	job_title: '',
	department: '',
	start_date: '',
	end_date: '',
	notes: '',
};

/**
 * Server field errors as `{ field: message }`.
 *
 * @param {Error} err API error.
 * @return {Object} Messages.
 */
const fieldErrors = ( err ) =>
	Object.fromEntries(
		Object.entries( err?.errors || {} ).map( ( [ field, value ] ) => [
			field,
			value?.first_message,
		] )
	);

/**
 * `6 Jan 2025 – 28 Feb 2026`, or `Since 6 Jan 2025`.
 *
 * @param {Object} contract Contract.
 * @return {string} Period.
 */
const period = ( contract ) =>
	contract.end_date
		? sprintf(
				/* translators: 1: start date, 2: end date. */
				__( '%1$s – %2$s', 'radius-hotel-booking-pro' ),
				format.formatDate( contract.start_date ),
				format.formatDate( contract.end_date )
		  )
		: sprintf(
				/* translators: %s: start date. */
				__( 'Since %s', 'radius-hotel-booking-pro' ),
				format.formatDate( contract.start_date )
		  );

/**
 * Add or change a contract.
 *
 * @param {Object}   props           Props.
 * @param {boolean}  props.open      Open.
 * @param {Function} props.onClose   Close.
 * @param {Object}   props.contract  Contract to change, or null to add one.
 * @param {Object}   props.jobTypes  Value => label.
 * @param {Object}   props.mutation  useContractMutation() result.
 * @param {boolean}  props.hasActive Another contract is active.
 * @return {JSX.Element} Dialog.
 */
function ContractDialog( {
	open,
	onClose,
	contract,
	jobTypes,
	mutation,
	hasActive,
} ) {
	const [ values, setValues ] = useState( EMPTY );
	const [ errors, setErrors ] = useState( {} );

	useEffect( () => {
		if ( open ) {
			setValues(
				contract
					? {
							...EMPTY,
							...Object.fromEntries(
								Object.keys( EMPTY ).map( ( key ) => [
									key,
									contract[ key ] ?? '',
								] )
							),
					  }
					: EMPTY
			);
			setErrors( {} );
		}
	}, [ open, contract ] );

	const set = ( field, value ) => {
		setValues( ( prev ) => ( { ...prev, [ field ]: value } ) );
		setErrors( ( prev ) => ( { ...prev, [ field ]: undefined } ) );
	};

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			await mutation.mutateAsync(
				contract
					? {
							action: 'update',
							contract: contract.id,
							fields: values,
					  }
					: { action: 'create', fields: values }
			);
			toast.success(
				contract
					? __( 'Contract saved.', 'radius-hotel-booking-pro' )
					: __( 'Contract added.', 'radius-hotel-booking-pro' )
			);
			onClose();
		} catch ( err ) {
			const mapped = fieldErrors( err );
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	const input = ( name, props = {} ) => (
		<Input
			value={ values[ name ] }
			onChange={ ( e ) => set( name, e.target.value ) }
			autoComplete="off"
			{ ...props }
		/>
	);

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-w-lg">
				<form className="space-y-4" onSubmit={ submit }>
					<DialogHeader>
						<DialogTitle>
							{ contract
								? __(
										'Change the contract',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Add a contract',
										'radius-hotel-booking-pro'
								  ) }
						</DialogTitle>
						{ ! contract && hasActive ? (
							<DialogDescription>
								{ __(
									'An active contract ends the current one on the day before it starts.',
									'radius-hotel-booking-pro'
								) }
							</DialogDescription>
						) : null }
					</DialogHeader>
					<SegmentedControl
						label={ __( 'Status', 'radius-hotel-booking-pro' ) }
						value={ values.status }
						onChange={ ( value ) => set( 'status', value ) }
						options={ [
							{
								value: 'active',
								label: __(
									'Active',
									'radius-hotel-booking-pro'
								),
							},
							{
								value: 'terminated',
								label: __(
									'Ended',
									'radius-hotel-booking-pro'
								),
							},
						] }
					/>
					<div className="grid gap-4 sm:grid-cols-2">
						<Field
							label={ __(
								'Job type',
								'radius-hotel-booking-pro'
							) }
							error={ errors.job_type }
						>
							<Select
								value={ values.job_type }
								onValueChange={ ( value ) =>
									set( 'job_type', value )
								}
							>
								<SelectTrigger>
									<SelectValue />
								</SelectTrigger>
								<SelectContent className="rtbp-root">
									{ Object.entries( jobTypes || {} ).map(
										( [ key, label ] ) => (
											<SelectItem
												key={ key }
												value={ key }
											>
												{ label }
											</SelectItem>
										)
									) }
								</SelectContent>
							</Select>
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
							{ input( 'department', { maxLength: 100 } ) }
						</Field>
						<div className="hidden sm:block" />
						<Field
							label={ __(
								'Start date',
								'radius-hotel-booking-pro'
							) }
							error={ errors.start_date }
							required
						>
							{ input( 'start_date', { type: 'date' } ) }
						</Field>
						<Field
							label={ __(
								'End date',
								'radius-hotel-booking-pro'
							) }
							error={ errors.end_date }
							required={ 'terminated' === values.status }
						>
							{ input( 'end_date', { type: 'date' } ) }
						</Field>
					</div>
					<Field
						label={ __( 'Notes', 'radius-hotel-booking-pro' ) }
						error={ errors.notes }
					>
						<Textarea
							rows={ 2 }
							value={ values.notes }
							maxLength={ 2000 }
							onChange={ ( e ) => set( 'notes', e.target.value ) }
						/>
					</Field>
					<DialogFooter>
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button type="submit" disabled={ mutation.isPending }>
							{ contract
								? __( 'Save', 'radius-hotel-booking-pro' )
								: __(
										'Add contract',
										'radius-hotel-booking-pro'
								  ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

/**
 * End an active contract: the last day and why.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.contract Contract, or null (closed).
 * @param {Function} props.onClose  Close.
 * @param {Object}   props.mutation useContractMutation() result.
 * @return {JSX.Element} Dialog.
 */
function EndDialog( { contract, onClose, mutation } ) {
	const [ endDate, setEndDate ] = useState( '' );
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( contract ) {
			setEndDate( format.siteToday ? format.siteToday() : '' );
			setReason( '' );
			setError( '' );
		}
	}, [ contract ] );

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			await mutation.mutateAsync( {
				action: 'terminate',
				contract: contract.id,
				fields: { end_date: endDate },
				reason,
			} );
			toast.success(
				__( 'Contract ended.', 'radius-hotel-booking-pro' )
			);
			onClose();
		} catch ( err ) {
			const mapped = fieldErrors( err );
			if ( mapped.end_date ) {
				setError( mapped.end_date );
			} else {
				toastError( err );
			}
		}
	};

	return (
		<Dialog
			open={ Boolean( contract ) }
			onOpenChange={ ( next ) => ! next && onClose() }
		>
			<DialogContent className="rtbp-root max-w-md">
				<form className="space-y-4" onSubmit={ submit }>
					<DialogHeader>
						<DialogTitle>
							{ __(
								'End this contract?',
								'radius-hotel-booking-pro'
							) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'The employee stays active and can still sign in. Deactivate them separately if they leave.',
								'radius-hotel-booking-pro'
							) }
						</DialogDescription>
					</DialogHeader>
					<Field
						label={ __( 'Last day', 'radius-hotel-booking-pro' ) }
						error={ error }
						required
					>
						<Input
							type="date"
							value={ endDate }
							onChange={ ( e ) => {
								setEndDate( e.target.value );
								setError( '' );
							} }
						/>
					</Field>
					<Field
						label={ __(
							'Reason (optional)',
							'radius-hotel-booking-pro'
						) }
					>
						<Textarea
							rows={ 2 }
							value={ reason }
							onChange={ ( e ) => setReason( e.target.value ) }
						/>
					</Field>
					<DialogFooter>
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button
							type="submit"
							variant="destructive"
							disabled={ mutation.isPending || ! endDate }
						>
							{ __( 'End contract', 'radius-hotel-booking-pro' ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

/**
 * @param {Object}  props              Props.
 * @param {Object}  props.employee     Employee (detail shape).
 * @param {boolean} props.canEdit      Viewer has `staff.edit`.
 * @param {boolean} props.canContracts Viewer has `staff.contracts`.
 * @return {JSX.Element} Tab.
 */
export default function EmploymentTab( { employee, canEdit, canContracts } ) {
	const form = useFileForm( employee, [
		'job_title',
		'department',
		'shift',
	] );
	const contracts = useContracts( employee.id );
	const mutation = useContractMutation( employee.id );
	const [ editing, setEditing ] = useState( null );
	const [ ending, setEnding ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );

	const list = contracts.data?.contracts || [];
	const hasActive = list.some( ( c ) => 'active' === c.status );

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
			<section className="space-y-3 rounded-lg border border-border p-4">
				<h3 className="m-0 flex items-center gap-2 text-sm font-semibold text-heading">
					<BriefcaseBusiness
						className="h-4 w-4 text-muted-foreground"
						aria-hidden="true"
					/>
					{ __( 'Current job', 'radius-hotel-booking-pro' ) }
				</h3>
				<form className="space-y-4" onSubmit={ form.save }>
					<div className="grid gap-4 sm:grid-cols-2">
						<Field
							label={ __(
								'Job title',
								'radius-hotel-booking-pro'
							) }
							error={ form.errors.job_title }
						>
							{ input( 'job_title', { maxLength: 100 } ) }
						</Field>
						<Field
							label={ __(
								'Department',
								'radius-hotel-booking-pro'
							) }
							error={ form.errors.department }
						>
							{ input( 'department', { maxLength: 100 } ) }
						</Field>
						<Field
							label={ __(
								'Work shift',
								'radius-hotel-booking-pro'
							) }
							error={ form.errors.shift }
							description={ __(
								'For example Morning, Day or Night.',
								'radius-hotel-booking-pro'
							) }
						>
							{ input( 'shift', { maxLength: 60 } ) }
						</Field>
					</div>
					<FormActions form={ form } canEdit={ canEdit } />
				</form>
			</section>

			<section className="space-y-3 rounded-lg border border-border p-4">
				<div className="flex flex-wrap items-center justify-between gap-2">
					<h3 className="m-0 flex items-center gap-2 text-sm font-semibold text-heading">
						<FilePenLine
							className="h-4 w-4 text-muted-foreground"
							aria-hidden="true"
						/>
						{ __( 'Contracts', 'radius-hotel-booking-pro' ) }
					</h3>
					{ canContracts ? (
						<Button
							type="button"
							size="sm"
							variant="outline"
							onClick={ () => setEditing( 'new' ) }
						>
							<Plus className="h-4 w-4" aria-hidden="true" />
							{ __( 'Add contract', 'radius-hotel-booking-pro' ) }
						</Button>
					) : null }
				</div>

				{ contracts.isPending ? (
					<Skeleton className="h-20 w-full" />
				) : null }
				{ contracts.error ? (
					<div className="flex flex-wrap items-center justify-between gap-2 text-sm text-destructive">
						{ contracts.error.message }
						<Button
							type="button"
							size="sm"
							variant="outline"
							onClick={ () => contracts.refetch() }
						>
							{ __( 'Try again', 'radius-hotel-booking-pro' ) }
						</Button>
					</div>
				) : null }
				{ contracts.data && ! list.length ? (
					<EmptyState
						icon={ FilePenLine }
						title={ __(
							'No contracts yet',
							'radius-hotel-booking-pro'
						) }
						description={ __(
							'Record each contract: its type, job, and start and end dates.',
							'radius-hotel-booking-pro'
						) }
						className="border-0 py-6"
					/>
				) : null }
				{ list.length ? (
					<ul className="m-0 list-none space-y-3 p-0">
						{ list.map( ( contract ) => (
							<li
								key={ contract.id }
								className="space-y-2 rounded-lg border border-border p-3"
							>
								<div className="flex flex-wrap items-start justify-between gap-2">
									<div className="min-w-0 space-y-1">
										<div className="flex flex-wrap items-center gap-2">
											<span className="text-sm font-semibold text-heading">
												{ contract.job_title ||
													contract.job_type_label }
											</span>
											<StatusBadge
												domain="active"
												value={
													'active' === contract.status
														? 'active'
														: 'ended'
												}
											/>
										</div>
										<p className="m-0 text-sm text-muted-foreground">
											{ [
												contract.job_type_label,
												contract.department,
												period( contract ),
											]
												.filter( Boolean )
												.join( ' · ' ) }
										</p>
									</div>
									{ canContracts ? (
										<div className="flex flex-wrap gap-2">
											<Button
												type="button"
												size="sm"
												variant="outline"
												onClick={ () =>
													setEditing( contract )
												}
											>
												{ __(
													'Change',
													'radius-hotel-booking-pro'
												) }
											</Button>
											{ 'active' === contract.status ? (
												<Button
													type="button"
													size="sm"
													variant="outline"
													onClick={ () =>
														setEnding( contract )
													}
												>
													{ __(
														'End',
														'radius-hotel-booking-pro'
													) }
												</Button>
											) : null }
											<Button
												type="button"
												size="sm"
												variant="ghost"
												className="text-destructive"
												onClick={ () =>
													setDeleting( contract )
												}
											>
												{ __(
													'Delete',
													'radius-hotel-booking-pro'
												) }
											</Button>
										</div>
									) : null }
								</div>
								{ contract.notes ? (
									<p
										className="m-0 text-sm text-muted-foreground"
										style={ { whiteSpace: 'pre-line' } }
									>
										{ contract.notes }
									</p>
								) : null }
							</li>
						) ) }
					</ul>
				) : null }
			</section>

			<ContractDialog
				open={ null !== editing }
				onClose={ () => setEditing( null ) }
				contract={ 'new' === editing ? null : editing }
				jobTypes={ contracts.data?.job_types }
				mutation={ mutation }
				hasActive={ hasActive }
			/>
			<EndDialog
				contract={ ending }
				onClose={ () => setEnding( null ) }
				mutation={ mutation }
			/>
			<ConfirmDialog
				open={ Boolean( deleting ) }
				onOpenChange={ ( next ) => ! next && setDeleting( null ) }
				title={ __(
					'Delete this contract?',
					'radius-hotel-booking-pro'
				) }
				description={ __(
					'Only for a contract entered by mistake: it leaves the history. To close a contract, end it instead.',
					'radius-hotel-booking-pro'
				) }
				confirmLabel={ __( 'Delete', 'radius-hotel-booking-pro' ) }
				destructive
				requireReason
				onConfirm={ ( reason ) =>
					mutation
						.mutateAsync( {
							action: 'delete',
							contract: deleting.id,
							reason,
						} )
						.then( () => {
							setDeleting( null );
							toast.success(
								__(
									'Contract deleted.',
									'radius-hotel-booking-pro'
								)
							);
						} )
				}
			/>
		</div>
	);
}

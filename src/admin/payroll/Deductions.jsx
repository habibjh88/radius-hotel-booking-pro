/**
 * Deductions and garnishments of an employee (M15 T4d, 12.15), on the *Pay*
 * tab: deductions are taken before tax (health insurance, other — with an
 * optional employer share), garnishments after tax (a court order up to a
 * % of disposable pay; another debt to recover). A run takes them (T5a).
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { FilePenLine, Plus, Trash2 } from 'lucide-react';
import {
	Badge,
	Button,
	ConfirmDialog,
	Dialog,
	DialogContent,
	DialogFooter,
	DialogHeader,
	DialogTitle,
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
import { format, toast, toastError } from '@rtbp/lib';

import { DateField } from '../pickers';
import { useDeductionMutation, useDeductions } from './api';

/**
 * The four kinds of row, as one choice.
 *
 * @return {Array<{value: string, kind: string, type: string, label: string}>} Choices.
 */
const choices = () => [
	{
		value: 'deduction:health_insurance',
		kind: 'deduction',
		type: 'health_insurance',
		label: __( 'Health insurance', 'radius-hotel-booking-pro' ),
	},
	{
		value: 'deduction:other',
		kind: 'deduction',
		type: 'other',
		label: __( 'Other deduction', 'radius-hotel-booking-pro' ),
	},
	{
		value: 'garnishment:court_order',
		kind: 'garnishment',
		type: 'court_order',
		label: __( 'Court order', 'radius-hotel-booking-pro' ),
	},
	{
		value: 'garnishment:other',
		kind: 'garnishment',
		type: 'other',
		label: __( 'Other garnishment', 'radius-hotel-booking-pro' ),
	},
];

const STATUS_LABELS = () => ( {
	active: __( 'Active', 'radius-hotel-booking-pro' ),
	suspended: __( 'Suspended', 'radius-hotel-booking-pro' ),
	completed: __( 'Completed', 'radius-hotel-booking-pro' ),
} );

const BLANK = {
	name: '',
	authority: '',
	remittance_day: '',
	calculation: 'per_payslip',
	start_date: '',
	end_date: '',
	amount_type: 'amount',
	amount: '',
	percent: '',
	employer_amount_type: 'none',
	employer_amount: '',
	employer_percent: '',
	total_amount: '',
	max_percent: '',
	status: 'active',
};

/**
 * How much a row takes, in a few words.
 *
 * @param {Object} row Row.
 * @return {string} Text.
 */
const takes = ( row ) => {
	if ( 'court_order' === row.type ) {
		return sprintf(
			/* translators: 1: amount, 2: percentage. */
			__(
				'%1$s a pay period, at most %2$s %% of take-home pay',
				'radius-hotel-booking-pro'
			),
			format.formatMoney( row.total_amount ),
			Number( row.max_percent )
		);
	}
	const share =
		'percent' === row.amount_type
			? sprintf(
					/* translators: %s: percentage. */
					__( '%s %%', 'radius-hotel-booking-pro' ),
					Number( row.percent )
			  )
			: format.formatMoney( row.amount );
	const per =
		'per_month' === row.calculation
			? __( 'a month', 'radius-hotel-booking-pro' )
			: __( 'a payslip', 'radius-hotel-booking-pro' );
	if ( 'garnishment' === row.kind ) {
		return sprintf(
			/* translators: 1: amount or %, 2: "a month" / "a payslip", 3: amount left. */
			__(
				'%1$s %2$s — %3$s left to recover',
				'radius-hotel-booking-pro'
			),
			share,
			per,
			format.formatMoney( row.remaining_amount )
		);
	}
	return sprintf(
		/* translators: 1: amount or %, 2: "a month" / "a payslip". */
		__( '%1$s %2$s', 'radius-hotel-booking-pro' ),
		share,
		per
	);
};

/**
 * A field error from the API client as text.
 *
 * @param {*} value Error.
 * @return {string} Message.
 */
const message = ( value ) =>
	value?.first_message ??
	( Array.isArray( value ) ? value[ 0 ] : String( value ) );

/**
 * Add or change a row.
 *
 * @param {Object}   props            Props.
 * @param {boolean}  props.open       Open.
 * @param {Object}   props.row        The row being changed, or null.
 * @param {number}   props.employeeId Employee.
 * @param {Function} props.onClose    Close.
 * @return {JSX.Element} Dialog.
 */
function DeductionDialog( { open, row, employeeId, onClose } ) {
	const [ choice, setChoice ] = useState( 'deduction:health_insurance' );
	const [ values, setValues ] = useState( BLANK );
	const [ errors, setErrors ] = useState( {} );
	const mutation = useDeductionMutation( employeeId );

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		setChoice(
			row ? `${ row.kind }:${ row.type }` : 'deduction:health_insurance'
		);
		setValues(
			row
				? {
						...BLANK,
						...Object.fromEntries(
							Object.entries( row ).map( ( [ k, v ] ) => [
								k,
								null === v ? '' : String( v ),
							] )
						),
				  }
				: BLANK
		);
		setErrors( {} );
	}, [ open, row ] );

	const [ kind, type ] = choice.split( ':' );
	const set = ( key, value ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
		setErrors( ( current ) => ( { ...current, [ key ]: undefined } ) );
	};
	const text = ( key, label, props = {} ) => (
		<Field
			label={ label }
			error={ errors[ key ] }
			required={ props.required }
			description={ props.description }
		>
			<Input
				value={ values[ key ] }
				onChange={ ( e ) => set( key, e.target.value ) }
				autoComplete="off"
				{ ...props.input }
			/>
		</Field>
	);
	const dateField = ( key, label, props = {} ) => (
		<Field
			label={ label }
			error={ errors[ key ] }
			required={ props.required }
		>
			<DateField
				value={ values[ key ] }
				onChange={ ( day ) => set( key, day ) }
				min={
					'end_date' === key
						? values.start_date || undefined
						: undefined
				}
				clearable={ ! props.required }
			/>
		</Field>
	);
	const share = ( prefix, label, optional ) => {
		const typeKey = `${ prefix }amount_type`;
		const options = [
			...( optional
				? [
						{
							value: 'none',
							label: __( 'None', 'radius-hotel-booking-pro' ),
						},
				  ]
				: [] ),
			{
				value: 'amount',
				label: __( 'An amount', 'radius-hotel-booking-pro' ),
			},
			{
				value: 'percent',
				label: __( 'A percentage', 'radius-hotel-booking-pro' ),
			},
		];
		return (
			<div className="space-y-2">
				<Field label={ label } error={ errors[ typeKey ] }>
					<SegmentedControl
						label={ label }
						value={
							values[ typeKey ] ||
							( optional ? 'none' : 'amount' )
						}
						onChange={ ( v ) => set( typeKey, v ) }
						options={ options }
					/>
				</Field>
				{ 'amount' === values[ typeKey ]
					? text(
							`${ prefix }amount`,
							__( 'Amount', 'radius-hotel-booking-pro' ),
							{
								required: true,
								input: {
									type: 'number',
									min: 0,
									step: 'any',
									inputMode: 'decimal',
								},
							}
					  )
					: null }
				{ 'percent' === values[ typeKey ]
					? text(
							`${ prefix }percent`,
							__( 'Percentage', 'radius-hotel-booking-pro' ),
							{
								required: true,
								input: {
									type: 'number',
									min: 0,
									max: 100,
									step: 'any',
									inputMode: 'decimal',
								},
							}
					  )
					: null }
			</div>
		);
	};

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await mutation.mutateAsync( {
				id: row?.id,
				...values,
				kind,
				type,
			} );
			toast.success( response.message );
			onClose();
		} catch ( err ) {
			const mapped = {};
			Object.entries( err.errors || {} ).forEach(
				( [ key, value ] ) => ( mapped[ key ] = message( value ) )
			);
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	const per = (
		<Field
			label={ __( 'Taken', 'radius-hotel-booking-pro' ) }
			error={ errors.calculation }
		>
			<SegmentedControl
				label={ __( 'Taken', 'radius-hotel-booking-pro' ) }
				value={ values.calculation }
				onChange={ ( v ) => set( 'calculation', v ) }
				options={ [
					{
						value: 'per_payslip',
						label: __(
							'Every payslip',
							'radius-hotel-booking-pro'
						),
					},
					{
						value: 'per_month',
						label: __( 'Once a month', 'radius-hotel-booking-pro' ),
					},
				] }
			/>
		</Field>
	);

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-h-[90vh] max-w-lg overflow-y-auto">
				<form className="space-y-4" onSubmit={ submit } noValidate>
					<DialogHeader>
						<DialogTitle>
							{ row
								? __(
										'Change the deduction',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Add a deduction or garnishment',
										'radius-hotel-booking-pro'
								  ) }
						</DialogTitle>
					</DialogHeader>
					<Field
						label={ __( 'Kind', 'radius-hotel-booking-pro' ) }
						error={ errors.type }
					>
						<Select
							value={ choice }
							onValueChange={ setChoice }
							disabled={ Boolean( row ) }
						>
							<SelectTrigger>
								<SelectValue />
							</SelectTrigger>
							<SelectContent className="rtbp-root">
								{ choices().map( ( c ) => (
									<SelectItem
										key={ c.value }
										value={ c.value }
									>
										{ c.label }
									</SelectItem>
								) ) }
							</SelectContent>
						</Select>
					</Field>
					{ text(
						'name',
						__(
							'Description on the payslip',
							'radius-hotel-booking-pro'
						),
						{ required: true, input: { maxLength: 191 } }
					) }
					{ 'deduction' === kind ? (
						<>
							<div className="grid gap-4 sm:grid-cols-2">
								{ text(
									'authority',
									__( 'Paid to', 'radius-hotel-booking-pro' ),
									{
										required: true,
										input: { maxLength: 191 },
									}
								) }
								{ text(
									'remittance_day',
									__(
										'Paid on day of the month',
										'radius-hotel-booking-pro'
									),
									{
										required: true,
										input: {
											type: 'number',
											min: 1,
											max: 31,
											step: 1,
										},
									}
								) }
							</div>
							{ per }
							{ share(
								'',
								__(
									'Employee pays (before tax)',
									'radius-hotel-booking-pro'
								),
								false
							) }
							{ share(
								'employer_',
								__(
									'The hotel adds',
									'radius-hotel-booking-pro'
								),
								true
							) }
						</>
					) : (
						<>
							<div className="grid gap-4 sm:grid-cols-2">
								{ dateField(
									'start_date',
									__( 'Start', 'radius-hotel-booking-pro' ),
									{ required: true }
								) }
								{ dateField(
									'end_date',
									__( 'End', 'radius-hotel-booking-pro' )
								) }
								{ text(
									'authority',
									__( 'Paid to', 'radius-hotel-booking-pro' ),
									{ input: { maxLength: 191 } }
								) }
								{ text(
									'remittance_day',
									__(
										'Paid on day of the month',
										'radius-hotel-booking-pro'
									),
									{
										input: {
											type: 'number',
											min: 1,
											max: 31,
											step: 1,
										},
									}
								) }
							</div>
							{ 'court_order' === type ? (
								<div className="grid gap-4 sm:grid-cols-2">
									{ text(
										'total_amount',
										__(
											'Requested a pay period',
											'radius-hotel-booking-pro'
										),
										{
											required: true,
											input: {
												type: 'number',
												min: 0,
												step: 'any',
												inputMode: 'decimal',
											},
										}
									) }
									{ text(
										'max_percent',
										__(
											'At most, % of take-home pay',
											'radius-hotel-booking-pro'
										),
										{
											required: true,
											input: {
												type: 'number',
												min: 0,
												max: 100,
												step: 'any',
												inputMode: 'decimal',
											},
										}
									) }
								</div>
							) : (
								<>
									{ text(
										'total_amount',
										__(
											'Total to recover',
											'radius-hotel-booking-pro'
										),
										{
											required: true,
											input: {
												type: 'number',
												min: 0,
												step: 'any',
												inputMode: 'decimal',
											},
										}
									) }
									{ per }
									{ share(
										'',
										__(
											'Taken (after tax)',
											'radius-hotel-booking-pro'
										),
										false
									) }
								</>
							) }
						</>
					) }
					{ row ? (
						<Field
							label={ __( 'Status', 'radius-hotel-booking-pro' ) }
							error={ errors.status }
						>
							<SegmentedControl
								label={ __(
									'Status',
									'radius-hotel-booking-pro'
								) }
								value={ values.status }
								onChange={ ( v ) => set( 'status', v ) }
								options={ Object.entries( STATUS_LABELS() ).map(
									( [ value, label ] ) => ( { value, label } )
								) }
							/>
						</Field>
					) : null }
					<DialogFooter>
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button type="submit" disabled={ mutation.isPending }>
							{ row
								? __( 'Save', 'radius-hotel-booking-pro' )
								: __( 'Add', 'radius-hotel-booking-pro' ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

/**
 * @param {Object}  props            Props.
 * @param {number}  props.employeeId Pro employee id.
 * @param {boolean} props.canEdit    May change them.
 * @return {JSX.Element} Panel.
 */
export default function Deductions( { employeeId, canEdit } ) {
	const list = useDeductions( employeeId );
	const mutation = useDeductionMutation( employeeId );
	const [ editing, setEditing ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );
	const labels = Object.fromEntries(
		choices().map( ( c ) => [ c.value, c.label ] )
	);
	const statuses = STATUS_LABELS();

	return (
		<Panel>
			<div className="space-y-4">
				<div className="flex flex-wrap items-start justify-between gap-3">
					<div className="min-w-0">
						<h3 className="m-0 text-base font-semibold text-heading">
							{ __(
								'Deductions and garnishments',
								'radius-hotel-booking-pro'
							) }
						</h3>
						<p className="m-0 text-sm text-muted-foreground">
							{ __(
								'Deductions are taken before tax; garnishments after tax.',
								'radius-hotel-booking-pro'
							) }
						</p>
					</div>
					{ canEdit ? (
						<Button
							variant="outline"
							onClick={ () => setEditing( { row: null } ) }
						>
							<Plus className="h-4 w-4" aria-hidden="true" />
							{ __( 'Add', 'radius-hotel-booking-pro' ) }
						</Button>
					) : null }
				</div>
				{ list.isPending ? (
					<Skeleton className="h-16 w-full rounded-lg" />
				) : null }
				{ list.error ? (
					<p className="m-0 text-sm text-destructive">
						{ list.error.message }
					</p>
				) : null }
				{ list.data && ! list.data.length ? (
					<p className="m-0 text-sm text-muted-foreground">
						{ __( 'None.', 'radius-hotel-booking-pro' ) }
					</p>
				) : null }
				<div className="space-y-2">
					{ ( list.data || [] ).map( ( row ) => (
						<div
							key={ row.id }
							className="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2"
						>
							<div className="min-w-0">
								<div className="flex flex-wrap items-center gap-2">
									<span className="text-sm font-medium">
										{ row.name }
									</span>
									<Badge variant="outline">
										{
											labels[
												`${ row.kind }:${ row.type }`
											]
										}
									</Badge>
									{ 'active' !== row.status ? (
										<Badge variant="secondary">
											{ statuses[ row.status ] ||
												row.status }
										</Badge>
									) : null }
								</div>
								<p className="m-0 text-xs text-muted-foreground">
									{ takes( row ) }
								</p>
							</div>
							{ canEdit ? (
								<div className="flex gap-1">
									<Button
										variant="ghost"
										size="sm"
										onClick={ () => setEditing( { row } ) }
										aria-label={ sprintf(
											/* translators: %s: deduction name. */
											__(
												'Change %s',
												'radius-hotel-booking-pro'
											),
											row.name
										) }
									>
										<FilePenLine
											className="h-4 w-4"
											aria-hidden="true"
										/>
									</Button>
									<Button
										variant="ghost"
										size="sm"
										className="text-destructive"
										onClick={ () => setDeleting( row ) }
										aria-label={ sprintf(
											/* translators: %s: deduction name. */
											__(
												'Delete %s',
												'radius-hotel-booking-pro'
											),
											row.name
										) }
									>
										<Trash2
											className="h-4 w-4"
											aria-hidden="true"
										/>
									</Button>
								</div>
							) : null }
						</div>
					) ) }
				</div>
			</div>
			<DeductionDialog
				open={ Boolean( editing ) }
				row={ editing?.row || null }
				employeeId={ employeeId }
				onClose={ () => setEditing( null ) }
			/>
			<ConfirmDialog
				open={ Boolean( deleting ) }
				onOpenChange={ ( open ) => ! open && setDeleting( null ) }
				title={
					deleting
						? sprintf(
								/* translators: %s: deduction name. */
								__( 'Delete %s?', 'radius-hotel-booking-pro' ),
								deleting.name
						  )
						: ''
				}
				description={ __(
					'One that pay runs have already taken cannot be deleted: mark it completed instead.',
					'radius-hotel-booking-pro'
				) }
				confirmLabel={ __( 'Delete', 'radius-hotel-booking-pro' ) }
				destructive
				onConfirm={ () =>
					mutation
						.mutateAsync( { id: deleting.id, remove: true } )
						.then( ( response ) =>
							toast.success( response.message )
						)
						.catch( ( err ) => toastError( err ) )
				}
			/>
		</Panel>
	);
}

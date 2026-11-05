/**
 * One employee's line of a pay run (M15 T5c, 15.8): the whole calculation,
 * and — on a draft, with `payroll.edit_line` — what can be entered for the
 * period: hours for an hourly employee, the hours or amounts of their
 * additional pay, one-off additions and deductions. Saving recalculates.
 */
import { useEffect, useId, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Check, Pencil, Plus, Trash2 } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	Field,
	Input,
	SegmentedControl,
	Switch,
} from '@rtbp/ui';
import { format, toast, toastError } from '@rtbp/lib';

import { usePayrollRunAction } from './api';
import { openPayslip } from './payslip';

const money = ( v ) => format.formatMoney( v );

/**
 * A breakdown row.
 *
 * @param {Object}  props        Props.
 * @param {string}  props.label  Label.
 * @param {string}  props.value  Amount (already formatted).
 * @param {boolean} props.strong Bold total.
 * @param {string}  props.hint   Small text under the label.
 * @return {JSX.Element} Row.
 */
function Row( { label, value, strong, hint } ) {
	return (
		<div
			className={
				strong
					? 'flex justify-between gap-3 border-t pt-2 font-semibold text-heading'
					: 'flex justify-between gap-3'
			}
		>
			<span className="min-w-0">
				{ label }
				{ hint ? ' ' : null }
				{ hint ? (
					<span className="text-xs font-normal text-muted-foreground">
						{ hint }
					</span>
				) : null }
			</span>
			<span className="shrink-0 tabular-nums">{ value }</span>
		</div>
	);
}

/**
 * The calculation, read-only.
 *
 * @param {Object} props           Props.
 * @param {Object} props.breakdown `LineCalculator` result.
 * @return {JSX.Element} Breakdown.
 */
export function Breakdown( { breakdown: b } ) {
	if ( ! b || ! b.statutory ) {
		return null;
	}
	const employee = Object.values( b.statutory.lines ).filter(
		( l ) => 'employee' === l.side
	);
	const employer = Object.values( b.statutory.lines ).filter(
		( l ) => 'employer' === l.side
	);
	const rate = ( l ) =>
		sprintf(
			/* translators: %s: percentage. */
			__( '%s %%', 'radius-hotel-booking-pro' ),
			String( Math.round( Number( l.rate ) * 1000 ) / 1000 )
		);
	return (
		<div className="space-y-4 text-sm">
			<div className="space-y-1">
				<Row
					label={ __( 'Base pay', 'radius-hotel-booking-pro' ) }
					hint={
						Number( b.hours ) > 0
							? sprintf(
									/* translators: %s: hours. */
									__( '%s h', 'radius-hotel-booking-pro' ),
									String( Number( b.hours ) )
							  )
							: ''
					}
					value={ money( b.base ) }
				/>
				{ b.earnings.map( ( e ) => (
					<Row
						key={ e.code }
						label={ e.label }
						hint={
							e.quantity
								? sprintf(
										/* translators: %s: hours. */
										__(
											'%s h',
											'radius-hotel-booking-pro'
										),
										String( Number( e.quantity ) )
								  ) +
								  ( e.over
										? ' · ' +
										  sprintf(
												/* translators: %s: hours. */
												__(
													'%s h over the yearly quota, taken off',
													'radius-hotel-booking-pro'
												),
												String( Number( e.over ) )
										  )
										: '' )
								: ''
						}
						value={
							( 'deduct' === e.effect ? '− ' : '' ) +
							money( e.amount )
						}
					/>
				) ) }
				{ b.vacation ? (
					<Row
						label={ __(
							'Vacation pay',
							'radius-hotel-booking-pro'
						) }
						hint={
							'retain' === b.vacation.method
								? __(
										'Kept for later, not paid now',
										'radius-hotel-booking-pro'
								  )
								: sprintf(
										/* translators: %s: percentage. */
										__(
											'%s %% of the eligible pay',
											'radius-hotel-booking-pro'
										),
										String( Number( b.vacation.percent ) )
								  )
						}
						value={
							'retain' === b.vacation.method
								? `(${ money( b.vacation.amount ) })`
								: money( b.vacation.amount )
						}
					/>
				) : null }
				<Row
					strong
					label={ __( 'Gross pay', 'radius-hotel-booking-pro' ) }
					value={ money( b.gross ) }
				/>
			</div>
			<div className="space-y-1">
				{ b.deductions.map( ( d ) => (
					<Row
						key={ d.key }
						label={ d.label }
						hint={ __( 'Before tax', 'radius-hotel-booking-pro' ) }
						value={ `− ${ money( d.amount ) }` }
					/>
				) ) }
				{ employee.map( ( l ) => (
					<Row
						key={ l.code + l.side }
						label={ l.label }
						hint={ rate( l ) }
						value={ `− ${ money( l.amount ) }` }
					/>
				) ) }
				{ b.garnishments.map( ( g ) => (
					<Row
						key={ g.key }
						label={ g.label }
						hint={ __( 'After tax', 'radius-hotel-booking-pro' ) }
						value={ `− ${ money( g.amount ) }` }
					/>
				) ) }
				{ b.custom.map( ( c, i ) => (
					<Row
						key={ i }
						label={ c.name }
						hint={
							Number( c.tax ) > 0
								? sprintf(
										/* translators: %s: amount. */ __(
											'Taxed %s',
											'radius-hotel-booking-pro'
										),
										money( c.tax )
								  )
								: ''
						}
						value={
							( 'deduction' === c.type ? '− ' : '+ ' ) +
							money( Math.abs( Number( c.net ) ) )
						}
					/>
				) ) }
				<Row
					strong
					label={ __( 'Net pay', 'radius-hotel-booking-pro' ) }
					value={ money( b.net ) }
				/>
			</div>
			<div className="space-y-1 text-muted-foreground">
				{ employer.map( ( l ) => (
					<Row
						key={ l.code + l.side }
						label={ l.label }
						hint={ rate( l ) }
						value={ money( l.amount ) }
					/>
				) ) }
				<Row
					strong
					label={ __(
						'Cost to the hotel',
						'radius-hotel-booking-pro'
					) }
					value={ money( b.employer_cost ) }
				/>
			</div>
		</div>
	);
}

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
 * What was saved for the line: `{ entries, custom, hours }` in the form's shape.
 *
 * @param {Object} line The line.
 * @return {Object} Inputs.
 */
const savedInputs = ( line ) => {
	// An empty PHP array arrives as `[]`; `[].entries` is a function — never hand it to setState.
	const inputs =
		line.inputs && ! Array.isArray( line.inputs ) ? line.inputs : {};
	return {
		entries:
			inputs.entries && ! Array.isArray( inputs.entries )
				? { ...inputs.entries }
				: {},
		custom: Array.isArray( inputs.custom ) ? inputs.custom : [],
		hours: inputs.hours || '',
	};
};

/**
 * A card around a group of inputs: title, one line of help, an optional
 * action on the right.
 *
 * @param {Object}      props             Props.
 * @param {string}      props.title       Title.
 * @param {string}      props.description Help under the title.
 * @param {JSX.Element} props.action      Button on the right.
 * @param {JSX.Element} props.children    Body.
 * @return {JSX.Element} Card.
 */
function Card( { title, description, action, children } ) {
	return (
		<section className="overflow-hidden rounded-lg border">
			<header className="flex items-start justify-between gap-3 border-b bg-muted px-4 py-3">
				<div className="min-w-0">
					<h3 className="text-sm font-semibold text-heading">
						{ title }
					</h3>
					{ description ? (
						<p className="text-xs text-muted-foreground">
							{ description }
						</p>
					) : null }
				</div>
				{ action ? <div className="shrink-0">{ action }</div> : null }
			</header>
			{ children }
		</section>
	);
}

/**
 * A number box with its unit inside, on the right ("5 h", "75000 CFA").
 *
 * @param {Object}   props          Props.
 * @param {string}   props.id       Id.
 * @param {string}   props.unit     Unit.
 * @param {string}   props.value    Value.
 * @param {Function} props.onChange Called with the new value.
 * @param {string}   props.error    Server message (red border).
 * @return {JSX.Element} Input.
 */
function UnitInput( { id, unit, value, onChange, error, ...rest } ) {
	return (
		<div className="relative w-40">
			<Input
				id={ id }
				type="number"
				min="0"
				step="any"
				inputMode="decimal"
				className={
					'text-right tabular-nums' +
					( unit ? ' pr-12' : '' ) +
					( error
						? ' !border-destructive focus-visible:!ring-destructive'
						: '' )
				}
				aria-invalid={ error ? true : undefined }
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
				{ ...rest }
			/>
			{ unit ? (
				<span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-muted-foreground">
					{ unit }
				</span>
			) : null }
		</div>
	);
}

/**
 * One input of the period: label and help on the left, the box on the right.
 *
 * @param {Object}   props             Props.
 * @param {string}   props.label       Label.
 * @param {string}   props.help        Help under the label.
 * @param {string}   props.unit        Unit inside the box.
 * @param {string}   props.error       Server message.
 * @param {string}   props.value       Value.
 * @param {Function} props.onChange    Called with the new value.
 * @param {string}   props.placeholder Placeholder.
 * @return {JSX.Element} Row.
 */
function EntryRow( {
	label,
	help,
	unit,
	error,
	value,
	onChange,
	placeholder,
} ) {
	const id = useId();
	return (
		<div className="px-4 py-3">
			<div className="flex flex-wrap items-center justify-between gap-3">
				<div className="min-w-0 flex-1">
					<label
						htmlFor={ id }
						className="text-sm font-medium text-heading"
					>
						{ label }
					</label>
					{ help ? (
						<p className="text-xs text-muted-foreground">
							{ help }
						</p>
					) : null }
				</div>
				<UnitInput
					id={ id }
					unit={ unit }
					value={ value }
					onChange={ onChange }
					error={ error }
					placeholder={ placeholder }
				/>
			</div>
			{ error ? (
				<p className="text-right text-xs text-destructive">{ error }</p>
			) : null }
		</div>
	);
}

/**
 * One one-off addition or deduction.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.item     `{ type, name, amount, taxable, tax_rate }`.
 * @param {number}   props.index    Position (for the server's field errors).
 * @param {Object}   props.errors   Field errors.
 * @param {string}   props.currency Currency symbol.
 * @param {number}   props.defaultTaxRate The salary tax rate, % (pre-fills the item's own rate).
 * @param {Function} props.onChange `( key, value )`.
 * @param {Function} props.onRemove Remove it.
 * @return {JSX.Element} Item.
 */
function CustomItem( {
	item,
	index,
	errors,
	currency,
	defaultTaxRate,
	onChange,
	onRemove,
} ) {
	const amountId = useId();
	const amountError = errors[ `custom.${ index }.amount` ];
	// A new item opens for editing; a filled one shows as one line until
	// Edit is clicked. An item the server flagged stays open.
	const [ open, setOpen ] = useState(
		() =>
			'' === String( item.name ?? '' ) ||
			'' === String( item.amount ?? '' )
	);
	const flagged = Boolean(
		amountError || errors[ `custom.${ index }.name` ]
	);
	if ( ! open && ! flagged ) {
		const addition = 'addition' === item.type;
		return (
			<div className="flex items-center gap-3 px-4 py-3">
				<span
					className={
						'flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold ' +
						( addition ? 'text-success' : 'text-destructive' )
					}
					aria-hidden="true"
				>
					{ addition ? '+' : '−' }
				</span>
				<div className="min-w-0 flex-1">
					<p className="m-0 truncate text-sm font-medium text-heading">
						{ item.name }
					</p>
					<p className="m-0 text-xs text-muted-foreground">
						{ addition
							? __( 'Addition', 'radius-hotel-booking-pro' )
							: __( 'Deduction', 'radius-hotel-booking-pro' ) }
						{ addition && item.taxable
							? ' · ' +
							  sprintf(
									/* translators: %s: tax rate. */
									__(
										'taxed at %s %%',
										'radius-hotel-booking-pro'
									),
									String( Number( item.tax_rate ) || 0 )
							  )
							: '' }
					</p>
				</div>
				<span className="shrink-0 text-sm font-medium tabular-nums text-heading">
					{ ( addition ? '+ ' : '− ' ) +
						format.formatMoney( Number( item.amount ) || 0 ) }
				</span>
				<Button
					type="button"
					variant="ghost"
					size="sm"
					onClick={ () => setOpen( true ) }
					aria-label={ sprintf(
						/* translators: %s: item name. */
						__( 'Edit %s', 'radius-hotel-booking-pro' ),
						item.name
					) }
				>
					<Pencil className="h-4 w-4" aria-hidden="true" />
				</Button>
				<Button
					type="button"
					variant="ghost"
					size="sm"
					className="text-destructive"
					onClick={ onRemove }
					aria-label={ sprintf(
						/* translators: %s: item name. */
						__( 'Remove %s', 'radius-hotel-booking-pro' ),
						item.name
					) }
				>
					<Trash2 className="h-4 w-4" aria-hidden="true" />
				</Button>
			</div>
		);
	}
	return (
		<div className="space-y-3 px-4 py-3">
			<div className="flex items-center justify-between gap-2">
				<SegmentedControl
					label={ __( 'Kind', 'radius-hotel-booking-pro' ) }
					value={ item.type }
					onChange={ ( v ) => onChange( 'type', v ) }
					options={ [
						{
							value: 'addition',
							label: __(
								'+ Addition',
								'radius-hotel-booking-pro'
							),
						},
						{
							value: 'deduction',
							label: __(
								'− Deduction',
								'radius-hotel-booking-pro'
							),
						},
					] }
				/>
				<div className="flex items-center gap-1">
					<Button
						type="button"
						variant="outline"
						size="sm"
						disabled={
							'' === String( item.name ?? '' ).trim() ||
							'' === String( item.amount ?? '' )
						}
						onClick={ () => setOpen( false ) }
					>
						<Check className="h-4 w-4" aria-hidden="true" />
						{ __( 'Done', 'radius-hotel-booking-pro' ) }
					</Button>
					<Button
						type="button"
						variant="ghost"
						size="sm"
						className="text-destructive"
						onClick={ onRemove }
						aria-label={ __(
							'Remove',
							'radius-hotel-booking-pro'
						) }
					>
						<Trash2 className="h-4 w-4" aria-hidden="true" />
					</Button>
				</div>
			</div>
			<div className="flex flex-wrap items-start gap-3">
				<Field
					className="flex-1"
					label={ __( 'Name', 'radius-hotel-booking-pro' ) }
					error={ errors[ `custom.${ index }.name` ] }
				>
					<Input
						value={ item.name }
						maxLength={ 100 }
						placeholder={ __(
							'e.g. Salary advance',
							'radius-hotel-booking-pro'
						) }
						onChange={ ( e ) => onChange( 'name', e.target.value ) }
					/>
				</Field>
				<div className="space-y-1.5">
					<label
						htmlFor={ amountId }
						className="block text-sm font-medium text-heading"
					>
						{ __( 'Amount', 'radius-hotel-booking-pro' ) }
					</label>
					<UnitInput
						id={ amountId }
						unit={ currency }
						value={ item.amount }
						onChange={ ( v ) => onChange( 'amount', v ) }
						error={ amountError }
					/>
					{ amountError ? (
						<p className="text-xs text-destructive">
							{ amountError }
						</p>
					) : null }
				</div>
			</div>
			{ 'addition' === item.type ? (
				<div className="flex flex-wrap items-center justify-between gap-3">
					<label className="flex items-center gap-2 text-sm">
						<Switch
							checked={ Boolean( item.taxable ) }
							onCheckedChange={ ( v ) => {
								onChange( 'taxable', v );
								// Switched on with no rate yet: start from the salary tax rate.
								if (
									v &&
									! ( Number( item.tax_rate ) > 0 ) &&
									defaultTaxRate > 0
								) {
									onChange(
										'tax_rate',
										String( defaultTaxRate )
									);
								}
							} }
						/>
						{ __(
							'Taxed at its own rate',
							'radius-hotel-booking-pro'
						) }
					</label>
					{ item.taxable ? (
						<div className="relative w-24">
							<Input
								type="number"
								min="0"
								max="100"
								step="any"
								className="pr-9 text-right tabular-nums"
								aria-label={ __(
									'Tax rate',
									'radius-hotel-booking-pro'
								) }
								value={ item.tax_rate }
								onChange={ ( e ) =>
									onChange( 'tax_rate', e.target.value )
								}
							/>
							<span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-muted-foreground">
								%
							</span>
						</div>
					) : null }
				</div>
			) : null }
		</div>
	);
}

/**
 * @param {Object}   props         Props.
 * @param {Object}   props.run     The run (with `item_types`).
 * @param {Object}   props.line    The line, or null.
 * @param {boolean}  props.canEdit Draft and `payroll.edit_line`.
 * @param {Function} props.onClose Close.
 * @return {JSX.Element} Dialog.
 */
export default function LineDialog( { run, line, canEdit, onClose } ) {
	const action = usePayrollRunAction();
	const [ entries, setEntries ] = useState( {} );
	const [ custom, setCustom ] = useState( [] );
	const [ hours, setHours ] = useState( '' );
	const [ errors, setErrors ] = useState( {} );
	// The one-off item waiting for the remove confirmation (its index), or null.
	const [ removing, setRemoving ] = useState( null );

	useEffect( () => {
		if ( line ) {
			const saved = savedInputs( line );
			setEntries( saved.entries );
			setCustom( saved.custom );
			setHours( saved.hours );
			setErrors( {} );
		}
	}, [ line ] );

	if ( ! line ) {
		return null;
	}
	const currency = format.currencyInfo?.().symbol || '';
	// The salary tax rate of this line (the first employee tax with a rate, e.g.
	// Côte d'Ivoire's IS 1.5 %): the default for a one-off item taxed on its own.
	const salaryTax = Object.values(
		line.breakdown?.statutory?.lines || {}
	).find(
		( l ) =>
			'employee' === l.side && 'tax' === l.kind && Number( l.rate ) > 0
	);
	const defaultTaxRate = salaryTax
		? Math.round( Number( salaryTax.rate ) * 1000 ) / 1000
		: 0;
	// Edited since the last save: the figures below are not recalculated yet.
	const dirty =
		JSON.stringify( { entries, custom, hours } ) !==
		JSON.stringify( savedInputs( line ) );
	const types = ( run.item_types || [] ).filter(
		( t ) => line.items.includes( t.key ) && 'none' !== t.entry
	);

	const save = async ( event ) => {
		event.preventDefault();
		try {
			const response = await action.mutateAsync( {
				action: 'line',
				id: run.id,
				line: line.id,
				entries,
				custom,
				hours,
			} );
			// Stay open: the line refreshes with the new figures.
			toast.success( response.message );
		} catch ( err ) {
			const mapped = {};
			Object.entries( err.errors || {} ).forEach(
				( [ k, v ] ) => ( mapped[ k ] = message( v ) )
			);
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	return (
		<Dialog open onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root flex max-h-[90vh] max-w-lg flex-col gap-0 p-0">
				<form
					className="flex min-h-0 flex-1 flex-col"
					onSubmit={ save }
					noValidate
				>
					{ /* Header and footer stay; only the body scrolls. */ }
					<DialogHeader className="shrink-0 border-b px-6 py-4 pr-12">
						<DialogTitle>{ line.employee_name }</DialogTitle>
						<DialogDescription>
							{ [ line.employee_no, line.payment_method ]
								.filter( Boolean )
								.join( ' · ' ) }
						</DialogDescription>
					</DialogHeader>
					<div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-6 pb-4">
						{ canEdit ? (
							<>
								<Card
									title={ __(
										'This pay period',
										'radius-hotel-booking-pro'
									) }
									description={ __(
										'Applies to this pay run only. Saving recalculates the line.',
										'radius-hotel-booking-pro'
									) }
								>
									<div className="divide-y divide-border">
										<EntryRow
											label={ __(
												'Hours worked',
												'radius-hotel-booking-pro'
											) }
											help={
												Number(
													line.breakdown?.hours
												) > 0
													? sprintf(
															/* translators: %s: hours, e.g. "173.33". */
															__(
																'For hourly pay. Leave empty to use the work shift’s %s h.',
																'radius-hotel-booking-pro'
															),
															String(
																Number(
																	line
																		.breakdown
																		.hours
																)
															)
													  )
													: __(
															'For hourly pay. Leave empty to use the work shift’s hours.',
															'radius-hotel-booking-pro'
													  )
											}
											unit={ __(
												'h',
												'radius-hotel-booking-pro'
											) }
											error={ errors.hours }
											value={ hours }
											placeholder={ String(
												Number(
													line.breakdown?.hours || 0
												)
											) }
											onChange={ setHours }
										/>
										{ types.map( ( t ) => (
											<EntryRow
												key={ t.key }
												label={ t.label }
												help={
													'hours' === t.entry
														? __(
																'Hours in this period.',
																'radius-hotel-booking-pro'
														  )
														: __(
																'Amount for this period.',
																'radius-hotel-booking-pro'
														  )
												}
												unit={
													'hours' === t.entry
														? __(
																'h',
																'radius-hotel-booking-pro'
														  )
														: currency
												}
												error={
													errors[
														`entries.${ t.key }`
													]
												}
												value={ entries[ t.key ] ?? '' }
												onChange={ ( v ) =>
													setEntries( ( c ) => ( {
														...c,
														[ t.key ]: v,
													} ) )
												}
											/>
										) ) }
									</div>
								</Card>
								<Card
									title={ __(
										'One-off additions and deductions',
										'radius-hotel-booking-pro'
									) }
									description={ __(
										'For this period only, such as an advance or an allowance.',
										'radius-hotel-booking-pro'
									) }
									action={
										<Button
											type="button"
											variant="outline"
											size="sm"
											onClick={ () =>
												setCustom( ( c ) => [
													...c,
													{
														type: 'addition',
														name: '',
														amount: '',
														taxable: false,
														tax_rate: 0,
													},
												] )
											}
										>
											<Plus
												className="h-4 w-4"
												aria-hidden="true"
											/>
											{ __(
												'Add item',
												'radius-hotel-booking-pro'
											) }
										</Button>
									}
								>
									{ custom.length ? (
										<div className="divide-y divide-border">
											{ custom.map( ( item, i ) => (
												<CustomItem
													key={ i }
													item={ item }
													index={ i }
													errors={ errors }
													currency={ currency }
													defaultTaxRate={
														defaultTaxRate
													}
													onChange={ ( key, value ) =>
														setCustom( ( c ) =>
															c.map(
																( row, j ) =>
																	j === i
																		? {
																				...row,
																				[ key ]:
																					value,
																		  }
																		: row
															)
														)
													}
													onRemove={ () =>
														// An empty item goes at once; one with content is confirmed.
														'' ===
															String(
																item.name ?? ''
															).trim() &&
														'' ===
															String(
																item.amount ??
																	''
															)
															? setCustom(
																	( c ) =>
																		c.filter(
																			(
																				row,
																				j
																			) =>
																				j !==
																				i
																		)
															  )
															: setRemoving( i )
													}
												/>
											) ) }
										</div>
									) : (
										<p className="px-4 py-4 text-center text-sm text-muted-foreground">
											{ __(
												'Nothing added for this period.',
												'radius-hotel-booking-pro'
											) }
										</p>
									) }
								</Card>
							</>
						) : null }
						{ dirty ? (
							<p className="m-0 rounded-md border border-dashed px-3 py-2 text-xs text-muted-foreground">
								{ __(
									'You have changes: the figures below are from the last save. Save and recalculate to update them.',
									'radius-hotel-booking-pro'
								) }
							</p>
						) : null }
						<Breakdown breakdown={ line.breakdown } />
					</div>
					<DialogFooter className="shrink-0 border-t px-6 py-4">
						{ ( run.payslip_formats || [] ).map( ( format ) => (
							<Button
								key={ format }
								type="button"
								variant="outline"
								onClick={ () =>
									openPayslip(
										run.id,
										line.id,
										format
									).catch( ( err ) => toastError( err ) )
								}
							>
								{ 'pdf' === format
									? __(
											'Payslip (PDF)',
											'radius-hotel-booking-pro'
									  )
									: __(
											'Payslip',
											'radius-hotel-booking-pro'
									  ) }
							</Button>
						) ) }
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ canEdit && dirty
								? __( 'Cancel', 'radius-hotel-booking-pro' )
								: __( 'Close', 'radius-hotel-booking-pro' ) }
						</Button>
						{ canEdit ? (
							<Button
								type="submit"
								disabled={ action.isPending || ! dirty }
							>
								{ __(
									'Save and recalculate',
									'radius-hotel-booking-pro'
								) }
							</Button>
						) : null }
					</DialogFooter>
				</form>
				<ConfirmDialog
					open={ null !== removing }
					onOpenChange={ ( next ) => ! next && setRemoving( null ) }
					title={
						null !== removing
							? sprintf(
									/* translators: %s: item name, e.g. "Salary advance". */
									__(
										'Remove %s?',
										'radius-hotel-booking-pro'
									),
									custom[ removing ]?.name ||
										__(
											'this item',
											'radius-hotel-booking-pro'
										)
							  )
							: ''
					}
					description={ __(
						'It comes off this line when you save and recalculate.',
						'radius-hotel-booking-pro'
					) }
					confirmLabel={ __( 'Remove', 'radius-hotel-booking-pro' ) }
					destructive
					onConfirm={ () => {
						const index = removing;
						setCustom( ( c ) =>
							c.filter( ( row, j ) => j !== index )
						);
						setRemoving( null );
					} }
				/>
			</DialogContent>
		</Dialog>
	);
}

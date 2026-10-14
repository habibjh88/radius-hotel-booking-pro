/**
 * One employee's line of a pay run (M15 T5c, 15.8): the whole calculation,
 * and — on a draft, with `payroll.edit_line` — what can be entered for the
 * period: hours for an hourly employee, the hours or amounts of their
 * additional pay, one-off additions and deductions. Saving recalculates.
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Plus, Trash2 } from 'lucide-react';
import {
	Button,
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
				{ hint ? (
					<span className="block text-xs text-muted-foreground">
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

	useEffect( () => {
		if ( line ) {
			// An empty PHP array arrives as `[]`; `[].entries` is a function — never hand it to setState.
			const inputs =
				line.inputs && ! Array.isArray( line.inputs )
					? line.inputs
					: {};
			setEntries(
				inputs.entries && ! Array.isArray( inputs.entries )
					? { ...inputs.entries }
					: {}
			);
			setCustom( Array.isArray( inputs.custom ) ? inputs.custom : [] );
			setHours( inputs.hours || '' );
			setErrors( {} );
		}
	}, [ line ] );

	if ( ! line ) {
		return null;
	}
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
			toast.success( response.message );
			onClose();
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
			<DialogContent className="rtbp-root max-h-[90vh] max-w-lg overflow-y-auto">
				<form className="space-y-5" onSubmit={ save } noValidate>
					<DialogHeader>
						<DialogTitle>{ line.employee_name }</DialogTitle>
						<DialogDescription>
							{ [ line.employee_no, line.payment_method ]
								.filter( Boolean )
								.join( ' · ' ) }
						</DialogDescription>
					</DialogHeader>
					{ canEdit ? (
						<div className="space-y-3 rounded-lg border p-3">
							<Field
								label={ __(
									'Hours worked',
									'radius-hotel-booking-pro'
								) }
								description={ __(
									'Empty: the work shift’s hours in the period. Used for hourly pay.',
									'radius-hotel-booking-pro'
								) }
								error={ errors.hours }
							>
								<Input
									type="number"
									min="0"
									step="any"
									inputMode="decimal"
									className="w-32"
									value={ hours }
									placeholder={ String(
										Number( line.breakdown?.hours || 0 )
									) }
									onChange={ ( e ) =>
										setHours( e.target.value )
									}
								/>
							</Field>
							{ types.map( ( t ) => (
								<Field
									key={ t.key }
									label={ t.label }
									error={ errors[ `entries.${ t.key }` ] }
								>
									<div className="flex items-center gap-2">
										<Input
											type="number"
											min="0"
											step="any"
											inputMode="decimal"
											className="w-32"
											value={ entries[ t.key ] ?? '' }
											onChange={ ( e ) =>
												setEntries( ( c ) => ( {
													...c,
													[ t.key ]: e.target.value,
												} ) )
											}
										/>
										<span className="text-sm text-muted-foreground">
											{ 'hours' === t.entry
												? __(
														'hours',
														'radius-hotel-booking-pro'
												  )
												: __(
														'amount',
														'radius-hotel-booking-pro'
												  ) }
										</span>
									</div>
								</Field>
							) ) }
							<div className="space-y-2">
								<div className="flex items-center justify-between">
									<span className="text-sm font-medium">
										{ __(
											'One-off additions and deductions',
											'radius-hotel-booking-pro'
										) }
									</span>
									<Button
										type="button"
										variant="ghost"
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
											'Add',
											'radius-hotel-booking-pro'
										) }
									</Button>
								</div>
								{ custom.map( ( item, i ) => {
									const set = ( key, value ) =>
										setCustom( ( c ) =>
											c.map( ( row, j ) =>
												j === i
													? { ...row, [ key ]: value }
													: row
											)
										);
									return (
										<div
											key={ i }
											className="space-y-2 rounded-lg border p-2"
										>
											<div className="flex flex-wrap items-center gap-2">
												<SegmentedControl
													label={ __(
														'Kind',
														'radius-hotel-booking-pro'
													) }
													value={ item.type }
													onChange={ ( v ) =>
														set( 'type', v )
													}
													options={ [
														{
															value: 'addition',
															label: __(
																'Addition',
																'radius-hotel-booking-pro'
															),
														},
														{
															value: 'deduction',
															label: __(
																'Deduction',
																'radius-hotel-booking-pro'
															),
														},
													] }
												/>
												<Button
													type="button"
													variant="ghost"
													size="sm"
													className="ml-auto text-destructive"
													onClick={ () =>
														setCustom( ( c ) =>
															c.filter(
																( row, j ) =>
																	j !== i
															)
														)
													}
													aria-label={ __(
														'Remove',
														'radius-hotel-booking-pro'
													) }
												>
													<Trash2
														className="h-4 w-4"
														aria-hidden="true"
													/>
												</Button>
											</div>
											<div className="grid gap-2 sm:grid-cols-2">
												<Field
													label={ __(
														'Name',
														'radius-hotel-booking-pro'
													) }
													error={
														errors[
															`custom.${ i }.name`
														]
													}
												>
													<Input
														value={ item.name }
														maxLength={ 100 }
														onChange={ ( e ) =>
															set(
																'name',
																e.target.value
															)
														}
													/>
												</Field>
												<Field
													label={ __(
														'Amount',
														'radius-hotel-booking-pro'
													) }
													error={
														errors[
															`custom.${ i }.amount`
														]
													}
												>
													<Input
														type="number"
														min="0"
														step="any"
														inputMode="decimal"
														value={ item.amount }
														onChange={ ( e ) =>
															set(
																'amount',
																e.target.value
															)
														}
													/>
												</Field>
											</div>
											{ 'addition' === item.type ? (
												<div className="flex flex-wrap items-center gap-3 text-sm">
													<label className="flex items-center gap-2">
														<Switch
															checked={ Boolean(
																item.taxable
															) }
															onCheckedChange={ (
																v
															) =>
																set(
																	'taxable',
																	v
																)
															}
														/>
														{ __(
															'Taxed at its own rate',
															'radius-hotel-booking-pro'
														) }
													</label>
													{ item.taxable ? (
														<span className="flex items-center gap-1">
															<Input
																type="number"
																min="0"
																max="100"
																step="any"
																className="w-20"
																value={
																	item.tax_rate
																}
																onChange={ (
																	e
																) =>
																	set(
																		'tax_rate',
																		e.target
																			.value
																	)
																}
															/>
															%
														</span>
													) : null }
												</div>
											) : null }
										</div>
									);
								} ) }
							</div>
						</div>
					) : null }
					<Breakdown breakdown={ line.breakdown } />
					<DialogFooter>
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
							{ canEdit
								? __( 'Cancel', 'radius-hotel-booking-pro' )
								: __( 'Close', 'radius-hotel-booking-pro' ) }
						</Button>
						{ canEdit ? (
							<Button type="submit" disabled={ action.isPending }>
								{ __(
									'Save and recalculate',
									'radius-hotel-booking-pro'
								) }
							</Button>
						) : null }
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

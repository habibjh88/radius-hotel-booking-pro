/**
 * Time off pieces shared by Payroll → Time off (the office, M15 T8b) and My
 * payroll → Time off (the staff member's own, M15 T9): texts, the balance
 * panel and the request form.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
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
	Panel,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Textarea,
} from '@rtbp/ui';
import { format, toast, toastError } from '@rtbp/lib';

import { DateField } from '../pickers';

/**
 * A field error from the API client as text.
 *
 * @param {*} value Error.
 * @return {string} Message.
 */
export const message = ( value ) =>
	value?.first_message ??
	( Array.isArray( value ) ? value[ 0 ] : String( value ) );

/**
 * "5 days".
 *
 * @param {number} days Days.
 * @return {string} Text.
 */
export const daysText = ( days ) =>
	sprintf(
		/* translators: %d: number of days. */
		_n( '%d day', '%d days', days, 'radius-hotel-booking-pro' ),
		days
	);

/**
 * "2 November 2026 – 5 November 2026", or one date (calendar dates, no time).
 *
 * @param {Object} r `{ start_date, end_date }`.
 * @return {string} Text.
 */
export const datesText = ( r ) =>
	r.start_date === r.end_date
		? format.formatDate( r.start_date )
		: sprintf(
				/* translators: 1: first day, 2: last day. */
				__( '%1$s – %2$s', 'radius-hotel-booking-pro' ),
				format.formatDate( r.start_date ),
				format.formatDate( r.end_date )
		  );

/**
 * Calendar days from one date to another, both included (0 when invalid).
 *
 * @param {string} start Y-m-d.
 * @param {string} end   Y-m-d.
 * @return {number} Days.
 */
export const countDays = ( start, end ) => {
	if ( ! start || ! end || end < start ) {
		return 0;
	}
	return (
		Math.round(
			( Date.parse( `${ end }T00:00:00Z` ) -
				Date.parse( `${ start }T00:00:00Z` ) ) /
				86400000
		) + 1
	);
};

/**
 * The vacation part of a balance as a sentence.
 *
 * @param {Object} balance Balance.
 * @return {string} Text.
 */
export const vacationText = ( balance ) =>
	balance.max > 0
		? sprintf(
				/* translators: 1: days used, 2: yearly quota, 3: days left, 4: year. */
				__(
					'Vacation: %1$d of %2$d days taken, %3$d left in %4$d.',
					'radius-hotel-booking-pro'
				),
				balance.used,
				balance.max,
				balance.remaining,
				balance.year
		  )
		: sprintf(
				/* translators: 1: days used, 2: year. */
				__(
					'Vacation: %1$d days taken in %2$d, no yearly limit.',
					'radius-hotel-booking-pro'
				),
				balance.used,
				balance.year
		  );

/**
 * An employee's balance for a year.
 *
 * @param {Object} props          Props.
 * @param {Object} props.balance  Balance.
 * @param {Object} props.types    Type => label.
 * @param {string} props.employee Employee name.
 * @return {JSX.Element} Panel.
 */
export function BalancePanel( { balance, types, employee } ) {
	const taken = Object.entries( balance.by_type || {} ).filter(
		( [ , days ] ) => days > 0
	);
	return (
		<Panel>
			<div className="space-y-2">
				<p className="m-0 font-semibold text-heading">
					{ sprintf(
						/* translators: 1: employee, 2: year. */
						__( '%1$s — %2$d', 'radius-hotel-booking-pro' ),
						employee,
						balance.year
					) }
				</p>
				<p className="m-0 text-sm">{ vacationText( balance ) }</p>
				{ taken.length ? (
					<ul className="m-0 flex list-none flex-wrap gap-2 p-0 text-xs">
						{ taken.map( ( [ type, days ] ) => (
							<li
								key={ type }
								className="rounded-md border px-2 py-1"
							>
								{ sprintf(
									/* translators: 1: type of time off, 2: "5 days". */
									__(
										'%1$s: %2$s',
										'radius-hotel-booking-pro'
									),
									types[ type ] || type,
									daysText( days )
								) }
							</li>
						) ) }
					</ul>
				) : (
					<p className="m-0 text-xs text-muted-foreground">
						{ __(
							'No approved time off this year.',
							'radius-hotel-booking-pro'
						) }
					</p>
				) }
			</div>
		</Panel>
	);
}

/**
 * Add a request for an employee.
 *
 * @param {Object}   props           Props.
 * @param {Array}    props.employees  Active employees to pick from; null = the signed-in staff member's own request.
 * @param {Object}   props.types     Type => label.
 * @param {string}   props.today     Site today.
 * @param {number}   props.preset    Employee to start with (0 = none).
 * @param {Function} props.onClose   Close.
 * @param {Function} props.useSave    Hook returning the mutation that sends the request.
 * @param {Function} props.useBalance Hook `( employeeId, year )` returning a query whose data is the balance.
 * @return {JSX.Element} Dialog.
 */
export function RequestDialog( {
	employees = null,
	types,
	today,
	preset = 0,
	onClose,
	useSave,
	useBalance,
} ) {
	const save = useSave();
	const [ values, setValues ] = useState( {
		employee_id: preset ? String( preset ) : '',
		type: 'day_off',
		start_date: today,
		end_date: today,
		reason: '',
	} );
	const [ errors, setErrors ] = useState( {} );
	const set = ( key, value ) => {
		setValues( ( c ) => {
			const next = { ...c, [ key ]: value };
			// Moving the first day past the last one moves the last one with it.
			if ( 'start_date' === key && next.end_date < value ) {
				next.end_date = value;
			}
			return next;
		} );
		setErrors( ( c ) => ( { ...c, [ key ]: undefined } ) );
	};
	const days = countDays( values.start_date, values.end_date );
	const year = Number( ( values.start_date || today ).slice( 0, 4 ) );
	const balance = useBalance( Number( values.employee_id ), year );
	const over =
		'day_off' === values.type &&
		balance.data?.max > 0 &&
		days > balance.data.remaining;

	const submit = async ( event ) => {
		event.preventDefault();
		if ( employees && ! values.employee_id ) {
			setErrors( {
				employee_id: __(
					'Choose an employee.',
					'radius-hotel-booking-pro'
				),
			} );
			return;
		}
		try {
			const { employee_id: employeeId, ...rest } = values;
			const response = await save.mutateAsync(
				employees
					? { ...rest, employee_id: Number( employeeId ) }
					: rest
			);
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
			<DialogContent className="rtbp-root max-w-lg">
				<form className="space-y-4" onSubmit={ submit } noValidate>
					<DialogHeader>
						<DialogTitle>
							{ __(
								'Request time off',
								'radius-hotel-booking-pro'
							) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'The request waits for approval; vacation days count once approved.',
								'radius-hotel-booking-pro'
							) }
						</DialogDescription>
					</DialogHeader>
					<div className="grid gap-4 sm:grid-cols-2">
						{ employees ? (
							<Field
								label={ __(
									'Employee',
									'radius-hotel-booking-pro'
								) }
								error={ errors.employee_id }
								required
							>
								<Select
									value={ values.employee_id }
									onValueChange={ ( v ) =>
										set( 'employee_id', v )
									}
								>
									<SelectTrigger>
										<SelectValue
											placeholder={ __(
												'Choose…',
												'radius-hotel-booking-pro'
											) }
										/>
									</SelectTrigger>
									<SelectContent className="rtbp-root">
										{ employees.map( ( e ) => (
											<SelectItem
												key={ e.id }
												value={ String( e.id ) }
											>
												{ e.number
													? `${ e.name } · ${ e.number }`
													: e.name }
											</SelectItem>
										) ) }
									</SelectContent>
								</Select>
							</Field>
						) : null }
						<Field
							label={ __( 'Type', 'radius-hotel-booking-pro' ) }
							error={ errors.type }
							required
						>
							<Select
								value={ values.type }
								onValueChange={ ( v ) => set( 'type', v ) }
							>
								<SelectTrigger>
									<SelectValue />
								</SelectTrigger>
								<SelectContent className="rtbp-root">
									{ Object.entries( types ).map(
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
								'First day',
								'radius-hotel-booking-pro'
							) }
							error={ errors.start_date }
							required
						>
							<DateField
								value={ values.start_date }
								onChange={ ( day ) => set( 'start_date', day ) }
							/>
						</Field>
						<Field
							label={ __(
								'Last day',
								'radius-hotel-booking-pro'
							) }
							error={ errors.end_date }
							required
						>
							<DateField
								value={ values.end_date }
								min={ values.start_date || undefined }
								onChange={ ( day ) => set( 'end_date', day ) }
							/>
						</Field>
					</div>
					<p
						className={
							over
								? 'm-0 text-sm text-destructive'
								: 'm-0 text-sm text-muted-foreground'
						}
					>
						{ [
							days ? daysText( days ) : '',
							'day_off' === values.type && balance.data
								? vacationText( balance.data )
								: '',
						]
							.filter( Boolean )
							.join( ' · ' ) }
					</p>
					<Field
						label={ __( 'Reason', 'radius-hotel-booking-pro' ) }
						error={ errors.reason }
					>
						<Textarea
							rows={ 2 }
							maxLength={ 1000 }
							value={ values.reason }
							onChange={ ( e ) =>
								set( 'reason', e.target.value )
							}
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
						<Button type="submit" disabled={ save.isPending }>
							{ __( 'Add request', 'radius-hotel-booking-pro' ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

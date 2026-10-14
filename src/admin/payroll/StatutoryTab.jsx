/**
 * Payroll → Statutory (M15 T6, 15.12, 15.13): a month's contributions per
 * authority — the tax and social security lines, and the deductions and garnishments to remit —
 * from the settled pay runs paid that month, with what was paid, what is
 * left and the state; record a payment (`payroll.statutory_pay`), void one
 * entered by mistake.
 */
import { Suspense, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Landmark } from 'lucide-react';
import {
	Button,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	EmptyState,
	Field,
	Input,
	Panel,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
	StatusBadge,
	Textarea,
} from '@rtbp/ui';
import { access, format, toast, toastError } from '@rtbp/lib';

import { DateField } from '../pickers';

import {
	useStatutoryMonth,
	useStatutoryMonths,
	useStatutoryPayment,
} from './api';

const money = ( v ) => format.formatMoney( v );

/**
 * "October 2026".
 *
 * @param {number} year  Year.
 * @param {number} month Month.
 * @return {string} Label.
 */
const monthLabel = ( year, month ) =>
	// A PHP date format, so the month name follows the site's language (as every other date).
	format.formatDateAs(
		`${ year }-${ String( month ).padStart( 2, '0' ) }-01`,
		'F Y'
	);

/**
 * The state badge: payment colours, voided as a cancelled document.
 *
 * @param {Object} props       Props.
 * @param {string} props.state unpaid|partial|paid|voided.
 * @return {JSX.Element} Badge.
 */
function State( { state } ) {
	if ( 'voided' === state ) {
		return <StatusBadge domain="document" value="voided" />;
	}
	return (
		<StatusBadge
			domain="payment"
			value={ 'partial' === state ? 'partially_paid' : state }
		/>
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
 * Record a payment for one contribution.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.item    The contribution, with `year` and `month`.
 * @param {Function} props.onClose Close.
 * @return {JSX.Element} Dialog.
 */
function PayDialog( { item, onClose } ) {
	const pay = useStatutoryPayment();
	const [ values, setValues ] = useState( {
		amount: String( Number( item.balance ) ),
		paid_on: format.siteToday(),
		method: '',
		reference: '',
	} );
	const [ errors, setErrors ] = useState( {} );
	const set = ( key, value ) => {
		setValues( ( c ) => ( { ...c, [ key ]: value } ) );
		setErrors( ( c ) => ( { ...c, [ key ]: undefined } ) );
	};
	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await pay.mutateAsync( {
				year: item.year,
				month: item.month,
				authority: item.authority,
				code: item.code,
				...values,
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
			<DialogContent className="rtbp-root max-w-md">
				<form className="space-y-4" onSubmit={ submit } noValidate>
					<DialogHeader>
						<DialogTitle>
							{ __(
								'Record a payment',
								'radius-hotel-booking-pro'
							) }
						</DialogTitle>
						<DialogDescription>
							{ sprintf(
								/* translators: 1: contribution, 2: authority, 3: month, 4: amount still due. */
								__(
									'%1$s to %2$s for %3$s — %4$s still due.',
									'radius-hotel-booking-pro'
								),
								item.label,
								item.authority || '—',
								monthLabel( item.year, item.month ),
								money( item.balance )
							) }
						</DialogDescription>
					</DialogHeader>
					<div className="grid gap-4 sm:grid-cols-2">
						<Field
							label={ __( 'Amount', 'radius-hotel-booking-pro' ) }
							error={ errors.amount }
							required
						>
							<Input
								type="number"
								min="0"
								step="1"
								inputMode="decimal"
								value={ values.amount }
								onChange={ ( e ) =>
									set( 'amount', e.target.value )
								}
							/>
						</Field>
						<Field
							label={ __(
								'Paid on',
								'radius-hotel-booking-pro'
							) }
							error={ errors.paid_on }
							required
						>
							<DateField
								value={ values.paid_on }
								max={ format.siteToday() }
								onChange={ ( day ) => set( 'paid_on', day ) }
							/>
						</Field>
						<Field
							label={ __(
								'Paid by',
								'radius-hotel-booking-pro'
							) }
						>
							<Input
								value={ values.method }
								maxLength={ 60 }
								onChange={ ( e ) =>
									set( 'method', e.target.value )
								}
							/>
						</Field>
						<Field
							label={ __(
								'Reference',
								'radius-hotel-booking-pro'
							) }
						>
							<Input
								value={ values.reference }
								maxLength={ 150 }
								onChange={ ( e ) =>
									set( 'reference', e.target.value )
								}
							/>
						</Field>
					</div>
					<DialogFooter>
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button type="submit" disabled={ pay.isPending }>
							{ __( 'Record', 'radius-hotel-booking-pro' ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

/**
 * Void a payment.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.payment Payment.
 * @param {Function} props.onClose Close.
 * @return {JSX.Element} Dialog.
 */
function VoidPaymentDialog( { payment, onClose } ) {
	const pay = useStatutoryPayment();
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await pay.mutateAsync( {
				void: payment.id,
				reason,
			} );
			toast.success( response.message );
			onClose();
		} catch ( err ) {
			setError( err.errors?.reason ? message( err.errors.reason ) : '' );
			if ( ! err.errors?.reason ) {
				toastError( err );
			}
		}
	};
	return (
		<Dialog open onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-w-md">
				<form className="space-y-4" onSubmit={ submit } noValidate>
					<DialogHeader>
						<DialogTitle>
							{ sprintf(
								/* translators: %s: amount. */
								__(
									'Void the payment of %s?',
									'radius-hotel-booking-pro'
								),
								money( payment.amount )
							) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'It stays listed, crossed out; the amount is due again.',
								'radius-hotel-booking-pro'
							) }
						</DialogDescription>
					</DialogHeader>
					<Field
						label={ __( 'Why', 'radius-hotel-booking-pro' ) }
						error={ error }
						required
					>
						<Textarea
							rows={ 2 }
							maxLength={ 255 }
							value={ reason }
							onChange={ ( e ) => {
								setReason( e.target.value );
								setError( '' );
							} }
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
							disabled={ pay.isPending }
						>
							{ __( 'Void', 'radius-hotel-booking-pro' ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

/**
 * @return {JSX.Element} Tab.
 */
export default function StatutoryTab() {
	const months = useStatutoryMonths();
	const [ picked, setPicked ] = useState( '' );
	const list = months.data || [];
	const current =
		list.find( ( m ) => `${ m.year }-${ m.month }` === picked ) ||
		list[ 0 ];
	const month = useStatutoryMonth( current?.year || 0, current?.month || 0 );
	const canPay = access?.useAccess
		? access.useAccess( 'payroll.statutory_pay' ) !== 'locked'
		: false;
	const [ paying, setPaying ] = useState( null );
	const [ voiding, setVoiding ] = useState( null );

	if ( months.isPending ) {
		return <Skeleton className="h-64 w-full rounded-xl" />;
	}
	if ( ! list.length ) {
		return (
			<Panel>
				<EmptyState
					icon={ Landmark }
					title={ __(
						'No contributions yet',
						'radius-hotel-booking-pro'
					) }
					description={ __(
						'They appear here once a pay run is settled.',
						'radius-hotel-booking-pro'
					) }
					className="border-0 py-12"
				/>
			</Panel>
		);
	}

	const data = month.data;
	return (
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<div className="space-y-4">
				<Panel>
					<div className="flex flex-wrap items-end justify-between gap-3">
						<Field
							label={ __(
								'Month the pay was paid',
								'radius-hotel-booking-pro'
							) }
						>
							<Select
								value={ `${ current.year }-${ current.month }` }
								onValueChange={ setPicked }
							>
								<SelectTrigger className="w-56">
									<SelectValue />
								</SelectTrigger>
								<SelectContent className="rtbp-root">
									{ list.map( ( m ) => (
										<SelectItem
											key={ `${ m.year }-${ m.month }` }
											value={ `${ m.year }-${ m.month }` }
										>
											{ monthLabel( m.year, m.month ) }
										</SelectItem>
									) ) }
								</SelectContent>
							</Select>
						</Field>
						{ data ? (
							<dl className="m-0 grid grid-cols-3 gap-4 text-sm">
								{ [
									[
										__( 'Due', 'radius-hotel-booking-pro' ),
										data.totals.due,
									],
									[
										__(
											'Paid',
											'radius-hotel-booking-pro'
										),
										data.totals.paid,
									],
									[
										__(
											'Left to pay',
											'radius-hotel-booking-pro'
										),
										data.totals.balance,
									],
								].map( ( [ label, value ] ) => (
									<div key={ label }>
										<dt className="text-muted-foreground">
											{ label }
										</dt>
										<dd className="m-0 font-semibold tabular-nums text-heading">
											{ money( value ) }
										</dd>
									</div>
								) ) }
							</dl>
						) : null }
					</div>
				</Panel>
				{ month.isPending ? (
					<Skeleton className="h-48 w-full rounded-xl" />
				) : null }
				{ ( data?.items || [] ).map( ( item ) => (
					<Panel key={ item.authority + item.code }>
						<div className="flex flex-wrap items-start justify-between gap-3">
							<div className="min-w-0 space-y-1">
								<div className="flex flex-wrap items-center gap-2">
									<span className="font-semibold text-heading">
										{ item.label }
									</span>
									<State state={ item.state } />
								</div>
								<p className="m-0 text-xs text-muted-foreground">
									{ [
										item.authority,
										sprintf(
											/* translators: 1: employee share, 2: employer share. */
											__(
												'employees %1$s · hotel %2$s',
												'radius-hotel-booking-pro'
											),
											money( item.employee ),
											money( item.employer )
										),
									]
										.filter( Boolean )
										.join( ' · ' ) }
								</p>
							</div>
							<div className="flex items-center gap-4 text-sm">
								<div className="text-right">
									<div className="font-semibold tabular-nums text-heading">
										{ money( item.due ) }
									</div>
									<div className="text-xs text-muted-foreground">
										{ sprintf(
											/* translators: %s: amount left. */
											__(
												'%s left',
												'radius-hotel-booking-pro'
											),
											money( item.balance )
										) }
									</div>
								</div>
								{ canPay &&
								Number( item.balance ) > 0 &&
								'voided' !== item.state ? (
									<Button
										variant="outline"
										size="sm"
										onClick={ () =>
											setPaying( {
												...item,
												year: current.year,
												month: current.month,
											} )
										}
									>
										{ __(
											'Record payment',
											'radius-hotel-booking-pro'
										) }
									</Button>
								) : null }
							</div>
						</div>
						{ item.payments.length ? (
							<ul className="m-0 mt-3 list-none space-y-1 border-t p-0 pt-2 text-sm">
								{ item.payments.map( ( p ) => (
									<li
										key={ p.id }
										className="flex flex-wrap items-center justify-between gap-2"
									>
										<span
											className={
												p.voided_at
													? 'text-muted-foreground line-through'
													: ''
											}
										>
											{ [
												format.formatDate( p.paid_on ),
												money( p.amount ),
												p.method,
												p.reference,
											]
												.filter( Boolean )
												.join( ' · ' ) }
										</span>
										{ p.voided_at ? (
											<span className="text-xs text-muted-foreground">
												{ sprintf(
													/* translators: %s: reason. */ __(
														'Voided: %s',
														'radius-hotel-booking-pro'
													),
													p.void_reason
												) }
											</span>
										) : canPay ? (
											<Button
												variant="ghost"
												size="sm"
												className="text-destructive"
												onClick={ () =>
													setVoiding( p )
												}
											>
												{ __(
													'Void',
													'radius-hotel-booking-pro'
												) }
											</Button>
										) : null }
									</li>
								) ) }
							</ul>
						) : null }
					</Panel>
				) ) }
			</div>
			{ paying ? (
				<PayDialog
					item={ paying }
					onClose={ () => setPaying( null ) }
				/>
			) : null }
			{ voiding ? (
				<VoidPaymentDialog
					payment={ voiding }
					onClose={ () => setVoiding( null ) }
				/>
			) : null }
		</Suspense>
	);
}

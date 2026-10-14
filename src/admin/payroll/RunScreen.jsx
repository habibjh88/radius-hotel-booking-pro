/**
 * One pay run (`#/payroll/runs/:id`, M15 T5c, 15.1–15.8): its period,
 * status and totals, its lines (a line opens its calculation and, on a
 * draft, its editor), and the moves its status allows — recalculate,
 * finalise, remove (draft); settle, revert (finalised); void (settled) —
 * each behind its own access key.
 */
import { Suspense, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	DataTable,
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
	Skeleton,
	StatusBadge,
	Textarea,
} from '@rtbp/ui';
import { access, format, toast, toastError } from '@rtbp/lib';

import LineDialog from './LineDialog';
import { periodText } from './RunsTab';
import { usePayrollRun, usePayrollRunAction } from './api';

const money = ( v ) => format.formatMoney( v );

/**
 * Settle: a payment method for every line.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.run     Run.
 * @param {boolean}  props.open    Open.
 * @param {Function} props.onClose Close.
 * @return {JSX.Element} Dialog.
 */
function SettleDialog( { run, open, onClose } ) {
	const action = usePayrollRunAction();
	const [ all, setAll ] = useState( '' );
	const [ payments, setPayments ] = useState( {} );
	const [ errors, setErrors ] = useState( {} );
	const set = ( id, key, value ) => {
		setPayments( ( c ) => ( {
			...c,
			[ id ]: { ...c[ id ], [ key ]: value },
		} ) );
		setErrors( ( c ) => ( {
			...c,
			[ `payments.${ id }.method` ]: undefined,
		} ) );
	};
	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await action.mutateAsync( {
				action: 'settle',
				id: run.id,
				payments,
			} );
			toast.success( response.message );
			onClose();
		} catch ( err ) {
			const mapped = {};
			Object.entries( err.errors || {} ).forEach(
				( [ k, v ] ) =>
					( mapped[ k ] = v?.first_message ?? String( v ) )
			);
			setErrors( mapped );
			if ( ! Object.keys( mapped ).length ) {
				toastError( err );
			}
		}
	};
	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-h-[90vh] max-w-lg overflow-y-auto">
				<form className="space-y-4" onSubmit={ submit } noValidate>
					<DialogHeader>
						<DialogTitle>
							{ __(
								'Settle the pay run',
								'radius-hotel-booking-pro'
							) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'How each employee was paid. The payslips are then final.',
								'radius-hotel-booking-pro'
							) }
						</DialogDescription>
					</DialogHeader>
					<div className="flex flex-wrap items-end gap-2">
						<Field
							label={ __(
								'Same method for everyone',
								'radius-hotel-booking-pro'
							) }
						>
							<Input
								value={ all }
								maxLength={ 60 }
								placeholder={ __(
									'e.g. Bank transfer',
									'radius-hotel-booking-pro'
								) }
								onChange={ ( e ) => setAll( e.target.value ) }
							/>
						</Field>
						<Button
							type="button"
							variant="outline"
							disabled={ ! all.trim() }
							onClick={ () => {
								run.lines.forEach( ( l ) =>
									set( l.id, 'method', all.trim() )
								);
								setErrors( {} );
							} }
						>
							{ __( 'Apply', 'radius-hotel-booking-pro' ) }
						</Button>
					</div>
					<div className="space-y-2">
						{ run.lines.map( ( l ) => (
							<div
								key={ l.id }
								className="space-y-2 rounded-lg border p-3"
							>
								<div className="flex justify-between gap-3 text-sm">
									<span className="font-medium">
										{ l.employee_name }
									</span>
									<span className="tabular-nums">
										{ money( l.net ) }
									</span>
								</div>
								<div className="grid gap-2 sm:grid-cols-2">
									<Field
										label={ __(
											'Paid by',
											'radius-hotel-booking-pro'
										) }
										error={
											errors[
												`payments.${ l.id }.method`
											]
										}
										required
									>
										<Input
											value={
												payments[ l.id ]?.method || ''
											}
											maxLength={ 60 }
											onChange={ ( e ) =>
												set(
													l.id,
													'method',
													e.target.value
												)
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
											value={
												payments[ l.id ]?.reference ||
												''
											}
											maxLength={ 100 }
											onChange={ ( e ) =>
												set(
													l.id,
													'reference',
													e.target.value
												)
											}
										/>
									</Field>
								</div>
							</div>
						) ) }
					</div>
					<DialogFooter>
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button type="submit" disabled={ action.isPending }>
							{ __( 'Settle', 'radius-hotel-booking-pro' ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

/**
 * Void: why.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.run     Run.
 * @param {boolean}  props.open    Open.
 * @param {Function} props.onClose Close.
 * @return {JSX.Element} Dialog.
 */
function VoidDialog( { run, open, onClose } ) {
	const action = usePayrollRunAction();
	const [ reason, setReason ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await action.mutateAsync( {
				action: 'void',
				id: run.id,
				reason,
			} );
			toast.success( response.message );
			onClose();
		} catch ( err ) {
			setError( err.errors?.reason?.first_message || '' );
			if ( ! err.errors?.reason ) {
				toastError( err );
			}
		}
	};
	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-w-md">
				<form className="space-y-4" onSubmit={ submit } noValidate>
					<DialogHeader>
						<DialogTitle>
							{ __(
								'Void the pay run?',
								'radius-hotel-booking-pro'
							) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'It stays in the history, marked voided; what it took from garnishments and time-off balances is given back. The period can then be run again.',
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
							disabled={ action.isPending }
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
 * @return {JSX.Element} Screen.
 */
export default function RunScreen() {
	const { id: param } = useParams();
	const id = Number( param ) || 0;
	const navigate = useNavigate();
	const { data: run, isPending, error, refetch } = usePayrollRun( id );
	const action = usePayrollRunAction();
	const level = ( key ) =>
		access?.useAccess ? access.useAccess( key ) : 'locked';
	const can = {
		run: 'locked' !== level( 'payroll.run' ),
		edit: 'locked' !== level( 'payroll.edit_line' ),
		finalize: 'locked' !== level( 'payroll.finalize' ),
		settle: 'locked' !== level( 'payroll.settle' ),
		void: 'locked' !== level( 'payroll.void' ),
		revert: 'locked' !== level( 'payroll.revert' ),
		remove: 'locked' !== level( 'payroll.remove' ),
	};
	const [ confirming, setConfirming ] = useState( null );
	const [ settling, setSettling ] = useState( false );
	const [ voiding, setVoiding ] = useState( false );
	const [ lineId, setLineId ] = useState( null );

	const back = (
		<Link
			to="/payroll"
			className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-heading"
		>
			<ArrowLeft className="h-4 w-4" aria-hidden="true" />
			{ __( 'All pay runs', 'radius-hotel-booking-pro' ) }
		</Link>
	);

	if ( isPending ) {
		return <Skeleton className="h-64 w-full rounded-xl" />;
	}
	if ( error || ! run ) {
		return (
			<div className="space-y-4">
				{ back }
				<Panel>
					<EmptyState
						title={ __(
							'This pay run could not be loaded',
							'radius-hotel-booking-pro'
						) }
						description={ error?.message }
						action={
							<Button
								variant="outline"
								onClick={ () => refetch() }
							>
								{ __(
									'Try again',
									'radius-hotel-booking-pro'
								) }
							</Button>
						}
						className="border-0"
					/>
				</Panel>
			</div>
		);
	}

	const draft = 'draft' === run.status;
	const periodOpen = run.can_finalize_from > format.siteToday();
	const moves = {
		recalculate: {
			title: __( 'Recalculate every line?', 'radius-hotel-booking-pro' ),
			description: __(
				'With each employee’s current pay, additional pay and deductions. What was entered on the lines is kept.',
				'radius-hotel-booking-pro'
			),
			label: __( 'Recalculate', 'radius-hotel-booking-pro' ),
		},
		finalize: {
			title: __( 'Finalise the pay run?', 'radius-hotel-booking-pro' ),
			description: __(
				'The lines are locked as they are. It can be reverted to draft until it is settled.',
				'radius-hotel-booking-pro'
			),
			label: __( 'Finalise', 'radius-hotel-booking-pro' ),
		},
		revert: {
			title: __( 'Back to draft?', 'radius-hotel-booking-pro' ),
			description: __(
				'The lines can be edited and recalculated again; finalise once more when done.',
				'radius-hotel-booking-pro'
			),
			label: __( 'Back to draft', 'radius-hotel-booking-pro' ),
		},
		remove: {
			title: __( 'Remove this draft?', 'radius-hotel-booking-pro' ),
			description: __(
				'The draft and its lines are deleted. The period can be run again.',
				'radius-hotel-booking-pro'
			),
			label: __( 'Remove', 'radius-hotel-booking-pro' ),
			destructive: true,
		},
	};
	const doMove = ( move ) =>
		action
			.mutateAsync( { action: move, id: run.id } )
			.then( ( response ) => {
				toast.success( response.message );
				if ( 'remove' === move ) {
					navigate( '/payroll' );
				}
			} )
			.catch( ( err ) => toastError( err ) );

	const columns = [
		{
			id: 'employee',
			header: __( 'Employee', 'radius-hotel-booking-pro' ),
			mobile: 'title',
			cell: ( l ) => (
				<span className="inline-flex flex-wrap items-baseline gap-x-2">
					<span className="font-semibold text-heading">
						{ l.employee_name }
					</span>
					<span className="text-xs text-muted-foreground">
						{ l.employee_no }
					</span>
				</span>
			),
		},
		{
			id: 'gross',
			header: __( 'Gross', 'radius-hotel-booking-pro' ),
			cell: ( l ) => money( l.gross ),
		},
		{
			id: 'tax',
			header: __( 'Taxes and contributions', 'radius-hotel-booking-pro' ),
			cell: ( l ) => money( l.employee_tax ),
		},
		{
			id: 'net',
			header: __( 'Net pay', 'radius-hotel-booking-pro' ),
			mobile: 'subtitle',
			cell: ( l ) => (
				<span className="font-semibold">{ money( l.net ) }</span>
			),
		},
		{
			id: 'cost',
			header: __( 'Cost to the hotel', 'radius-hotel-booking-pro' ),
			cell: ( l ) => money( l.employer_cost ),
		},
		...( run.lines.some( ( l ) => l.payment_method )
			? [
					{
						id: 'paid',
						header: __( 'Paid by', 'radius-hotel-booking-pro' ),
						cell: ( l ) =>
							[ l.payment_method, l.payment_ref ]
								.filter( Boolean )
								.join( ' · ' ),
					},
			  ]
			: [] ),
	];

	return (
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<div className="mx-auto max-w-5xl space-y-4">
				{ back }
				<Panel>
					<div className="flex flex-wrap items-start justify-between gap-3">
						<div className="min-w-0 space-y-1">
							<div className="flex flex-wrap items-center gap-2">
								<h2 className="m-0 text-xl font-semibold text-heading">
									{ periodText( run ) }
								</h2>
								<StatusBadge
									domain="document"
									value={ run.status }
								/>
							</div>
							<p className="m-0 text-sm text-muted-foreground">
								{ sprintf(
									/* translators: 1: pay schedule, 2: pay day. */
									__(
										'%1$s · paid on %2$s',
										'radius-hotel-booking-pro'
									),
									run.schedule_name,
									format.formatDate( run.pay_date )
								) }
							</p>
							{ run.void_reason ? (
								<p className="m-0 text-sm text-destructive">
									{ sprintf(
										/* translators: %s: reason. */ __(
											'Voided: %s',
											'radius-hotel-booking-pro'
										),
										run.void_reason
									) }
								</p>
							) : null }
						</div>
						<div className="flex flex-wrap gap-2">
							{ draft && can.run ? (
								<Button
									variant="outline"
									onClick={ () =>
										setConfirming( 'recalculate' )
									}
								>
									{ moves.recalculate.label }
								</Button>
							) : null }
							{ draft && can.remove ? (
								<Button
									variant="outline"
									className="text-destructive"
									onClick={ () => setConfirming( 'remove' ) }
								>
									{ moves.remove.label }
								</Button>
							) : null }
							{ draft && can.finalize ? (
								<Button
									onClick={ () =>
										setConfirming( 'finalize' )
									}
									disabled={ periodOpen }
									title={
										periodOpen
											? __(
													'Possible once the pay period has ended.',
													'radius-hotel-booking-pro'
											  )
											: undefined
									}
								>
									{ moves.finalize.label }
								</Button>
							) : null }
							{ 'finalized' === run.status && can.revert ? (
								<Button
									variant="outline"
									onClick={ () => setConfirming( 'revert' ) }
								>
									{ moves.revert.label }
								</Button>
							) : null }
							{ 'finalized' === run.status && can.settle ? (
								<Button onClick={ () => setSettling( true ) }>
									{ __(
										'Settle',
										'radius-hotel-booking-pro'
									) }
								</Button>
							) : null }
							{ 'settled' === run.status && can.void ? (
								<Button
									variant="outline"
									className="text-destructive"
									onClick={ () => setVoiding( true ) }
								>
									{ __( 'Void', 'radius-hotel-booking-pro' ) }
								</Button>
							) : null }
						</div>
					</div>
					{ draft && periodOpen && can.finalize ? (
						<p className="m-0 mt-2 text-xs text-muted-foreground">
							{ sprintf(
								/* translators: %s: date. */
								__(
									'It can be finalised from %s, the last day of the pay period.',
									'radius-hotel-booking-pro'
								),
								format.formatDate( run.can_finalize_from )
							) }
						</p>
					) : null }
					<dl className="m-0 mt-4 grid grid-cols-2 gap-3 text-sm lg:grid-cols-4">
						{ [
							[
								__( 'Gross', 'radius-hotel-booking-pro' ),
								run.gross_total,
							],
							[
								__(
									'Taxes and contributions',
									'radius-hotel-booking-pro'
								),
								run.employee_tax_total,
							],
							[
								__( 'Net pay', 'radius-hotel-booking-pro' ),
								run.net_total,
							],
							[
								__(
									'Cost to the hotel',
									'radius-hotel-booking-pro'
								),
								run.employer_cost_total,
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
				</Panel>
				<Panel>
					<DataTable
						columns={ columns }
						rows={ run.lines }
						total={ run.lines.length }
						onRowClick={ ( l ) => setLineId( l.id ) }
						caption={ __( 'Lines', 'radius-hotel-booking-pro' ) }
					/>
				</Panel>
			</div>
			<LineDialog
				run={ run }
				line={ run.lines.find( ( l ) => l.id === lineId ) || null }
				canEdit={ draft && can.edit }
				onClose={ () => setLineId( null ) }
			/>
			<ConfirmDialog
				open={ Boolean( confirming ) }
				onOpenChange={ ( open ) => ! open && setConfirming( null ) }
				title={ moves[ confirming ]?.title || '' }
				description={ moves[ confirming ]?.description || '' }
				confirmLabel={ moves[ confirming ]?.label || '' }
				destructive={ Boolean( moves[ confirming ]?.destructive ) }
				onConfirm={ () => doMove( confirming ) }
			/>
			<SettleDialog
				run={ run }
				open={ settling }
				onClose={ () => setSettling( false ) }
			/>
			<VoidDialog
				run={ run }
				open={ voiding }
				onClose={ () => setVoiding( false ) }
			/>
		</Suspense>
	);
}

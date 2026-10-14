/**
 * Payroll → Time off (M15 T8b, 15.18–15.20, 17.3): the requests, pending
 * first (the queue) and decided ones (the history), filtered by status, year
 * and employee; an employee's balance for the year (the vacation quota and
 * the approved days per type); add a request for an employee, approve or
 * decline one (`timeoff.approve`). The server re-checks the quota and
 * overlaps, so the screen only shows what it will say.
 */
import { Suspense, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { CalendarOff, Check, Plus, X } from 'lucide-react';
import {
	Button,
	DataTable,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	EmptyState,
	Field,
	FilterTabs,
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
import { access, format, toast, toastError, usePageActions } from '@rtbp/lib';
import { useSearchParams } from 'react-router-dom';

import { useTimeOff, useTimeOffBalance, useTimeOffMutation } from './api';
import {
	BalancePanel,
	RequestDialog,
	datesText,
	daysText,
	vacationText,
} from './timeOffParts';

const PER_PAGE = 20;
const ALL = 'all';

/**
 * Approve or decline a pending request, with an optional note.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.request  The request.
 * @param {string}   props.decision approve|decline.
 * @param {Function} props.onClose  Close.
 * @return {JSX.Element} Dialog.
 */
function DecideDialog( { request, decision, onClose } ) {
	const decide = useTimeOffMutation();
	const [ note, setNote ] = useState( '' );
	const approve = 'approve' === decision;
	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await decide.mutateAsync( {
				id: request.id,
				decision,
				note,
			} );
			toast.success( response.message );
			onClose();
		} catch ( err ) {
			toastError( err );
		}
	};
	return (
		<Dialog open onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-w-md">
				<form className="space-y-4" onSubmit={ submit } noValidate>
					<DialogHeader>
						<DialogTitle>
							{ approve
								? __(
										'Approve this time off?',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Decline this time off?',
										'radius-hotel-booking-pro'
								  ) }
						</DialogTitle>
						<DialogDescription>
							{ sprintf(
								/* translators: 1: employee, 2: type of time off, 3: dates, 4: "5 days". */
								__(
									'%1$s — %2$s, %3$s (%4$s).',
									'radius-hotel-booking-pro'
								),
								request.employee_name,
								request.type_label,
								datesText( request ),
								daysText( request.days )
							) }
						</DialogDescription>
					</DialogHeader>
					{ 'day_off' === request.type && request.balance ? (
						<p className="m-0 text-sm text-muted-foreground">
							{ vacationText( request.balance ) }
						</p>
					) : null }
					<Field
						label={ __(
							'Note (optional)',
							'radius-hotel-booking-pro'
						) }
					>
						<Textarea
							rows={ 2 }
							maxLength={ 255 }
							value={ note }
							onChange={ ( e ) => setNote( e.target.value ) }
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
							variant={ approve ? 'default' : 'destructive' }
							disabled={ decide.isPending }
						>
							{ approve
								? __( 'Approve', 'radius-hotel-booking-pro' )
								: __( 'Decline', 'radius-hotel-booking-pro' ) }
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
export default function TimeOffTab() {
	const [ params, setParams ] = useSearchParams();
	const status = params.get( 'status' ) ?? 'pending';
	const employee = Number( params.get( 'employee' ) || 0 );
	const thisYear = Number( format.siteToday().slice( 0, 4 ) );
	const year = Number( params.get( 'year' ) || thisYear );
	const page = Math.max( 1, Number( params.get( 'page' ) || 1 ) );
	const list = useTimeOff( {
		status,
		employee_id: employee || undefined,
		year,
	} );
	const balance = useTimeOffBalance( employee, year );
	const canDecide = access?.useAccess
		? access.useAccess( 'timeoff.approve' ) !== 'locked'
		: false;
	const [ adding, setAdding ] = useState( false );
	const [ deciding, setDeciding ] = useState( null );

	usePageActions(
		canDecide ? (
			<Button onClick={ () => setAdding( true ) }>
				<Plus className="h-4 w-4" aria-hidden="true" />
				<span className="sr-only sm:not-sr-only">
					{ __( 'Request time off', 'radius-hotel-booking-pro' ) }
				</span>
			</Button>
		) : null
	);

	const setView = ( next ) =>
		setParams(
			( current ) => {
				const out = new URLSearchParams( current );
				Object.entries( next ).forEach( ( [ k, v ] ) =>
					null === v ? out.delete( k ) : out.set( k, v )
				);
				return out;
			},
			{ replace: true }
		);

	const data = list.data;
	const types = data?.types || {};
	const employees = data?.employees || [];
	const rows = data?.requests || [];
	const shown = rows.slice( ( page - 1 ) * PER_PAGE, page * PER_PAGE );
	const employeeName =
		employees.find( ( e ) => e.id === employee )?.name ||
		rows.find( ( r ) => r.employee_id === employee )?.employee_name ||
		'';
	const years = [ thisYear + 1, thisYear, thisYear - 1, thisYear - 2 ];
	if ( ! years.includes( year ) ) {
		years.push( year );
	}

	const columns = [
		{
			id: 'employee',
			header: __( 'Employee', 'radius-hotel-booking-pro' ),
			mobile: 'title',
			cell: ( r ) => (
				<span className="inline-flex flex-wrap items-baseline gap-x-2">
					<span className="font-semibold text-heading">
						{ r.employee_name }
					</span>
					{ r.employee_no ? (
						<span className="text-xs text-muted-foreground">
							{ r.employee_no }
						</span>
					) : null }
				</span>
			),
		},
		{
			id: 'dates',
			header: __( 'Dates', 'radius-hotel-booking-pro' ),
			mobile: 'subtitle',
			cell: ( r ) => datesText( r ),
		},
		{
			id: 'type',
			header: __( 'Type', 'radius-hotel-booking-pro' ),
			cell: ( r ) => r.type_label,
		},
		{
			id: 'days',
			header: __( 'Days', 'radius-hotel-booking-pro' ),
			align: 'right',
			cell: ( r ) => String( r.days ),
		},
		{
			id: 'status',
			header: __( 'Status', 'radius-hotel-booking-pro' ),
			cell: ( r ) => <StatusBadge domain="request" value={ r.status } />,
		},
		{
			id: 'note',
			header: __( 'Reason / note', 'radius-hotel-booking-pro' ),
			wrap: true,
			cell: ( r ) =>
				[ r.reason, r.review_note ].filter( Boolean ).join( ' — ' ) ||
				'—',
		},
	];

	if ( list.isPending && ! data ) {
		return <Skeleton className="h-64 w-full rounded-xl" />;
	}

	return (
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<div className="space-y-4">
				<Panel>
					<div className="space-y-3">
						<FilterTabs
							label={ __( 'Status', 'radius-hotel-booking-pro' ) }
							tabs={ [
								{
									value: 'pending',
									label: __(
										'Awaiting approval',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'approved',
									label: __(
										'Approved',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'declined',
									label: __(
										'Declined',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'cancelled',
									label: __(
										'Cancelled',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: '',
									label: __(
										'All',
										'radius-hotel-booking-pro'
									),
								},
							] }
							value={ status }
							onChange={ ( value ) =>
								setView( { status: value, page: null } )
							}
						/>
						<div className="flex flex-wrap items-end gap-3">
							<Field
								label={ __(
									'Year',
									'radius-hotel-booking-pro'
								) }
							>
								<Select
									value={ String( year ) }
									onValueChange={ ( v ) =>
										setView( { year: v, page: null } )
									}
								>
									<SelectTrigger className="w-28">
										<SelectValue />
									</SelectTrigger>
									<SelectContent className="rtbp-root">
										{ years.map( ( y ) => (
											<SelectItem
												key={ y }
												value={ String( y ) }
											>
												{ String( y ) }
											</SelectItem>
										) ) }
									</SelectContent>
								</Select>
							</Field>
							<Field
								label={ __(
									'Employee',
									'radius-hotel-booking-pro'
								) }
							>
								<Select
									value={
										employee ? String( employee ) : ALL
									}
									onValueChange={ ( v ) =>
										setView( {
											employee: ALL === v ? null : v,
											page: null,
										} )
									}
								>
									<SelectTrigger className="w-64 max-w-full">
										<SelectValue />
									</SelectTrigger>
									<SelectContent className="rtbp-root">
										<SelectItem value={ ALL }>
											{ __(
												'All employees',
												'radius-hotel-booking-pro'
											) }
										</SelectItem>
										{ employees.map( ( e ) => (
											<SelectItem
												key={ e.id }
												value={ String( e.id ) }
											>
												{ e.name }
											</SelectItem>
										) ) }
									</SelectContent>
								</Select>
							</Field>
						</div>
					</div>
				</Panel>
				{ employee && balance.data ? (
					<BalancePanel
						balance={ balance.data }
						types={ types }
						employee={ employeeName }
					/>
				) : null }
				<Panel>
					<DataTable
						columns={ columns }
						rows={ shown }
						total={ rows.length }
						page={ page }
						perPage={ PER_PAGE }
						onPageChange={ ( next ) =>
							setView( { page: String( next ) } )
						}
						loading={ list.isFetching && ! data }
						error={ list.error }
						onRetry={ () => list.refetch() }
						rowActions={
							canDecide
								? ( r ) =>
										'pending' === r.status
											? [
													{
														label: __(
															'Approve',
															'radius-hotel-booking-pro'
														),
														icon: Check,
														primary: true,
														onSelect: () =>
															setDeciding( {
																request: r,
																decision:
																	'approve',
															} ),
													},
													{
														label: __(
															'Decline',
															'radius-hotel-booking-pro'
														),
														icon: X,
														destructive: true,
														onSelect: () =>
															setDeciding( {
																request: r,
																decision:
																	'decline',
															} ),
													},
											  ]
											: []
								: undefined
						}
						empty={
							<EmptyState
								icon={ CalendarOff }
								title={
									'pending' === status
										? __(
												'Nothing waiting for approval',
												'radius-hotel-booking-pro'
										  )
										: __(
												'No time off here',
												'radius-hotel-booking-pro'
										  )
								}
								description={ __(
									'Requests from staff and those added here are listed by status and year.',
									'radius-hotel-booking-pro'
								) }
								className="border-0 py-12"
							/>
						}
					/>
				</Panel>
			</div>
			{ adding ? (
				<RequestDialog
					employees={ employees }
					types={ types }
					today={ data?.today || format.siteToday() }
					preset={ employee }
					onClose={ () => setAdding( false ) }
					useSave={ useTimeOffMutation }
					useBalance={ useTimeOffBalance }
				/>
			) : null }
			{ deciding ? (
				<DecideDialog
					request={ deciding.request }
					decision={ deciding.decision }
					onClose={ () => setDeciding( null ) }
				/>
			) : null }
		</Suspense>
	);
}

/**
 * My payroll (`#/me/payroll`, M15 T9, 16.7–16.9), on the account menu: a
 * staff member's own payslips (settled runs; PDF) and time off (a month
 * calendar, the year's balance, ask for time off, cancel a pending request).
 * The server only ever reads the signed-in user's employee file.
 */
import { Suspense, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	CalendarOff,
	ChevronLeft,
	ChevronRight,
	Download,
	FileText,
	Plus,
} from 'lucide-react';
import { useSearchParams } from 'react-router-dom';
import {
	Button,
	CalendarGrid,
	ConfirmDialog,
	DataTable,
	EmptyState,
	FilterTabs,
	Panel,
	Skeleton,
	StatusBadge,
} from '@rtbp/ui';
import { format, toast, toastError, usePageActions } from '@rtbp/lib';

import { openFile } from '../payslip';
import {
	BalancePanel,
	RequestDialog,
	datesText,
	daysText,
} from '../timeOffParts';
import {
	useMyBalance,
	useMyPayslips,
	useMyTimeOff,
	useMyTimeOffMutation,
} from './api';

const PER_PAGE = 12;

/**
 * The API's "no employee file" answer, as an empty state; other errors as text.
 *
 * @param {Object} props       Props.
 * @param {Object} props.error Error.
 * @return {JSX.Element} State.
 */
function NoFile( { error } ) {
	return (
		<Panel>
			<EmptyState
				icon={ CalendarOff }
				title={
					'no_employee_file' === error?.code
						? __( 'No employee file', 'radius-hotel-booking-pro' )
						: __( 'Could not load', 'radius-hotel-booking-pro' )
				}
				description={ error?.message }
				className="border-0 py-12"
			/>
		</Panel>
	);
}

/**
 * My payslips.
 *
 * @return {JSX.Element} Tab.
 */
function Payslips() {
	const slips = useMyPayslips();
	const [ page, setPage ] = useState( 1 );
	const [ busy, setBusy ] = useState( 0 );
	if ( slips.error ) {
		return <NoFile error={ slips.error } />;
	}
	const rows = slips.data || [];
	const download = async ( slip ) => {
		setBusy( slip.id );
		try {
			await openFile( `me/payslips/${ slip.id }/pdf`, {}, 'pdf' );
		} catch ( err ) {
			toastError( err );
		} finally {
			setBusy( 0 );
		}
	};
	const columns = [
		{
			id: 'pay',
			header: __( 'Pay day', 'radius-hotel-booking-pro' ),
			mobile: 'title',
			cell: ( s ) => (
				<span className="font-semibold text-heading">
					{ format.formatDate( s.pay_date ) }
				</span>
			),
		},
		{
			id: 'period',
			header: __( 'Pay period', 'radius-hotel-booking-pro' ),
			mobile: 'subtitle',
			cell: ( s ) =>
				datesText( {
					start_date: s.period_start,
					end_date: s.period_end,
				} ),
		},
		{
			id: 'hours',
			header: __( 'Hours', 'radius-hotel-booking-pro' ),
			align: 'right',
			cell: ( s ) => String( Number( s.hours ) ),
		},
		{
			id: 'gross',
			header: __( 'Gross', 'radius-hotel-booking-pro' ),
			align: 'right',
			cell: ( s ) => format.formatMoney( s.gross ),
		},
		{
			id: 'net',
			header: __( 'Net pay', 'radius-hotel-booking-pro' ),
			align: 'right',
			cell: ( s ) => (
				<span className="font-semibold">
					{ format.formatMoney( s.net ) }
				</span>
			),
		},
	];
	return (
		<Panel>
			<DataTable
				columns={ columns }
				rows={ rows.slice( ( page - 1 ) * PER_PAGE, page * PER_PAGE ) }
				total={ rows.length }
				page={ page }
				perPage={ PER_PAGE }
				onPageChange={ setPage }
				loading={ slips.isPending }
				rowActions={ ( s ) => [
					{
						label: __( 'Download PDF', 'radius-hotel-booking-pro' ),
						icon: Download,
						primary: true,
						disabled: busy === s.id,
						onSelect: () => download( s ),
					},
				] }
				empty={
					<EmptyState
						icon={ FileText }
						title={ __(
							'No payslips yet',
							'radius-hotel-booking-pro'
						) }
						description={ __(
							'Your payslips appear here once a pay run is paid out.',
							'radius-hotel-booking-pro'
						) }
						className="border-0 py-12"
					/>
				}
			/>
		</Panel>
	);
}

/**
 * The days of a month, `Y-m-d`.
 *
 * @param {string} month `Y-m`.
 * @return {string[]} Dates.
 */
const daysOf = ( month ) => {
	const [ y, m ] = month.split( '-' ).map( Number );
	const last = new Date( Date.UTC( y, m, 0 ) ).getUTCDate();
	return Array.from(
		{ length: last },
		( _, i ) => `${ month }-${ String( i + 1 ).padStart( 2, '0' ) }`
	);
};

/**
 * `Y-m` moved by some months.
 *
 * @param {string} month `Y-m`.
 * @param {number} by    Months.
 * @return {string} `Y-m`.
 */
const shiftMonth = ( month, by ) => {
	const [ y, m ] = month.split( '-' ).map( Number );
	const d = new Date( Date.UTC( y, m - 1 + by, 1 ) );
	return `${ d.getUTCFullYear() }-${ String( d.getUTCMonth() + 1 ).padStart(
		2,
		'0'
	) }`;
};

const TONE = {
	pending: 'bg-warning-soft text-warning',
	approved: 'bg-success-soft text-success',
};

/**
 * My time off.
 *
 * @return {JSX.Element} Tab.
 */
function TimeOff() {
	const [ month, setMonth ] = useState( () =>
		format.siteToday().slice( 0, 7 )
	);
	const year = Number( month.slice( 0, 4 ) );
	const mine = useMyTimeOff( year );
	const cancel = useMyTimeOffMutation();
	const [ adding, setAdding ] = useState( false );
	const [ cancelling, setCancelling ] = useState( null );

	usePageActions(
		mine.data ? (
			<Button onClick={ () => setAdding( true ) }>
				<Plus className="h-4 w-4" aria-hidden="true" />
				<span className="sr-only sm:not-sr-only">
					{ __( 'Request time off', 'radius-hotel-booking-pro' ) }
				</span>
			</Button>
		) : null,
		// Shown once the employee file is known to exist.
		[ Boolean( mine.data ) ]
	);

	if ( mine.error ) {
		return <NoFile error={ mine.error } />;
	}
	if ( ! mine.data ) {
		return <Skeleton className="h-64 w-full rounded-xl" />;
	}
	const { requests, balance, types, today } = mine.data;
	const dates = daysOf( month );
	// Pending and approved requests only: declined and cancelled leave the day free.
	const live = requests.filter( ( r ) =>
		[ 'pending', 'approved' ].includes( r.status )
	);
	const cells = dates.map(
		( d ) =>
			live.find( ( r ) => r.start_date <= d && r.end_date >= d ) || null
	);

	return (
		<div className="space-y-4">
			<BalancePanel
				balance={ balance }
				types={ types }
				employee={ __( 'Your time off', 'radius-hotel-booking-pro' ) }
			/>
			<Panel>
				<div className="space-y-3">
					<div className="flex items-center justify-between gap-2">
						<Button
							variant="ghost"
							size="sm"
							aria-label={ __(
								'Previous month',
								'radius-hotel-booking-pro'
							) }
							onClick={ () =>
								setMonth( shiftMonth( month, -1 ) )
							}
						>
							<ChevronLeft aria-hidden="true" />
						</Button>
						<span className="font-semibold text-heading">
							{ format.formatDateAs( `${ month }-01`, 'F Y' ) }
						</span>
						<Button
							variant="ghost"
							size="sm"
							aria-label={ __(
								'Next month',
								'radius-hotel-booking-pro'
							) }
							onClick={ () => setMonth( shiftMonth( month, 1 ) ) }
						>
							<ChevronRight aria-hidden="true" />
						</Button>
					</div>
					<CalendarGrid
						dates={ dates }
						today={ today }
						corner={ __( 'Month', 'radius-hotel-booking-pro' ) }
						caption={ __(
							'Your time off this month',
							'radius-hotel-booking-pro'
						) }
						rows={ [
							{
								key: 'me',
								label: __(
									'Time off',
									'radius-hotel-booking-pro'
								),
								cells,
							},
						] }
						renderCell={ ( row, cell ) =>
							cell ? cell.type_label.slice( 0, 1 ) : ''
						}
						cellClassName={ ( row, cell ) =>
							cell ? TONE[ cell.status ] : ''
						}
						cellLabel={ ( row, cell ) =>
							cell
								? sprintf(
										/* translators: 1: type of time off, 2: status. */
										__(
											'%1$s, %2$s',
											'radius-hotel-booking-pro'
										),
										cell.type_label,
										'pending' === cell.status
											? __(
													'awaiting approval',
													'radius-hotel-booking-pro'
											  )
											: __(
													'approved',
													'radius-hotel-booking-pro'
											  )
								  )
								: ''
						}
					/>
				</div>
			</Panel>
			<Panel>
				<DataTable
					columns={ [
						{
							id: 'dates',
							header: __( 'Dates', 'radius-hotel-booking-pro' ),
							mobile: 'title',
							cell: ( r ) => (
								<span className="font-semibold text-heading">
									{ datesText( r ) }
								</span>
							),
						},
						{
							id: 'type',
							header: __( 'Type', 'radius-hotel-booking-pro' ),
							mobile: 'subtitle',
							cell: ( r ) =>
								`${ r.type_label } · ${ daysText( r.days ) }`,
						},
						{
							id: 'status',
							header: __( 'Status', 'radius-hotel-booking-pro' ),
							cell: ( r ) => (
								<StatusBadge
									domain="request"
									value={ r.status }
								/>
							),
						},
						{
							id: 'note',
							header: __(
								'Reason / note',
								'radius-hotel-booking-pro'
							),
							wrap: true,
							cell: ( r ) =>
								[ r.reason, r.review_note ]
									.filter( Boolean )
									.join( ' — ' ) || '—',
						},
					] }
					rows={ requests }
					total={ requests.length }
					page={ 1 }
					perPage={ Math.max( 1, requests.length ) }
					rowActions={ ( r ) =>
						'pending' === r.status
							? [
									{
										label: __(
											'Cancel request',
											'radius-hotel-booking-pro'
										),
										destructive: true,
										onSelect: () => setCancelling( r ),
									},
							  ]
							: []
					}
					empty={
						<EmptyState
							icon={ CalendarOff }
							title={ sprintf(
								/* translators: %d: year. */
								__(
									'No time off in %d',
									'radius-hotel-booking-pro'
								),
								year
							) }
							className="border-0 py-12"
						/>
					}
				/>
			</Panel>
			{ adding ? (
				<RequestDialog
					types={ types }
					today={ today }
					onClose={ () => setAdding( false ) }
					useSave={ useMyTimeOffMutation }
					useBalance={ useMyBalance }
				/>
			) : null }
			{ cancelling ? (
				<ConfirmDialog
					open
					onOpenChange={ ( next ) => ! next && setCancelling( null ) }
					title={ __(
						'Cancel this request?',
						'radius-hotel-booking-pro'
					) }
					description={ sprintf(
						/* translators: 1: type of time off, 2: dates. */
						__(
							'%1$s, %2$s. You can ask again later.',
							'radius-hotel-booking-pro'
						),
						cancelling.type_label,
						datesText( cancelling )
					) }
					confirmLabel={ __(
						'Cancel request',
						'radius-hotel-booking-pro'
					) }
					destructive
					onConfirm={ async () => {
						try {
							const response = await cancel.mutateAsync( {
								cancel: cancelling.id,
							} );
							toast.success( response.message );
							setCancelling( null );
						} catch ( err ) {
							toastError( err );
						}
					} }
				/>
			) : null }
		</div>
	);
}

const TABS = [
	{
		key: 'payslips',
		label: __( 'Payslips', 'radius-hotel-booking-pro' ),
		Component: Payslips,
	},
	{
		key: 'time-off',
		label: __( 'Time off', 'radius-hotel-booking-pro' ),
		Component: TimeOff,
	},
];

/**
 * @return {JSX.Element} Screen.
 */
export default function MyPayrollScreen() {
	const [ params, setParams ] = useSearchParams();
	const current =
		TABS.find( ( tab ) => tab.key === params.get( 'tab' ) ) || TABS[ 0 ];
	const { Component } = current;
	return (
		<div className="space-y-4">
			<FilterTabs
				label={ __( 'My payroll', 'radius-hotel-booking-pro' ) }
				tabs={ TABS.map( ( tab ) => ( {
					value: tab.key,
					label: tab.label,
				} ) ) }
				value={ current.key }
				onChange={ ( value ) =>
					setParams( { tab: value }, { replace: true } )
				}
			/>
			<Suspense
				fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }
			>
				<Component />
			</Suspense>
		</div>
	);
}

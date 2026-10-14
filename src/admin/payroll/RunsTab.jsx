/**
 * Payroll → Pay runs (M15 T5c, 15.1, 15.2): every run, newest first, with
 * its status, and *Run payroll* — a draft for a schedule's next pay period
 * that has no run yet. A row opens the run.
 */
import { Suspense, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Banknote, Play } from 'lucide-react';
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
} from '@rtbp/ui';
import { access, format, toast, toastError, usePageActions } from '@rtbp/lib';

import { usePaySchedules, usePayrollRunAction, usePayrollRuns } from './api';

const PER_PAGE = 20;

/**
 * "1 Sep – 30 Sep 2026".
 *
 * @param {Object} run Run.
 * @return {string} Period.
 */
export const periodText = ( run ) =>
	sprintf(
		/* translators: 1: first day, 2: last day. */
		__( '%1$s – %2$s', 'radius-hotel-booking-pro' ),
		format.formatDate( run.period_start ),
		format.formatDate( run.period_end )
	);

/**
 * Start a run.
 *
 * @param {Object}   props         Props.
 * @param {boolean}  props.open    Open.
 * @param {Function} props.onClose Close.
 * @return {JSX.Element} Dialog.
 */
function RunPayrollDialog( { open, onClose } ) {
	const schedules = usePaySchedules();
	const action = usePayrollRunAction();
	const navigate = useNavigate();
	const [ schedule, setSchedule ] = useState( '' );
	const list = schedules.data?.schedules || [];
	const chosen =
		list.find( ( s ) => String( s.id ) === schedule ) || list[ 0 ];

	const start = async () => {
		try {
			const response = await action.mutateAsync( {
				action: 'create',
				schedule_id: chosen.id,
			} );
			toast.success( response.message );
			const skipped = response.data.run.skipped || [];
			if ( skipped.length ) {
				// Already paid for some of these days on another schedule.
				toast.warning(
					sprintf(
						/* translators: %s: employees with the run that already pays them. */
						__(
							'Left out, already paid for some of these days: %s',
							'radius-hotel-booking-pro'
						),
						skipped.join( ', ' )
					)
				);
			}
			onClose();
			navigate( `/payroll/runs/${ response.data.run.id }` );
		} catch ( err ) {
			toastError( err );
		}
	};

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-w-md">
				<DialogHeader>
					<DialogTitle>
						{ __( 'Run payroll', 'radius-hotel-booking-pro' ) }
					</DialogTitle>
					<DialogDescription>
						{ __(
							'A draft for the next pay period without a pay run, with a line for each active employee on the schedule. Nothing is paid until it is settled.',
							'radius-hotel-booking-pro'
						) }
					</DialogDescription>
				</DialogHeader>
				{ list.length ? (
					<Field
						label={ __(
							'Pay schedule',
							'radius-hotel-booking-pro'
						) }
						description={
							chosen
								? sprintf(
										/* translators: %d: number of employees. */
										_n(
											'%d employee on it (deactivated ones are left out).',
											'%d employees on it (deactivated ones are left out).',
											chosen.employees,
											'radius-hotel-booking-pro'
										),
										chosen.employees
								  )
								: ''
						}
					>
						<Select
							value={ chosen ? String( chosen.id ) : '' }
							onValueChange={ setSchedule }
						>
							<SelectTrigger>
								<SelectValue />
							</SelectTrigger>
							<SelectContent className="rtbp-root">
								{ list.map( ( s ) => (
									<SelectItem
										key={ s.id }
										value={ String( s.id ) }
									>
										{ s.name }
									</SelectItem>
								) ) }
							</SelectContent>
						</Select>
					</Field>
				) : (
					<p className="m-0 text-sm text-muted-foreground">
						{ __(
							'Add a pay schedule first, then put employees on it from their Pay tab.',
							'radius-hotel-booking-pro'
						) }
					</p>
				) }
				<DialogFooter>
					<Button type="button" variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
					</Button>
					<Button
						onClick={ start }
						disabled={ ! chosen || action.isPending }
					>
						{ __( 'Create the draft', 'radius-hotel-booking-pro' ) }
					</Button>
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
}

/**
 * @return {JSX.Element} Tab.
 */
export default function RunsTab() {
	const [ params, setParams ] = useSearchParams();
	const status = params.get( 'status' ) || '';
	const page = Number( params.get( 'page' ) ) || 1;
	const runs = usePayrollRuns( { status, page } );
	const navigate = useNavigate();
	const canRun = access?.useAccess
		? access.useAccess( 'payroll.run' ) !== 'locked'
		: false;
	const [ starting, setStarting ] = useState( false );

	usePageActions(
		canRun ? (
			<Button onClick={ () => setStarting( true ) }>
				<Play className="h-4 w-4" aria-hidden="true" />
				<span className="sr-only sm:not-sr-only">
					{ __( 'Run payroll', 'radius-hotel-booking-pro' ) }
				</span>
			</Button>
		) : null
	);

	const setView = ( next ) =>
		setParams(
			( current ) => {
				const out = new URLSearchParams( current );
				Object.entries( next ).forEach( ( [ k, v ] ) =>
					v ? out.set( k, v ) : out.delete( k )
				);
				return out;
			},
			{ replace: true }
		);

	const columns = [
		{
			id: 'period',
			header: __( 'Pay period', 'radius-hotel-booking-pro' ),
			mobile: 'title',
			cell: ( run ) => (
				<span className="inline-flex flex-wrap items-baseline gap-x-2">
					<span className="font-semibold text-heading">
						{ periodText( run ) }
					</span>
					<span className="text-xs text-muted-foreground">
						{ run.schedule_name }
					</span>
				</span>
			),
		},
		{
			id: 'pay',
			header: __( 'Pay day', 'radius-hotel-booking-pro' ),
			mobile: 'subtitle',
			cell: ( run ) => format.formatDate( run.pay_date ),
		},
		{
			id: 'status',
			header: __( 'Status', 'radius-hotel-booking-pro' ),
			cell: ( run ) => (
				<StatusBadge domain="document" value={ run.status } />
			),
		},
		{
			id: 'employees',
			header: __( 'Employees', 'radius-hotel-booking-pro' ),
			cell: ( run ) => String( run.employees ),
		},
		{
			id: 'gross',
			header: __( 'Gross', 'radius-hotel-booking-pro' ),
			cell: ( run ) => format.formatMoney( run.gross_total ),
		},
		{
			id: 'net',
			header: __( 'Net pay', 'radius-hotel-booking-pro' ),
			cell: ( run ) => format.formatMoney( run.net_total ),
		},
	];

	return (
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<Panel>
				<div className="space-y-3">
					<FilterTabs
						label={ __( 'Status', 'radius-hotel-booking-pro' ) }
						tabs={ [
							{
								value: '',
								label: __( 'All', 'radius-hotel-booking-pro' ),
							},
							{
								value: 'draft',
								label: __(
									'Draft',
									'radius-hotel-booking-pro'
								),
							},
							{
								value: 'finalized',
								label: __(
									'Finalised',
									'radius-hotel-booking-pro'
								),
							},
							{
								value: 'settled',
								label: __(
									'Settled',
									'radius-hotel-booking-pro'
								),
							},
							{
								value: 'voided',
								label: __(
									'Voided',
									'radius-hotel-booking-pro'
								),
							},
						] }
						value={ status }
						onChange={ ( value ) =>
							setView( { status: value, page: '' } )
						}
					/>
					<DataTable
						columns={ columns }
						rows={ runs.data?.runs || [] }
						total={ runs.data?.total || 0 }
						page={ page }
						perPage={ PER_PAGE }
						onPageChange={ ( next ) =>
							setView( { page: String( next ) } )
						}
						loading={ runs.isPending }
						error={ runs.error }
						onRetry={ () => runs.refetch() }
						onRowClick={ ( run ) =>
							navigate( `/payroll/runs/${ run.id }` )
						}
						empty={
							<EmptyState
								icon={ Banknote }
								title={ __(
									'No pay runs yet',
									'radius-hotel-booking-pro'
								) }
								description={ __(
									'Run payroll for a pay schedule: a draft is made for its next pay period.',
									'radius-hotel-booking-pro'
								) }
								className="border-0 py-12"
							/>
						}
						caption={ __( 'Pay runs', 'radius-hotel-booking-pro' ) }
					/>
				</div>
			</Panel>
			<RunPayrollDialog
				open={ starting }
				onClose={ () => setStarting( false ) }
			/>
		</Suspense>
	);
}

/**
 * What a staff scan opens (R3-T4): the booking at a glance — guest, each
 * room with its status, the balance — and the next step for each room,
 * Check in or Check out, without the full booking screen. Record payment
 * and the full booking are one tap away. The moves are the free plugin's
 * (`booking-lines/{id}/check-in|check-out`), under the same access keys and
 * PIN; the server checks everything again.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, LogIn, LogOut, Phone, Wallet } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	EmptyState,
	Money,
	Panel,
	Skeleton,
	StatusBadge,
} from '@rtbp/ui';
import { access, api, format, toast, toastError } from '@rtbp/lib';

const MOVES = {
	check_in: { path: 'check-in', icon: LogIn },
	check_out: { path: 'check-out', icon: LogOut },
};

/**
 * A move in words.
 *
 * @param {string} action check_in | check_out.
 * @return {string} Label.
 */
const moveLabel = ( action ) =>
	'check_in' === action
		? __( 'Check in', 'radius-hotel-booking-pro' )
		: __( 'Check out', 'radius-hotel-booking-pro' );

/**
 * @return {JSX.Element} Screen.
 */
export default function ScanCard() {
	const { id: param } = useParams();
	const id = Number( param ) || 0;
	const navigate = useNavigate();
	const client = useQueryClient();
	const level = ( key ) =>
		access?.useAccess ? access.useAccess( key ) : 'locked';
	const can = {
		check_in: 'locked' !== level( 'bookings.check_in' ),
		check_out: 'locked' !== level( 'bookings.check_out' ),
		pay: 'locked' !== level( 'payments.record' ),
	};
	const query = useQuery( {
		queryKey: [ 'bookings', 'scan', id ],
		queryFn: () =>
			api.get( `bookings/${ id }` ).then( ( r ) => r.data.booking ),
		enabled: id > 0,
	} );
	const [ busy, setBusy ] = useState( 0 );
	// Check out asks first: `{ line }`.
	const [ confirming, setConfirming ] = useState( null );

	const move = async ( line, action ) => {
		setBusy( line.id );
		try {
			const response = await api.post(
				`booking-lines/${ line.id }/${ MOVES[ action ].path }`,
				{}
			);
			toast.success( response.message );
			// This card, the booking screen, the lists and the dashboard counts.
			client.invalidateQueries( { queryKey: [ 'bookings' ] } );
			client.invalidateQueries( { queryKey: [ 'dashboard' ] } );
		} catch ( err ) {
			toastError( err );
		} finally {
			setBusy( 0 );
		}
	};

	const back = (
		<Link
			to="/bookings"
			className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-heading"
		>
			<ArrowLeft className="h-4 w-4" aria-hidden="true" />
			{ __( 'Bookings', 'radius-hotel-booking-pro' ) }
		</Link>
	);

	if ( query.isPending ) {
		return <Skeleton className="h-64 w-full rounded-xl" />;
	}
	const booking = query.data;
	if ( query.error || ! booking ) {
		return (
			<div className="space-y-4">
				{ back }
				<Panel>
					<EmptyState
						title={ __(
							'This booking could not be loaded',
							'radius-hotel-booking-pro'
						) }
						description={ query.error?.message }
						className="border-0"
					/>
				</Panel>
			</div>
		);
	}

	const guest = booking.guest || {};
	const due = Number( booking.balance_due ) || 0;

	return (
		<div className="mx-auto max-w-2xl space-y-4">
			{ back }
			<Panel>
				<div className="space-y-1">
					<div className="flex flex-wrap items-center gap-2">
						<h2 className="m-0 text-xl font-semibold text-heading">
							{ booking.reference }
						</h2>
						<StatusBadge domain="stay" value={ booking.status } />
						<StatusBadge
							domain="payment"
							value={ booking.payment_status }
						/>
					</div>
					<p className="m-0 text-base font-medium text-heading">
						{ guest.name || '—' }
					</p>
					{ guest.phone ? (
						<a
							href={ `tel:${ guest.phone }` }
							className="inline-flex items-center gap-1.5 text-sm text-muted-foreground no-underline hover:text-heading"
						>
							<Phone className="h-4 w-4" aria-hidden="true" />
							{ guest.phone }
						</a>
					) : null }
				</div>
			</Panel>

			<Panel
				title={ __( 'Rooms', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Check each room in or out from here.',
					'radius-hotel-booking-pro'
				) }
			>
				<ul className="m-0 list-none divide-y divide-border p-0">
					{ ( booking.lines || [] ).map( ( line ) => {
						const next = [ 'check_in', 'check_out' ].find(
							( action ) =>
								( line.actions || [] ).includes( action ) &&
								can[ action ]
						);
						const Icon = next ? MOVES[ next ].icon : null;
						return (
							<li
								key={ line.id }
								className="flex flex-wrap items-center justify-between gap-3 py-3"
							>
								<div className="min-w-0 space-y-0.5">
									<div className="flex flex-wrap items-center gap-2">
										<span className="text-base font-semibold text-heading">
											{ sprintf(
												/* translators: %s: room number. */
												__(
													'Room %s',
													'radius-hotel-booking-pro'
												),
												line.room_number
											) }
										</span>
										<StatusBadge
											domain="stay"
											value={ line.status }
										/>
									</div>
									<p className="m-0 text-sm text-muted-foreground">
										{ line.rate_plan_name } ·{ ' ' }
										{ format.formatDateTime( line.start ) }{ ' ' }
										→ { format.formatDateTime( line.end ) }
									</p>
								</div>
								{ next ? (
									<Button
										size="lg"
										onClick={ () =>
											'check_out' === next
												? setConfirming( { line } )
												: move( line, next )
										}
										disabled={ Boolean( busy ) }
									>
										<Icon
											className="h-4 w-4"
											aria-hidden="true"
										/>
										{ moveLabel( next ) }
									</Button>
								) : null }
							</li>
						);
					} ) }
				</ul>
			</Panel>

			<Panel>
				<div className="flex flex-wrap items-center justify-between gap-3">
					<div>
						<p className="m-0 text-sm text-muted-foreground">
							{ __( 'Balance due', 'radius-hotel-booking-pro' ) }
						</p>
						<p className="m-0 text-xl font-semibold text-heading">
							<Money value={ due } />
						</p>
					</div>
					<div className="flex flex-wrap gap-2">
						{ due > 0 && can.pay ? (
							<Button
								onClick={ () =>
									navigate( `/bookings/${ id }?pay=1` )
								}
							>
								<Wallet
									className="h-4 w-4"
									aria-hidden="true"
								/>
								{ __(
									'Record payment',
									'radius-hotel-booking-pro'
								) }
							</Button>
						) : null }
						<Button asChild variant="outline">
							<Link to={ `/bookings/${ id }` }>
								{ __(
									'Open booking',
									'radius-hotel-booking-pro'
								) }
							</Link>
						</Button>
					</div>
				</div>
			</Panel>

			<ConfirmDialog
				open={ Boolean( confirming ) }
				onOpenChange={ ( open ) => ! open && setConfirming( null ) }
				title={
					confirming
						? sprintf(
								/* translators: %s: room number. */
								__(
									'Check out room %s?',
									'radius-hotel-booking-pro'
								),
								confirming.line.room_number
						  )
						: ''
				}
				description={
					due > 0
						? __(
								'Money is still due on this booking.',
								'radius-hotel-booking-pro'
						  )
						: ''
				}
				confirmLabel={ __( 'Check out', 'radius-hotel-booking-pro' ) }
				onConfirm={ () => {
					const { line } = confirming;
					setConfirming( null );
					return move( line, 'check_out' );
				} }
			/>
		</div>
	);
}

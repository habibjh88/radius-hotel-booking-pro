/**
 * Dashboard → Cash register card (R3-T6). The client's paper sheet on the
 * dashboard: Open / Close, and one running total per payment method for the
 * staff member's own register. The full page (history, slips) is one click
 * away at Front desk → Cash register.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link } from 'react-router-dom';
import { ArrowRight, Lock, Printer } from 'lucide-react';
import { Button, Money, Panel, Skeleton } from '@rtbp/ui';
import { format } from '@rtbp/lib';

import { useMyRegister } from './api';
import {
	CloseDialog,
	ClosedSummary,
	OpenForm,
	printSlip,
} from './CashRegisterScreen';

/**
 * The open register's totals, one tile per method, then the drawer's cash.
 *
 * @param {Object} props         Props.
 * @param {Object} props.session Open session.
 * @return {JSX.Element} Tiles.
 */
function Totals( { session } ) {
	const live = session.live || { methods: [], expected_cash: 0 };
	return (
		<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
			{ live.methods.map( ( method ) => (
				<div
					key={ method.key }
					className="rounded-lg border border-border px-4 py-3"
				>
					<span className="block text-sm font-medium text-muted-foreground">
						{ method.label }
					</span>
					<span className="block text-lg font-semibold tabular-nums text-heading">
						<Money value={ method.amount } />
					</span>
					<span className="block text-xs text-muted-foreground">
						{ sprintf(
							/* translators: %d: number of payments. */
							_n(
								'%d payment',
								'%d payments',
								method.count,
								'radius-hotel-booking-pro'
							),
							method.count
						) }
					</span>
				</div>
			) ) }
			<div className="rounded-lg bg-muted px-4 py-3">
				<span className="block text-sm font-medium text-muted-foreground">
					{ __(
						'Cash expected in the drawer',
						'radius-hotel-booking-pro'
					) }
				</span>
				<span className="block text-lg font-semibold tabular-nums text-heading">
					<Money value={ live.expected_cash } />
				</span>
				<span className="block text-xs text-muted-foreground">
					{ sprintf(
						/* translators: %s: opening cash amount. */
						__(
							'Opening cash %s included',
							'radius-hotel-booking-pro'
						),
						format.formatMoney(
							Number( session.opening_float ) || 0
						)
					) }
				</span>
			</div>
		</div>
	);
}

/**
 * @return {JSX.Element} Card.
 */
export default function CashRegisterCard() {
	const query = useMyRegister();
	const [ closing, setClosing ] = useState( false );
	// The session just closed here: its summary and slip until dismissed.
	const [ justClosed, setJustClosed ] = useState( null );
	const session = query.data?.session;

	const pageLink = (
		<Button asChild variant="ghost" size="sm">
			<Link to="/cash-register">
				{ __( 'Details', 'radius-hotel-booking-pro' ) }
				<ArrowRight className="h-4 w-4" aria-hidden="true" />
			</Link>
		</Button>
	);

	if ( query.isPending ) {
		return <Skeleton className="h-36 w-full rounded-xl" />;
	}
	if ( query.error ) {
		return (
			<Panel title={ __( 'Cash register', 'radius-hotel-booking-pro' ) }>
				<p className="m-0 text-sm text-destructive">
					{ query.error.message }
				</p>
			</Panel>
		);
	}

	let description = __(
		'Closed. Open it at the start of your shift.',
		'radius-hotel-booking-pro'
	);
	if ( session ) {
		description = sprintf(
			/* translators: %s: date and time. */
			__( 'Open since %s', 'radius-hotel-booking-pro' ),
			format.formatDateTime( session.opened_at )
		);
	}

	return (
		<Panel
			title={ __( 'Cash register', 'radius-hotel-booking-pro' ) }
			description={ description }
			actions={
				<div className="flex items-center gap-2">
					{ pageLink }
					{ session ? (
						<Button onClick={ () => setClosing( true ) }>
							<Lock className="h-4 w-4" aria-hidden="true" />
							{ __( 'Close', 'radius-hotel-booking-pro' ) }
						</Button>
					) : null }
				</div>
			}
		>
			<div className="space-y-4">
				{ justClosed ? (
					<div className="space-y-3 rounded-lg border border-border p-4">
						<div className="flex flex-wrap items-center justify-between gap-2">
							<span className="text-sm font-semibold text-heading">
								{ sprintf(
									/* translators: 1: opened at, 2: closed at. */
									__(
										'Shift closed: %1$s → %2$s',
										'radius-hotel-booking-pro'
									),
									format.formatDateTime(
										justClosed.opened_at
									),
									format.formatDateTime(
										justClosed.closed_at
									)
								) }
							</span>
							<span className="flex gap-2">
								<Button
									size="sm"
									variant="outline"
									onClick={ () => printSlip( justClosed ) }
								>
									<Printer
										className="h-4 w-4"
										aria-hidden="true"
									/>
									{ __(
										'Print',
										'radius-hotel-booking-pro'
									) }
								</Button>
								<Button
									size="sm"
									variant="ghost"
									onClick={ () => setJustClosed( null ) }
								>
									{ __(
										'Dismiss',
										'radius-hotel-booking-pro'
									) }
								</Button>
							</span>
						</div>
						<ClosedSummary session={ justClosed } />
					</div>
				) : null }

				{ session ? (
					<Totals session={ session } />
				) : (
					<OpenForm onOpened={ () => setJustClosed( null ) } />
				) }
			</div>

			<CloseDialog
				open={ closing }
				onClose={ () => setClosing( false ) }
				onClosed={ ( closed ) => {
					setClosing( false );
					setJustClosed( closed );
				} }
			/>
		</Panel>
	);
}

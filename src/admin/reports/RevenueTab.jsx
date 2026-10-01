/**
 * Revenue breakdown (M10 T6b, 10.16–10.17): the period's revenue by room
 * type, by rate plan and by the staff member who took the booking. Each list
 * adds up to the Sales tab's *Sales with tax included* for the same period and
 * date mode. A tab of the free Reports screen (`rtbp.reports.tabs`).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { AlertCircle, Banknote, BedDouble } from 'lucide-react';
import { Button, Money, Panel, Skeleton, StatCard } from '@rtbp/ui';

import { useProReport } from './api';

/**
 * One breakdown: a row per group, largest first, with its share as a bar.
 *
 * @param {Object}  props         Props.
 * @param {string}  props.title   Panel title.
 * @param {Array}   props.rows    `[ { key, label, bookings, rooms, revenue, share } ]`.
 * @param {boolean} props.loading Loading.
 * @return {JSX.Element} Panel.
 */
function Breakdown( { title, rows, loading } ) {
	return (
		<Panel title={ title }>
			{ loading ? (
				<Skeleton className="h-24 w-full" />
			) : rows.length ? (
				<ul className="m-0 list-none space-y-4 p-0">
					{ rows.map( ( row ) => (
						<li key={ row.key } className="space-y-1.5">
							<div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-sm">
								<span className="min-w-0 font-medium text-heading">
									{ row.label }
									<span className="ml-2 font-normal text-muted-foreground">
										{ [
											sprintf(
												/* translators: %d: number of bookings. */
												_n(
													'%d booking',
													'%d bookings',
													row.bookings,
													'radius-hotel-booking-pro'
												),
												row.bookings
											),
											sprintf(
												/* translators: %d: number of booked rooms. */
												_n(
													'%d room',
													'%d rooms',
													row.rooms,
													'radius-hotel-booking-pro'
												),
												row.rooms
											),
										].join( ' · ' ) }
									</span>
								</span>
								<span className="flex items-baseline gap-2 tabular-nums">
									<span className="font-semibold text-heading">
										<Money value={ row.revenue } />
									</span>
									<span className="w-12 text-right text-muted-foreground">
										{ sprintf(
											/* translators: %s: a percentage figure. */
											__(
												'%s%%',
												'radius-hotel-booking-pro'
											),
											row.share
										) }
									</span>
								</span>
							</div>
							<div
								className="h-2 overflow-hidden rounded-full bg-muted"
								role="presentation"
							>
								<div
									className="h-full rounded-full bg-primary"
									style={ {
										width: `${ Math.min(
											100,
											row.share
										) }%`,
									} }
								/>
							</div>
						</li>
					) ) }
				</ul>
			) : (
				<p className="m-0 text-sm text-muted-foreground">
					{ __(
						'No sales in this period.',
						'radius-hotel-booking-pro'
					) }
				</p>
			) }
		</Panel>
	);
}

/**
 * @param {Object} props       Props.
 * @param {Object} props.range `{ from, to, mode }`.
 * @return {JSX.Element} Tab.
 */
export default function RevenueTab( { range } ) {
	const report = useProReport( 'revenue', range );
	const data = report.data;
	const loading = report.isPending;

	if ( report.isError && ! data ) {
		return (
			<Panel>
				<div className="flex flex-col items-center gap-3 py-10 text-center">
					<AlertCircle
						className="h-8 w-8 text-destructive"
						aria-hidden="true"
					/>
					<p className="m-0 text-sm text-muted-foreground">
						{ report.error?.message ||
							__(
								'The report could not be loaded.',
								'radius-hotel-booking-pro'
							) }
					</p>
					<Button
						variant="outline"
						onClick={ () => report.refetch() }
					>
						{ __( 'Try again', 'radius-hotel-booking-pro' ) }
					</Button>
				</div>
			</Panel>
		);
	}

	return (
		<div className="space-y-5">
			<div className="grid gap-4 sm:grid-cols-2">
				<StatCard
					icon={ Banknote }
					label={ __(
						'Sales with tax included',
						'radius-hotel-booking-pro'
					) }
					value={ <Money value={ data?.totals?.revenue } /> }
					hint={ __(
						'The same figure as the Sales tab',
						'radius-hotel-booking-pro'
					) }
					loading={ loading }
				/>
				<StatCard
					icon={ BedDouble }
					label={ __( 'Rooms sold', 'radius-hotel-booking-pro' ) }
					value={ data?.totals?.rooms ?? 0 }
					hint={ __(
						'Booked rooms counted in the sales',
						'radius-hotel-booking-pro'
					) }
					loading={ loading }
				/>
			</div>
			<div className="grid gap-5 xl:grid-cols-2">
				<Breakdown
					title={ __( 'By room type', 'radius-hotel-booking-pro' ) }
					rows={ data?.room_types || [] }
					loading={ loading }
				/>
				<Breakdown
					title={ __( 'By rate plan', 'radius-hotel-booking-pro' ) }
					rows={ data?.rate_plans || [] }
					loading={ loading }
				/>
			</div>
			<Breakdown
				title={ __( 'By staff member', 'radius-hotel-booking-pro' ) }
				rows={ data?.staff || [] }
				loading={ loading }
			/>
			<p className="m-0 text-sm text-muted-foreground">
				{ __(
					'Rate plans show the name they had when booked. Staff is who took the booking; website bookings and imported ones are listed apart.',
					'radius-hotel-booking-pro'
				) }
			</p>
		</div>
	);
}

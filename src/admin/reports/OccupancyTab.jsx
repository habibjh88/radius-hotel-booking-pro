/**
 * Occupancy (M10 T6a, 10.15): the share of sellable room-hours a stay
 * occupied, per day and for the period, the most rooms occupied at once, and
 * each room type's rate. A tab of the free Reports screen (`rtbp.reports.tabs`).
 */
import { Suspense } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { AlertCircle, BedDouble, Gauge, Layers, Percent } from 'lucide-react';
import {
	Button,
	EmptyState,
	Panel,
	ReportChart,
	Skeleton,
	StatCard,
} from '@rtbp/ui';
import { format } from '@rtbp/lib';

import { useProReport } from './api';

/**
 * A percentage with one decimal.
 *
 * @param {number} value Percent.
 * @return {string} Text.
 */
const pct = ( value ) =>
	sprintf(
		/* translators: %s: a percentage figure, e.g. 72.5. */
		__( '%s%%', 'radius-hotel-booking-pro' ),
		Number( value || 0 ).toLocaleString( undefined, {
			maximumFractionDigits: 1,
		} )
	);

/**
 * @param {Object} props       Props.
 * @param {Object} props.range `{ from, to, mode }`.
 * @return {JSX.Element} Tab.
 */
export default function OccupancyTab( { range } ) {
	const report = useProReport( 'occupancy', {
		from: range.from,
		to: range.to,
	} );
	const data = report.data;
	const totals = data?.totals || {};
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

	const chart = data?.chart;
	const keyLabel = ( key ) =>
		'month' === chart?.mode
			? format.formatDateAs( `${ key }-01`, 'M Y' )
			: format.formatDateAs( key, 'j M' );

	return (
		<div className="space-y-5">
			<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
				<StatCard
					icon={ Percent }
					label={ __( 'Occupancy', 'radius-hotel-booking-pro' ) }
					value={ pct( totals.occupancy ) }
					hint={ __(
						'Occupied room-hours of the sellable ones',
						'radius-hotel-booking-pro'
					) }
					loading={ loading }
				/>
				<StatCard
					icon={ Gauge }
					label={ __(
						'Most rooms at once',
						'radius-hotel-booking-pro'
					) }
					value={ totals.peak ?? 0 }
					hint={ sprintf(
						/* translators: %d: number of rooms. */
						_n(
							'of %d sellable room',
							'of %d sellable rooms',
							totals.rooms || 0,
							'radius-hotel-booking-pro'
						),
						totals.rooms || 0
					) }
					loading={ loading }
				/>
				<StatCard
					icon={ BedDouble }
					label={ __(
						'Rooms occupied on average',
						'radius-hotel-booking-pro'
					) }
					value={ Number( totals.average_rooms || 0 ).toLocaleString(
						undefined,
						{ maximumFractionDigits: 1 }
					) }
					hint={ __(
						'At any moment of the period',
						'radius-hotel-booking-pro'
					) }
					loading={ loading }
				/>
				<StatCard
					icon={ Layers }
					label={ __(
						'Occupied room-hours',
						'radius-hotel-booking-pro'
					) }
					value={ Math.round(
						totals.occupied_hours || 0
					).toLocaleString() }
					hint={ sprintf(
						/* translators: %s: number of hours. */
						__( 'of %s sellable', 'radius-hotel-booking-pro' ),
						Math.round(
							totals.sellable_hours || 0
						).toLocaleString()
					) }
					loading={ loading }
				/>
			</div>

			<Panel
				title={ __( 'Occupancy rate', 'radius-hotel-booking-pro' ) }
				description={
					'month' === chart?.mode
						? __( 'Per month', 'radius-hotel-booking-pro' )
						: __( 'Per day', 'radius-hotel-booking-pro' )
				}
			>
				{ loading ? (
					<Skeleton className="h-[280px] w-full" />
				) : totals.rooms ? (
					<Suspense
						fallback={ <Skeleton className="h-[280px] w-full" /> }
					>
						<ReportChart
							points={ chart.points }
							series={ chart.series }
							formatKey={ keyLabel }
							formatValue={ pct }
							label={ __(
								'Occupancy rate',
								'radius-hotel-booking-pro'
							) }
						/>
					</Suspense>
				) : (
					<EmptyState
						icon={ BedDouble }
						title={ __(
							'No room to sell',
							'radius-hotel-booking-pro'
						) }
						description={ __(
							'Occupancy needs rooms in the available state.',
							'radius-hotel-booking-pro'
						) }
					/>
				) }
			</Panel>

			<Panel title={ __( 'By room type', 'radius-hotel-booking-pro' ) }>
				{ loading ? (
					<Skeleton className="h-24 w-full" />
				) : (
					<ul className="m-0 list-none space-y-3 p-0">
						{ ( data?.room_types || [] ).map( ( type ) => (
							<li key={ type.name } className="space-y-1.5">
								<div className="flex items-baseline justify-between gap-3 text-sm">
									<span className="font-medium text-heading">
										{ type.name }
										<span className="ml-2 font-normal text-muted-foreground">
											{ sprintf(
												/* translators: %d: number of rooms. */
												_n(
													'%d room',
													'%d rooms',
													type.rooms,
													'radius-hotel-booking-pro'
												),
												type.rooms
											) }
										</span>
									</span>
									<span className="font-semibold tabular-nums text-heading">
										{ pct( type.occupancy ) }
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
												type.occupancy
											) }%`,
										} }
									/>
								</div>
							</li>
						) ) }
					</ul>
				) }
			</Panel>

			<p className="m-0 text-sm text-muted-foreground">
				{ __(
					'Occupied = a confirmed, in-house or departed stay in the room. Sellable = rooms in the available state today, less blocked hours. Rooms are counted as they are now, also for past periods.',
					'radius-hotel-booking-pro'
				) }
			</p>
		</div>
	);
}

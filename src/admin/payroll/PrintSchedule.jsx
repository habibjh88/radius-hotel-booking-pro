/**
 * Print the work schedule (R2-T9), on Payroll → Work shifts: every active
 * employee against the days of a week or a month, with their shift hours,
 * days off and approved time off. Opens the print view, or saves a PDF when
 * Pro's PDF format is on.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { FileDown, Printer } from 'lucide-react';
import { Button, Field, Panel, SegmentedControl } from '@rtbp/ui';
import { format, toastError } from '@rtbp/lib';

import { DateField } from '../pickers';
import { openFile } from './payslip';

/**
 * `Y-m-d` of a local date.
 *
 * @param {Date} date Date.
 * @return {string} Day.
 */
const ymd = ( date ) =>
	[
		date.getFullYear(),
		String( date.getMonth() + 1 ).padStart( 2, '0' ),
		String( date.getDate() ).padStart( 2, '0' ),
	].join( '-' );

/**
 * The week (Monday–Sunday) or month around a day.
 *
 * @param {string} span `week` or `month`.
 * @param {string} day  `Y-m-d`.
 * @return {{from: string, to: string}} Period.
 */
const periodOf = ( span, day ) => {
	const [ y, m, d ] = day.split( '-' ).map( Number );
	if ( 'month' === span ) {
		return {
			from: ymd( new Date( y, m - 1, 1 ) ),
			to: ymd( new Date( y, m, 0 ) ),
		};
	}
	const date = new Date( y, m - 1, d );
	const monday = new Date( y, m - 1, d - ( ( date.getDay() + 6 ) % 7 ) );
	return {
		from: ymd( monday ),
		to: ymd(
			new Date(
				monday.getFullYear(),
				monday.getMonth(),
				monday.getDate() + 6
			)
		),
	};
};

/**
 * @param {Object}  props        Props.
 * @param {boolean} props.hasPdf Pro's PDF format is available.
 * @return {JSX.Element} Panel.
 */
export default function PrintSchedule( { hasPdf } ) {
	const today = format.siteToday ? format.siteToday() : ymd( new Date() );
	const [ span, setSpan ] = useState( 'week' );
	const [ period, setPeriod ] = useState( () => periodOf( 'week', today ) );
	const [ busy, setBusy ] = useState( false );

	const open = ( kind ) => {
		setBusy( true );
		openFile( 'payroll/work-schedule', { ...period, format: kind }, kind )
			.catch( toastError )
			.finally( () => setBusy( false ) );
	};

	return (
		<Panel>
			<div className="space-y-4">
				<div>
					<h3 className="m-0 text-base font-semibold text-heading">
						{ __(
							'Print the work schedule',
							'radius-hotel-booking-pro'
						) }
					</h3>
					<p className="m-0 text-sm text-muted-foreground">
						{ __(
							'Every active employee with their shift hours, days off and approved time off, day by day. An employee shows a work shift once one is chosen on their Pay tab.',
							'radius-hotel-booking-pro'
						) }
					</p>
				</div>
				<div className="flex flex-wrap items-end gap-4">
					<Field label={ __( 'Show', 'radius-hotel-booking-pro' ) }>
						<SegmentedControl
							label={ __( 'Show', 'radius-hotel-booking-pro' ) }
							value={ span }
							onChange={ ( next ) => {
								setSpan( next );
								setPeriod( periodOf( next, period.from ) );
							} }
							options={ [
								{
									value: 'week',
									label: __(
										'A week',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'month',
									label: __(
										'A month',
										'radius-hotel-booking-pro'
									),
								},
							] }
						/>
					</Field>
					<Field
						label={
							'month' === span
								? __(
										'Any day of the month',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Any day of the week',
										'radius-hotel-booking-pro'
								  )
						}
					>
						<DateField
							value={ period.from }
							onChange={ ( day ) =>
								day && setPeriod( periodOf( span, day ) )
							}
						/>
					</Field>
					<div className="flex gap-2">
						<Button
							type="button"
							disabled={ busy }
							onClick={ () => open( 'html' ) }
						>
							<Printer className="h-4 w-4" aria-hidden="true" />
							{ __( 'Print', 'radius-hotel-booking-pro' ) }
						</Button>
						{ hasPdf ? (
							<Button
								type="button"
								variant="outline"
								disabled={ busy }
								onClick={ () => open( 'pdf' ) }
							>
								<FileDown
									className="h-4 w-4"
									aria-hidden="true"
								/>
								{ __( 'PDF', 'radius-hotel-booking-pro' ) }
							</Button>
						) : null }
					</div>
				</div>
				<p className="m-0 text-xs text-muted-foreground">
					{ format.formatDate
						? `${ format.formatDate(
								period.from
						  ) } – ${ format.formatDate( period.to ) }`
						: `${ period.from } – ${ period.to }` }
				</p>
			</div>
		</Panel>
	);
}

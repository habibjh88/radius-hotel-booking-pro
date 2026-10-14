/**
 * Payroll reports on the free Reports screen (M15 T7b, 15.16, 15.17), as
 * tabs through `rtbp.reports.tabs`: *Payroll* (totals, by month, by
 * employee) and *Statutory contributions* (by contribution, by month), over
 * the settled pay runs whose pay day falls in the chosen period. The screen's
 * period picker and export button come with it.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { Banknote, Landmark, ReceiptText, Users } from 'lucide-react';
import { Button, DataTable, Money, Panel, StatCard } from '@rtbp/ui';
import { api, format } from '@rtbp/lib';

/**
 * A report of the free `GET reports/{name}` endpoint (the Reports screen's cache).
 *
 * @param {string} name  Report name.
 * @param {Object} range `{ from, to }`.
 * @return {Object} Query.
 */
const useReport = ( name, range ) =>
	useQuery( {
		queryKey: [ 'reports', name, { from: range.from, to: range.to } ],
		queryFn: () =>
			api
				.get( `reports/${ name }`, { from: range.from, to: range.to } )
				.then( ( r ) => r.data ),
		placeholderData: ( previous ) => previous,
		staleTime: 60 * 1000,
	} );

/**
 * "October 2026" from "2026-10".
 *
 * @param {string} ym Year-month.
 * @return {string} Label.
 */
const monthLabel = ( ym ) => format.formatDateAs( `${ ym }-01`, 'F Y' );

const moneyCell = ( key ) => ( row ) => <Money value={ row[ key ] } />;

/**
 * Error with retry.
 *
 * @param {Object} props        Props.
 * @param {Object} props.report Query.
 * @return {JSX.Element} Panel.
 */
function Failed( { report } ) {
	return (
		<Panel>
			<div className="flex flex-col items-center gap-3 py-10 text-center">
				<p className="m-0 text-sm text-muted-foreground">
					{ report.error?.message ||
						__(
							'The report could not be loaded.',
							'radius-hotel-booking-pro'
						) }
				</p>
				<Button variant="outline" onClick={ () => report.refetch() }>
					{ __( 'Try again', 'radius-hotel-booking-pro' ) }
				</Button>
			</div>
		</Panel>
	);
}

/**
 * @param {Object} props       Props.
 * @param {Object} props.range `{ from, to }`.
 * @return {JSX.Element} Tab.
 */
export function PayrollReportTab( { range } ) {
	const report = useReport( 'payroll', range );
	const data = report.data;
	const loading = report.isPending;
	if ( report.isError && ! data ) {
		return <Failed report={ report } />;
	}
	const figures = [
		{
			id: 'gross',
			header: __( 'Gross pay', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'gross' ),
		},
		{
			id: 'employee_tax',
			header: __(
				'Employee taxes and contributions',
				'radius-hotel-booking-pro'
			),
			cell: moneyCell( 'employee_tax' ),
		},
		{
			id: 'deductions',
			header: __( 'Deductions', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'deductions' ),
		},
		{
			id: 'garnishments',
			header: __( 'Garnishments', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'garnishments' ),
		},
		{
			id: 'custom',
			header: __( 'One-off items', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'custom' ),
		},
		{
			id: 'net',
			header: __( 'Net pay', 'radius-hotel-booking-pro' ),
			mobile: 'subtitle',
			cell: moneyCell( 'net' ),
		},
		{
			id: 'employer_tax',
			header: __( 'Hotel contributions', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'employer_tax' ),
		},
		{
			id: 'employer_cost',
			header: __( 'Cost to the hotel', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'employer_cost' ),
		},
	];
	return (
		<div className="space-y-5">
			<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
				<StatCard
					icon={ Banknote }
					label={ __( 'Gross pay', 'radius-hotel-booking-pro' ) }
					value={ <Money value={ data?.totals?.gross } /> }
					loading={ loading }
				/>
				<StatCard
					icon={ ReceiptText }
					label={ __( 'Net pay', 'radius-hotel-booking-pro' ) }
					value={ <Money value={ data?.totals?.net } /> }
					loading={ loading }
				/>
				<StatCard
					icon={ Landmark }
					label={ __(
						'Taxes and contributions',
						'radius-hotel-booking-pro'
					) }
					value={
						<Money
							value={
								Number( data?.totals?.employee_tax || 0 ) +
								Number( data?.totals?.employer_tax || 0 )
							}
						/>
					}
					hint={ __(
						'Employees’ and the hotel’s',
						'radius-hotel-booking-pro'
					) }
					loading={ loading }
				/>
				<StatCard
					icon={ Users }
					label={ __( 'Payslips', 'radius-hotel-booking-pro' ) }
					value={ data?.totals?.payslips ?? 0 }
					hint={
						data
							? [
									sprintf(
										/* translators: %d: number of employees. */
										_n(
											'%d employee',
											'%d employees',
											data.totals.employees,
											'radius-hotel-booking-pro'
										),
										data.totals.employees
									),
									sprintf(
										/* translators: %d: number of pay runs. */
										_n(
											'%d pay run',
											'%d pay runs',
											data.totals.runs,
											'radius-hotel-booking-pro'
										),
										data.totals.runs
									),
							  ].join( ' · ' )
							: ''
					}
					loading={ loading }
				/>
			</div>
			<Panel>
				<h3 className="m-0 mb-3 text-base font-semibold text-heading">
					{ __( 'By month', 'radius-hotel-booking-pro' ) }
				</h3>
				<DataTable
					columns={ [
						{
							id: 'month',
							header: __( 'Month', 'radius-hotel-booking-pro' ),
							mobile: 'title',
							cell: ( row ) => monthLabel( row.month ),
						},
						...figures,
					] }
					rows={ data?.months || [] }
					rowKey={ ( row ) => row.month }
					total={ data?.months?.length || 0 }
					loading={ loading }
					caption={ __(
						'Payroll by month',
						'radius-hotel-booking-pro'
					) }
					empty={
						<p className="m-0 py-6 text-center text-sm text-muted-foreground">
							{ __(
								'No settled pay run in this period.',
								'radius-hotel-booking-pro'
							) }
						</p>
					}
				/>
			</Panel>
			<Panel>
				<h3 className="m-0 mb-3 text-base font-semibold text-heading">
					{ __( 'By employee', 'radius-hotel-booking-pro' ) }
				</h3>
				<DataTable
					columns={ [
						{
							id: 'name',
							header: __(
								'Employee',
								'radius-hotel-booking-pro'
							),
							mobile: 'title',
							cell: ( row ) => (
								<span className="font-semibold text-heading">
									{ row.name }
								</span>
							),
						},
						{
							id: 'payslips',
							header: __(
								'Payslips',
								'radius-hotel-booking-pro'
							),
							cell: ( row ) => String( row.payslips ),
						},
						...figures,
					] }
					rows={ data?.employees || [] }
					rowKey={ ( row ) => `${ row.number }|${ row.name }` }
					total={ data?.employees?.length || 0 }
					loading={ loading }
					caption={ __(
						'Payroll by employee',
						'radius-hotel-booking-pro'
					) }
					empty={
						<p className="m-0 py-6 text-center text-sm text-muted-foreground">
							{ __(
								'No settled pay run in this period.',
								'radius-hotel-booking-pro'
							) }
						</p>
					}
				/>
			</Panel>
		</div>
	);
}

/**
 * @param {Object} props       Props.
 * @param {Object} props.range `{ from, to }`.
 * @return {JSX.Element} Tab.
 */
export function StatutoryReportTab( { range } ) {
	const report = useReport( 'payroll-statutory', range );
	const data = report.data;
	const loading = report.isPending;
	if ( report.isError && ! data ) {
		return <Failed report={ report } />;
	}
	const figures = [
		{
			id: 'employee',
			header: __( 'Employees', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'employee' ),
		},
		{
			id: 'employer',
			header: __( 'Hotel', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'employer' ),
		},
		{
			id: 'due',
			header: __( 'Due', 'radius-hotel-booking-pro' ),
			mobile: 'subtitle',
			cell: moneyCell( 'due' ),
		},
		{
			id: 'paid',
			header: __( 'Paid', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'paid' ),
		},
		{
			id: 'balance',
			header: __( 'Left to pay', 'radius-hotel-booking-pro' ),
			cell: moneyCell( 'balance' ),
		},
	];
	return (
		<div className="space-y-5">
			<div className="grid gap-4 sm:grid-cols-3">
				<StatCard
					icon={ Landmark }
					label={ __( 'Due', 'radius-hotel-booking-pro' ) }
					value={ <Money value={ data?.totals?.due } /> }
					loading={ loading }
				/>
				<StatCard
					icon={ ReceiptText }
					label={ __( 'Paid', 'radius-hotel-booking-pro' ) }
					value={ <Money value={ data?.totals?.paid } /> }
					loading={ loading }
				/>
				<StatCard
					icon={ Banknote }
					label={ __( 'Left to pay', 'radius-hotel-booking-pro' ) }
					value={ <Money value={ data?.totals?.balance } /> }
					loading={ loading }
				/>
			</div>
			<Panel>
				<h3 className="m-0 mb-3 text-base font-semibold text-heading">
					{ __( 'By contribution', 'radius-hotel-booking-pro' ) }
				</h3>
				<DataTable
					columns={ [
						{
							id: 'label',
							header: __(
								'Contribution',
								'radius-hotel-booking-pro'
							),
							mobile: 'title',
							cell: ( row ) => (
								<span className="inline-flex flex-wrap items-baseline gap-x-2">
									<span className="font-semibold text-heading">
										{ row.label }
									</span>
									<span className="text-xs text-muted-foreground">
										{ row.authority }
									</span>
								</span>
							),
						},
						...figures,
					] }
					rows={ data?.items || [] }
					rowKey={ ( row ) => `${ row.authority }|${ row.code }` }
					total={ data?.items?.length || 0 }
					loading={ loading }
					caption={ __(
						'Statutory contributions',
						'radius-hotel-booking-pro'
					) }
					empty={
						<p className="m-0 py-6 text-center text-sm text-muted-foreground">
							{ __(
								'No settled pay run in this period.',
								'radius-hotel-booking-pro'
							) }
						</p>
					}
				/>
			</Panel>
			<Panel>
				<h3 className="m-0 mb-3 text-base font-semibold text-heading">
					{ __( 'By month', 'radius-hotel-booking-pro' ) }
				</h3>
				<DataTable
					columns={ [
						{
							id: 'month',
							header: __( 'Month', 'radius-hotel-booking-pro' ),
							mobile: 'title',
							cell: ( row ) => monthLabel( row.month ),
						},
						...figures,
					] }
					rows={ data?.months || [] }
					rowKey={ ( row ) => row.month }
					total={ data?.months?.length || 0 }
					loading={ loading }
					caption={ __(
						'Statutory contributions by month',
						'radius-hotel-booking-pro'
					) }
					empty={
						<p className="m-0 py-6 text-center text-sm text-muted-foreground">
							{ __(
								'No settled pay run in this period.',
								'radius-hotel-booking-pro'
							) }
						</p>
					}
				/>
			</Panel>
		</div>
	);
}

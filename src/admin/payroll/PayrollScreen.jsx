/**
 * Payroll (`#/payroll`, M15): pay schedules, work shifts, pay runs,
 * statutory contributions, reports and time off, one tab each (added task by
 * task). The tab is in the URL: `#/payroll?tab=schedules`.
 */
import { lazy, Suspense } from 'react';
import { __ } from '@wordpress/i18n';
import { useSearchParams } from 'react-router-dom';
import { FilterTabs, Skeleton } from '@rtbp/ui';
import { access } from '@rtbp/lib';

/**
 * The payroll tabs, in display order.
 *
 * @type {Array<{key: string, label: string, Component: Function}>}
 */
export const PAYROLL_TABS = [
	{
		key: 'runs',
		label: __( 'Pay runs', 'radius-hotel-booking-pro' ),
		Component: lazy( () => import( './RunsTab' ) ),
	},
	{
		key: 'statutory',
		label: __( 'Statutory', 'radius-hotel-booking-pro' ),
		Component: lazy( () => import( './StatutoryTab' ) ),
	},
	{
		key: 'time-off',
		label: __( 'Time off', 'radius-hotel-booking-pro' ),
		Component: lazy( () => import( './TimeOffTab' ) ),
	},
	{
		key: 'schedules',
		label: __( 'Pay schedules', 'radius-hotel-booking-pro' ),
		Component: lazy( () => import( './SchedulesTab' ) ),
	},
	{
		key: 'shifts',
		label: __( 'Work shifts', 'radius-hotel-booking-pro' ),
		Component: lazy( () => import( './ShiftsTab' ) ),
	},
];

/**
 * @return {JSX.Element} Screen.
 */
export default function PayrollScreen() {
	const [ params, setParams ] = useSearchParams();
	// Reading payroll needs page.payroll; the route already hides the screen without it.
	access?.useAccess?.( 'page.payroll' );
	const current =
		PAYROLL_TABS.find( ( tab ) => tab.key === params.get( 'tab' ) ) ||
		PAYROLL_TABS[ 0 ];
	const { Component } = current;

	return (
		<div className="space-y-4">
			{ PAYROLL_TABS.length > 1 ? (
				<FilterTabs
					label={ __( 'Payroll', 'radius-hotel-booking-pro' ) }
					tabs={ PAYROLL_TABS.map( ( tab ) => ( {
						value: tab.key,
						label: tab.label,
					} ) ) }
					value={ current.key }
					onChange={ ( value ) =>
						setParams( { tab: value }, { replace: true } )
					}
				/>
			) : null }
			<Suspense
				fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }
			>
				<Component />
			</Suspense>
		</div>
	);
}

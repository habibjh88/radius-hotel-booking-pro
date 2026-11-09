/**
 * Pro admin entry.
 *
 * Loads after the free admin app (its script depends on the free handle), so
 * `window.rtbp` already exists. Everything Pro shows lives inside the free app
 * and is added through the `rtbp.*` filters (ADR-015) — Pro has no screens of
 * its own outside it.
 */
import { createElement, lazy, Suspense } from 'react';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import {
	Calculator,
	BadgePercent,
	Banknote,
	ChartPie,
	CalendarSync,
	History,
	Hourglass,
	KeyRound,
	ShieldHalf,
	UserCog,
	UserRound,
	UsersRound,
	Wallet,
} from 'lucide-react';

import ProFeaturesSection from './ProFeaturesSection';
import ScanButton from './scan/ScanButton';
import BracketsTable from './payroll/BracketsTable';
import { registerPasscode } from './access/passcode';
import { registerTimeline } from './activity/timeline';

const AccessSection = lazy( () => import( './access/AccessSection' ) );

// Pro UI parts an add-on may reuse (e.g. a country's tax scales): `window.rtbpPro.ui`.
window.rtbpPro = window.rtbpPro || {};
window.rtbpPro.ui = { ...( window.rtbpPro.ui || {} ), BracketsTable };

/**
 * Whether a Pro feature is active (booted) on the server.
 *
 * @param {string} key Feature key.
 * @return {boolean} Active.
 */
const featureActive = ( key ) =>
	( window.rtbpPro?.features || [] ).some(
		( feature ) => feature.key === key && feature.state === 'active'
	);

/**
 * Add staff pages to the free plugin's "Staff & access" sidebar section
 * (group `team`, which holds Permissions), at the first anchor found:
 * `[ path, 'before' | 'after' ]`. A free version without that section keeps
 * them at the end of System, as before.
 *
 * @param {Array} routes Routes so far.
 * @param {Array} items  Routes to add (their group is `system`).
 * @param {Array} places Anchors, in order of preference.
 * @return {Array} Routes.
 */
const intoTeam = ( routes, items, places ) => {
	if ( ! routes.some( ( route ) => 'team' === route.group ) ) {
		return [ ...routes, ...items ];
	}
	const moved = items.map( ( route ) =>
		'system' === route.group ? { ...route, group: 'team' } : route
	);
	for ( const [ path, side ] of places ) {
		const at = routes.findIndex( ( route ) => route.path === path );
		if ( at >= 0 ) {
			const index = 'before' === side ? at : at + 1;
			return [
				...routes.slice( 0, index ),
				...moved,
				...routes.slice( index ),
			];
		}
	}
	return [ ...routes, ...moved ];
};

addFilter(
	'rtbp.settings.sections',
	'radius-hotel-booking-pro/features',
	( sections ) => [
		...sections,
		{
			key: 'pro_features',
			label: __( 'Pro features', 'radius-hotel-booking-pro' ),
			// Saved with access.manage (the section has no settings.* key).
			accessKey: 'access.manage',
			render: ( props ) => <ProFeaturesSection { ...props } />,
		},
	]
);

// M07: pricing templates and seasonal rules (T5b); occupancy and booking window (T6b).
if ( featureActive( 'pricing_rules' ) ) {
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/pricing',
		( routes ) => [
			...routes,
			{
				path: '/pricing',
				group: 'hotel',
				label: __( 'Pricing rules', 'radius-hotel-booking-pro' ),
				description: __(
					'Seasonal, occupancy and booking-window prices',
					'radius-hotel-booking-pro'
				),
				icon: BadgePercent,
				capability: 'rtbp_view_dashboard',
				accessKey: 'pricing.manage',
				element: lazy( () => import( './pricing/PricingScreen' ) ),
			},
		]
	);
}

// M08: calendar sync — share each room's calendar and read channel calendars (T6a–T6b).
if ( featureActive( 'ical_sync' ) ) {
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/ical',
		( routes ) => [
			...routes,
			{
				path: '/calendar-sync',
				group: 'overview',
				label: __( 'Calendar sync', 'radius-hotel-booking-pro' ),
				description: __(
					'Share and read room calendars with channels',
					'radius-hotel-booking-pro'
				),
				icon: CalendarSync,
				capability: 'rtbp_view_dashboard',
				accessKey: 'availability.manage',
				element: lazy( () => import( './ical/CalendarSyncScreen' ) ),
			},
		]
	);
}

// M12: staff records.
if ( featureActive( 'staff_records' ) ) {
	// Settings → Staff: the departments and job titles to pick from.
	addFilter(
		'rtbp.settings.sections',
		'radius-hotel-booking-pro/staff-lists',
		( sections ) => [
			...sections,
			{
				key: 'pro_staff',
				label: __( 'Staff', 'radius-hotel-booking-pro' ),
				description: __(
					'Departments and job titles',
					'radius-hotel-booking-pro'
				),
				icon: UsersRound,
				accessKey: 'settings.general',
				Component: lazy( () =>
					import( './staff/StaffSettingsSection' )
				),
				// The forms and filters read the lists from the staff options.
				onSaved: () =>
					window.rtbp?.lib?.queryClient?.invalidateQueries( {
						queryKey: [ 'employees', 'options' ],
					} ),
			},
		]
	);
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/staff',
		( routes ) =>
			intoTeam(
				routes,
				[
					{
						path: '/staff',
						group: 'system',
						label: __( 'Staff', 'radius-hotel-booking-pro' ),
						description: __(
							'Employee files, contracts and documents',
							'radius-hotel-booking-pro'
						),
						icon: UsersRound,
						capability: 'rtbp_view_dashboard',
						accessKey: 'page.staff',
						element: lazy( () =>
							import( './staff/StaffListScreen' )
						),
					},
					{
						path: '/staff/new',
						hidden: true,
						label: __(
							'Add an employee',
							'radius-hotel-booking-pro'
						),
						capability: 'rtbp_view_dashboard',
						accessKey: 'staff.create',
						element: lazy( () =>
							import( './staff/CreateEmployeeScreen' )
						),
					},
					{
						path: '/staff/:id',
						hidden: true,
						label: __( 'Employee', 'radius-hotel-booking-pro' ),
						capability: 'rtbp_view_dashboard',
						accessKey: 'page.staff',
						element: lazy( () =>
							import( './staff/StaffDetailScreen' )
						),
					},
				],
				[ [ '/permissions', 'before' ] ]
			)
	);
}

// M16: staff self-service — My profile, on the account menu.
if ( featureActive( 'staff_self_service' ) ) {
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/self-service',
		( routes ) => [
			...routes,
			{
				path: '/me/profile',
				// In the sidebar too, not only the account menu (R1-T5).
				group: 'me',
				icon: UserRound,
				label: __( 'My profile', 'radius-hotel-booking-pro' ),
				// Any dashboard user, on their own profile (no access key).
				capability: 'rtbp_view_dashboard',
				element: lazy( () => import( './self/ProfileScreen' ) ),
			},
		]
	);
	addFilter(
		'rtbp.user.menu',
		'radius-hotel-booking-pro/self-service',
		( items ) => [
			...( items || [] ),
			{
				key: 'profile',
				label: __( 'My profile', 'radius-hotel-booking-pro' ),
				icon: UserRound,
				to: '/me/profile',
			},
		]
	);
}

// M13: the passcode level (PIN prompt) and Settings → Passcodes.
if ( featureActive( 'advanced_access' ) ) {
	registerPasscode();

	// The free permission matrix gains Passcode, between Open and Locked.
	addFilter(
		'rtbp.access.levels',
		'radius-hotel-booking-pro/access',
		( levels ) => {
			const passcode = {
				value: 'passcode',
				label: __( 'Passcode', 'radius-hotel-booking-pro' ),
			};
			const list = ( levels || [] ).filter(
				( l ) => l.value !== 'passcode'
			);
			const at = list.findIndex( ( l ) => l.value === 'locked' );
			return at < 0
				? [ ...list, passcode ]
				: [ ...list.slice( 0, at ), passcode, ...list.slice( at ) ];
		}
	);

	// Access roles and staff access (T5b).
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/access',
		( routes ) =>
			intoTeam(
				routes,
				[
					{
						path: '/access/roles',
						group: 'system',
						label: __( 'Access roles', 'radius-hotel-booking-pro' ),
						description: __(
							'Named roles such as Receptionist, with their permissions',
							'radius-hotel-booking-pro'
						),
						icon: ShieldHalf,
						capability: 'rtbp_view_dashboard',
						accessKey: 'access.manage',
						element: lazy( () => import( './access/RolesScreen' ) ),
					},
					{
						path: '/access/staff',
						group: 'system',
						label: __( 'Staff access', 'radius-hotel-booking-pro' ),
						description: __(
							'Each person’s access role, PIN and personal overrides',
							'radius-hotel-booking-pro'
						),
						icon: UserCog,
						capability: 'rtbp_view_dashboard',
						accessKey: 'access.manage',
						element: lazy( () => import( './access/StaffScreen' ) ),
					},
				],
				[ [ '/permissions', 'after' ] ]
			)
	);

	addFilter(
		'rtbp.settings.sections',
		'radius-hotel-booking-pro/access',
		( sections ) => [
			...sections,
			{
				key: 'pro_access',
				label: __( 'Passcodes', 'radius-hotel-booking-pro' ),
				// Saved with access.manage (the section has no settings.* key).
				accessKey: 'access.manage',
				description: __(
					'PIN confirmation, the fallback PIN and the locked message',
					'radius-hotel-booking-pro'
				),
				icon: KeyRound,
				Component: AccessSection,
			},
		]
	);
}

// Booking QR codes: *Scan a booking* in the top bar (free 1.0.0 UNRELEASE adds the slot).
if ( featureActive( 'booking_qr' ) ) {
	addFilter(
		'rtbp.topbar.actions',
		'radius-hotel-booking-pro/scan',
		( items ) => [
			...( items || [] ),
			{ key: 'scan', Component: ScanButton },
		]
	);
	// What a scan opens: the booking's quick card, Check in / Check out at once (R3-T4).
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/scan-card',
		( routes ) => [
			...routes,
			{
				path: '/scan/:id',
				hidden: true,
				group: 'frontDesk',
				label: __( 'Scanned booking', 'radius-hotel-booking-pro' ),
				description: __(
					'Check in, check out or take the payment',
					'radius-hotel-booking-pro'
				),
				accessKey: 'page.bookings',
				element: lazy( () => import( './scan/ScanCard' ) ),
			},
		]
	);
}

// R3-T5 – T8: Front desk → Cash register, and its setting.
if ( featureActive( 'cash_register' ) ) {
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/cash-register',
		( routes ) => {
			const route = {
				path: '/cash-register',
				group: 'frontDesk',
				label: __( 'Cash register', 'radius-hotel-booking-pro' ),
				description: __(
					'Open and close your register; what came in per payment method',
					'radius-hotel-booking-pro'
				),
				icon: Calculator,
				accessKey: 'register.use',
				element: lazy( () =>
					import( './cashRegister/CashRegisterScreen' )
				),
			};
			// After Payments in the Front desk group, else at the end.
			const at = routes.findIndex( ( r ) => '/payments' === r.path );
			return at < 0
				? [ ...routes, route ]
				: [
						...routes.slice( 0, at + 1 ),
						route,
						...routes.slice( at + 1 ),
				  ];
		}
	);
	addFilter(
		'rtbp.settings.sections',
		'radius-hotel-booking-pro/cash-register',
		( sections ) => [
			...sections,
			{
				key: 'pro_cash_register',
				label: __( 'Cash register', 'radius-hotel-booking-pro' ),
				description: __(
					'Whether payments need an open register',
					'radius-hotel-booking-pro'
				),
				icon: Calculator,
				accessKey: 'settings.payments',
				Component: lazy( () =>
					import( './cashRegister/CashRegisterSettingsSection' )
				),
			},
		]
	);
}

// M05 T8: Settings → Automatic release (the grace period).
if ( featureActive( 'auto_release' ) ) {
	addFilter(
		'rtbp.settings.sections',
		'radius-hotel-booking-pro/auto-release',
		( sections ) => [
			...sections,
			{
				key: 'pro_auto_release',
				label: __( 'Automatic release', 'radius-hotel-booking-pro' ),
				description: __(
					'Free the room when a booking stays unpaid past its deadline',
					'radius-hotel-booking-pro'
				),
				icon: Hourglass,
				accessKey: 'settings.payments',
				Component: lazy( () =>
					import( './payments/AutoReleaseSettingsSection' )
				),
			},
		]
	);
}

// M14: the activity log screen.
if ( featureActive( 'activity_log' ) ) {
	// The history panel on booking and guest screens (T3b).
	registerTimeline();

	// Settings → Activity log (T4).
	addFilter(
		'rtbp.settings.sections',
		'radius-hotel-booking-pro/activity',
		( sections ) => [
			...sections,
			{
				key: 'pro_activity',
				label: __( 'Activity log', 'radius-hotel-booking-pro' ),
				description: __(
					'Archiving, removing archived entries and page views',
					'radius-hotel-booking-pro'
				),
				icon: History,
				accessKey: 'activity.archive',
				Component: lazy( () =>
					import( './activity/ActivitySettingsSection' )
				),
			},
		]
	);

	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/activity',
		( routes ) => [
			...routes,
			{
				path: '/activity',
				group: 'insights',
				label: __( 'Activity log', 'radius-hotel-booking-pro' ),
				description: __(
					'Who did what, when and from which device',
					'radius-hotel-booking-pro'
				),
				icon: History,
				capability: 'rtbp_view_dashboard',
				accessKey: 'page.activity_log',
				element: lazy( () => import( './activity/ActivityScreen' ) ),
			},
		]
	);
}

/*
 * Developer check of the runtime seams, only where the free plugin shows its
 * own UI kit (WP_DEBUG, administrators).
 */
if ( window.radius_hotel_booking_param?.dev_ui ) {
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/runtime-check',
		( routes ) => [
			...routes,
			{
				path: '/pro/runtime',
				hidden: true,
				label: 'Pro runtime check',
				description:
					'The extension seams, exercised from the Pro bundle (WP_DEBUG only)',
				element: lazy( () => import( './dev/RuntimeCheck' ) ),
			},
		]
	);
}

// M10: occupancy rate (T6a) and revenue breakdowns (T6b) as tabs of the free Reports screen.
if ( featureActive( 'advanced_reports' ) ) {
	addFilter(
		'rtbp.reports.tabs',
		'radius-hotel-booking-pro/reports',
		( tabs ) => [
			...tabs,
			{
				key: 'occupancy',
				label: __( 'Occupancy', 'radius-hotel-booking-pro' ),
				icon: ChartPie,
				accessKey: 'page.reports_rooms',
				// Occupancy is about stays: no arrival / booking-date switch.
				mode: false,
				Component: lazy( () => import( './reports/OccupancyTab' ) ),
			},
			{
				key: 'revenue',
				label: __( 'Revenue breakdown', 'radius-hotel-booking-pro' ),
				icon: Banknote,
				accessKey: 'page.reports_sales',
				Component: lazy( () => import( './reports/RevenueTab' ) ),
			},
		]
	);
}

// M11: scheduled exports (T4), a panel of the free Exports screen (archive and remove dropped in R1).
if ( featureActive( 'advanced_exports' ) ) {
	addFilter(
		'rtbp.exports.panels',
		'radius-hotel-booking-pro/archive',
		( panels ) => [
			...panels,
			{
				key: 'schedules',
				Component: lazy( () => import( './exports/SchedulesPanel' ) ),
			},
		]
	);
}

// M15: payroll and HR — the Payroll screen, Settings → Payroll, the payroll
// reports, the Pay tab of employee files and the staff's own My payroll.
if ( featureActive( 'payroll' ) ) {
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/payroll',
		( routes ) =>
			intoTeam(
				routes,
				[
					{
						path: '/payroll',
						group: 'system',
						label: __( 'Payroll', 'radius-hotel-booking-pro' ),
						description: __(
							'Pay runs, payslips, taxes and contributions, and time off',
							'radius-hotel-booking-pro'
						),
						icon: Banknote,
						accessKey: 'page.payroll',
						element: lazy( () =>
							import( './payroll/PayrollScreen' )
						),
					},
					{
						path: '/payroll/runs/:id',
						hidden: true,
						group: 'system',
						label: __( 'Pay run', 'radius-hotel-booking-pro' ),
						description: __(
							'Lines, totals and what can be done next',
							'radius-hotel-booking-pro'
						),
						accessKey: 'page.payroll',
						element: lazy( () => import( './payroll/RunScreen' ) ),
					},
					{
						path: '/me/payroll',
						// In the sidebar too, named as on the old dashboard (R1-T5).
						group: 'me',
						icon: Wallet,
						label: __( 'My paychecks', 'radius-hotel-booking-pro' ),
						title: __( 'My payroll', 'radius-hotel-booking-pro' ),
						description: __(
							'Your payslips and time off',
							'radius-hotel-booking-pro'
						),
						accessKey: 'page.my_payroll',
						element: lazy( () =>
							import( './payroll/self/MyPayrollScreen' )
						),
					},
				],
				[
					[ '/staff', 'after' ],
					[ '/permissions', 'before' ],
				]
			)
	);

	addFilter(
		'rtbp.settings.sections',
		'radius-hotel-booking-pro/payroll',
		( sections ) => [
			...sections,
			{
				key: 'payroll',
				label: __( 'Payroll', 'radius-hotel-booking-pro' ),
				description: __(
					'Taxes and contributions, and the vacation quota',
					'radius-hotel-booking-pro'
				),
				icon: Banknote,
				accessKey: 'payroll.tax_settings',
				Component: lazy( () =>
					import( './payroll/PayrollSettingsSection' )
				),
			},
		]
	);

	addFilter(
		'rtbp.reports.tabs',
		'radius-hotel-booking-pro/payroll',
		( tabs ) => [
			...tabs,
			{
				key: 'payroll',
				label: __( 'Payroll', 'radius-hotel-booking-pro' ),
				icon: Banknote,
				accessKey: 'page.payroll',
				mode: false,
				Component: lazy( () =>
					import( './payroll/reports' ).then( ( m ) => ( {
						default: m.PayrollReportTab,
					} ) )
				),
			},
			{
				key: 'payroll-statutory',
				label: __(
					'Taxes and contributions',
					'radius-hotel-booking-pro'
				),
				icon: Banknote,
				accessKey: 'page.payroll',
				mode: false,
				Component: lazy( () =>
					import( './payroll/reports' ).then( ( m ) => ( {
						default: m.StatutoryReportTab,
					} ) )
				),
			},
		]
	);

	// The Pay tab of an employee file, after Employment, for whoever may open payroll.
	const PayTab = lazy( () => import( './payroll/PayTab' ) );
	addFilter(
		'rtbp.staff.tabs',
		'radius-hotel-booking-pro/pay',
		( tabs, { employee } ) => {
			const level =
				window.rtbp?.lib?.access?.getAccessLevel?.( 'page.payroll' );
			if ( 'locked' === level ) {
				return tabs;
			}
			const tab = {
				key: 'pay',
				label: __( 'Pay', 'radius-hotel-booking-pro' ),
				render: () =>
					createElement(
						Suspense,
						{ fallback: null },
						createElement( PayTab, { employee } )
					),
			};
			const after = tabs.findIndex( ( t ) => 'employment' === t.key );
			const next = [ ...tabs ];
			next.splice( after < 0 ? next.length : after + 1, 0, tab );
			return next;
		}
	);

	addFilter(
		'rtbp.user.menu',
		'radius-hotel-booking-pro/my-payroll',
		( items ) => {
			const level =
				window.rtbp?.lib?.access?.getAccessLevel?.( 'page.my_payroll' );
			if ( 'locked' === level ) {
				return items;
			}
			return [
				...( items || [] ),
				{
					key: 'my-payroll',
					label: __( 'My payroll', 'radius-hotel-booking-pro' ),
					icon: Wallet,
					to: '/me/payroll',
				},
			];
		}
	);
}

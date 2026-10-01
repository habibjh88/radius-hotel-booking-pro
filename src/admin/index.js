/**
 * Pro admin entry.
 *
 * Loads after the free admin app (its script depends on the free handle), so
 * `window.rtbp` already exists. Everything Pro shows lives inside the free app
 * and is added through the `rtbp.*` filters (ADR-015) — Pro has no screens of
 * its own outside it.
 */
import { lazy } from 'react';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import {
	BadgePercent,
	CalendarSync,
	History,
	Hourglass,
	KeyRound,
	ShieldHalf,
	UserCog,
	UsersRound,
} from 'lucide-react';

import ProFeaturesSection from './ProFeaturesSection';
import { registerPasscode } from './access/passcode';
import { registerTimeline } from './activity/timeline';

const AccessSection = lazy( () => import( './access/AccessSection' ) );

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
	addFilter(
		'rtbp.admin.routes',
		'radius-hotel-booking-pro/staff',
		( routes ) => [
			...routes,
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
				element: lazy( () => import( './staff/StaffListScreen' ) ),
			},
			{
				path: '/staff/new',
				hidden: true,
				label: __( 'Add an employee', 'radius-hotel-booking-pro' ),
				capability: 'rtbp_view_dashboard',
				accessKey: 'staff.create',
				element: lazy( () => import( './staff/CreateEmployeeScreen' ) ),
			},
			{
				path: '/staff/:id',
				hidden: true,
				label: __( 'Employee', 'radius-hotel-booking-pro' ),
				capability: 'rtbp_view_dashboard',
				accessKey: 'page.staff',
				element: lazy( () => import( './staff/StaffDetailScreen' ) ),
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
		( routes ) => [
			...routes,
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
		]
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

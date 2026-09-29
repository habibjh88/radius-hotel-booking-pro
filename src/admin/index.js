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
import { KeyRound, ShieldHalf, UserCog } from 'lucide-react';

import ProFeaturesSection from './ProFeaturesSection';
import { registerPasscode } from './access/passcode';

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
			render: ( props ) => <ProFeaturesSection { ...props } />,
		},
	]
);

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
			const list = ( levels || [] ).filter( ( l ) => l.value !== 'passcode' );
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

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

import ProFeaturesSection from './ProFeaturesSection';

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

/**
 * Adds the activity timeline to record screens (M14 T3b) through the free
 * panel filters (ADR-015):
 *
 *   applyFilters( 'rtbp.booking.panels', panels, { booking } )
 *   applyFilters( 'rtbp.guest.panels', panels, { guest } )
 *
 * Each panel is `{ key, label, order, render() }`; the host screen renders
 * them sorted by `order`. The free screens add these filters in M03 / M09.
 */
import { createElement, lazy, Suspense } from 'react';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

const ActivityTimeline = lazy( () => import( './ActivityTimeline' ) );

/**
 * A panel that shows one record's history.
 *
 * @param {string} type Subject type.
 * @param {*}      id   Subject id.
 * @return {Object} Panel.
 */
const panel = ( type, id ) => ( {
	key: 'activity',
	label: __( 'History', 'radius-hotel-booking-pro' ),
	order: 90,
	render: () =>
		createElement(
			Suspense,
			{ fallback: null },
			createElement( ActivityTimeline, { type, id } )
		),
} );

/**
 * Register on the booking and guest panel filters.
 *
 * @return {void}
 */
export function registerTimeline() {
	addFilter( 'rtbp.booking.panels', 'radius-hotel-booking-pro/activity', ( panels, context ) =>
		context?.booking?.id
			? [ ...( panels || [] ), panel( 'booking', context.booking.id ) ]
			: panels
	);
	addFilter( 'rtbp.guest.panels', 'radius-hotel-booking-pro/activity', ( panels, context ) =>
		context?.guest?.id
			? [ ...( panels || [] ), panel( 'guest', context.guest.id ) ]
			: panels
	);
}

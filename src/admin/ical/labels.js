/**
 * Words for the calendar sync screen.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * "Every 15 minutes", "Every hour", "Every 3 hours", "Once a day".
 *
 * @param {number} minutes Interval.
 * @return {string} Label.
 */
export function intervalLabel( minutes ) {
	if ( minutes >= 1440 ) {
		return __( 'Once a day', 'radius-hotel-booking-pro' );
	}
	if ( minutes >= 60 ) {
		const hours = minutes / 60;
		return 1 === hours
			? __( 'Every hour', 'radius-hotel-booking-pro' )
			: sprintf(
					/* translators: %d: hours. */
					_n(
						'Every %d hour',
						'Every %d hours',
						hours,
						'radius-hotel-booking-pro'
					),
					hours
			  );
	}
	return sprintf(
		/* translators: %d: minutes. */
		_n(
			'Every %d minute',
			'Every %d minutes',
			minutes,
			'radius-hotel-booking-pro'
		),
		minutes
	);
}

/**
 * Level and source labels shared by the access screens.
 */
import { __ } from '@wordpress/i18n';

/**
 * The three levels.
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
export const levelOptions = () => [
	{ value: 'open', label: __( 'Open', 'radius-hotel-booking-pro' ) },
	{
		value: 'passcode',
		label: __( 'Passcode', 'radius-hotel-booking-pro' ),
	},
	{ value: 'locked', label: __( 'Locked', 'radius-hotel-booking-pro' ) },
];

/**
 * The levels plus "not set" (''), labelled for its context.
 *
 * @param {string} unsetLabel E.g. "Default" or "Inherit".
 * @return {Array<{value: string, label: string}>} Options.
 */
export const withUnset = ( unsetLabel ) => [
	{ value: '', label: unsetLabel },
	...levelOptions(),
];

/**
 * @param {string} level Level.
 * @return {string} Label.
 */
export const levelLabel = ( level ) =>
	levelOptions().find( ( option ) => option.value === level )?.label ||
	level;

/**
 * Where an effective level comes from (`GET access/users/{id}`).
 *
 * @param {string} source Source key.
 * @return {string} Label.
 */
export const sourceLabel = ( source ) =>
	( {
		override: __( 'Personal override', 'radius-hotel-booking-pro' ),
		role: __( 'Access role', 'radius-hotel-booking-pro' ),
		wp_role: __( 'Staff role default', 'radius-hotel-booking-pro' ),
		default: __( 'Default', 'radius-hotel-booking-pro' ),
		admin: __( 'Administrator', 'radius-hotel-booking-pro' ),
		no_dashboard: __( 'No dashboard access', 'radius-hotel-booking-pro' ),
	} )[ source ] || source;

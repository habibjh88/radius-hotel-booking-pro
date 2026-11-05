/**
 * Turn a scanned or typed code into its booking (`GET scan`) and open it.
 */
import { __ } from '@wordpress/i18n';
import { api, toastError } from '@rtbp/lib';

/**
 * The booking a code points at.
 *
 * @param {string} code QR link, token or booking reference.
 * @return {Promise<{id: number, reference: string}>} Booking.
 */
export const resolveCode = ( code ) =>
	api.get( 'scan', { code: code.trim() } ).then( ( r ) => r.data );

/**
 * Open the booking a code points at; say so when there is none.
 *
 * @param {string}   code     Scanned or typed text.
 * @param {Function} navigate The router's navigate.
 * @return {Promise<boolean>} Whether a booking was opened.
 */
export async function openScanned( code, navigate ) {
	try {
		const booking = await resolveCode( code );
		navigate( `/bookings/${ booking.id }` );
		return true;
	} catch ( error ) {
		toastError(
			error,
			__( 'No booking matches this code.', 'radius-hotel-booking-pro' )
		);
		return false;
	}
}

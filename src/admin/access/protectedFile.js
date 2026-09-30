/**
 * Open or download a protected file (ADR-009) with the REST nonce and, when
 * the viewer holds one, the PIN grant for the keys it needs: a plain link
 * cannot send the `X-RTBP-Passcode` header, so a passcode-level key would
 * answer "not found".
 */
import { __ } from '@wordpress/i18n';

import { grantFor } from './passcode';

/**
 * Fetch the file.
 *
 * @param {string}   url  File URL.
 * @param {string[]} keys Access keys the file needs.
 * @return {Promise<Blob>} Contents.
 */
async function fetchFile( url, keys ) {
	const headers = {};
	const nonce = window.radius_hotel_booking_param?.nonce;
	if ( nonce ) {
		headers[ 'X-WP-Nonce' ] = nonce;
	}
	const grant = grantFor( keys );
	if ( grant ) {
		headers[ 'X-RTBP-Passcode' ] = grant;
	}
	const response = await fetch( url, {
		credentials: 'same-origin',
		headers,
	} );
	if ( ! response.ok ) {
		throw new Error(
			__(
				'The file could not be downloaded.',
				'radius-hotel-booking-pro'
			)
		);
	}
	return response.blob();
}

/**
 * Save a protected file under a name.
 *
 * @param {string}   url  File URL.
 * @param {string}   name File name.
 * @param {string[]} keys Access keys the file needs.
 * @return {Promise<void>}
 */
export async function downloadProtected( url, name, keys ) {
	const link = document.createElement( 'a' );
	link.href = URL.createObjectURL( await fetchFile( url, keys ) );
	link.download = name;
	document.body.appendChild( link );
	link.click();
	link.remove();
	setTimeout( () => URL.revokeObjectURL( link.href ), 1000 );
}

/**
 * Show a protected file in a new tab. The tab opens at once (a popup blocker
 * only allows it during the click) and receives the file when it arrives.
 *
 * @param {string}   url  File URL.
 * @param {string[]} keys Access keys the file needs.
 * @return {Promise<void>}
 */
export async function openProtected( url, keys ) {
	const win = window.open( '', '_blank' );
	try {
		const href = URL.createObjectURL( await fetchFile( url, keys ) );
		if ( win ) {
			win.location.href = href;
		} else {
			window.location.assign( href );
		}
		setTimeout( () => URL.revokeObjectURL( href ), 60 * 1000 );
	} catch ( error ) {
		win?.close();
		throw error;
	}
}

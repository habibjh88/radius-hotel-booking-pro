/**
 * Open or save a payslip (M15 T7a). It comes through the API client — which
 * asks for the PIN when `page.payroll` is at PIN level, as a plain link
 * could not — as a base64 file: the HTML view opens in a new tab to print,
 * the PDF is saved.
 */
import { api } from '@rtbp/lib';

/**
 * @param {number} runId  Run.
 * @param {number} lineId Line.
 * @param {string} format `html` or `pdf`.
 * @return {Promise<void>}
 */
export const openPayslip = ( runId, lineId, format ) =>
	openFile(
		`payroll/runs/${ runId }/lines/${ lineId }/payslip`,
		{ format },
		format
	);

/**
 * Fetch a base64 file from the API and open (`html`) or save (anything else) it.
 *
 * @param {string} path   API path.
 * @param {Object} params Query parameters.
 * @param {string} format `html` opens in a new tab; other formats are saved.
 * @return {Promise<void>}
 */
export async function openFile( path, params, format ) {
	// Opened now, while the click still counts, so the browser does not block it.
	const tab = 'html' === format ? window.open( '', '_blank' ) : null;
	try {
		const { data } = await api.get( path, params );
		const bytes = Uint8Array.from( atob( data.body ), ( c ) =>
			c.charCodeAt( 0 )
		);
		const url = URL.createObjectURL(
			new Blob( [ bytes ], { type: data.content_type } )
		);
		if ( tab ) {
			tab.location.href = url;
		} else {
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = data.filename;
			document.body.appendChild( link );
			link.click();
			link.remove();
		}
		setTimeout( () => URL.revokeObjectURL( url ), 60000 );
	} catch ( err ) {
		if ( tab ) {
			tab.close();
		}
		throw err;
	}
}

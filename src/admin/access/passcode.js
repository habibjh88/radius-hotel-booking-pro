/**
 * The passcode level on the client (M13 T4b).
 *
 * Registers on the free `rtbp.api.error` filter (ADR-015): when a request
 * answers `passcode_required`, it retries once with a cached grant, otherwise
 * opens the PIN prompt, gets a grant from `POST access/unlock`, and retries
 * the request with the `X-RTBP-Passcode` header. The screen that made the
 * request just sees it succeed, without a second click.
 *
 * Grants are kept in memory until they expire: under the `global` scope one
 * grant covers every key, under `per_key` each key has its own. The server
 * checks every grant again, so this cache only saves prompts.
 */
import { createRoot } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

import PasscodeDialog from './PasscodeDialog';

const HEADER = 'X-RTBP-Passcode';
const GLOBAL = '*';

/** @type {Map<string, {token: string, expires: number}>} */
const grants = new Map();

/** @type {Promise<string>|null} The prompt currently open. */
let prompt = null;

/**
 * A live grant under one scope ('*' or a key), if any.
 *
 * @param {string} scope Scope.
 * @return {string|null} Token.
 */
function live( scope ) {
	const grant = grants.get( scope );
	// A little margin, so a grant does not expire in flight.
	if ( grant && grant.expires - 3000 > Date.now() ) {
		return grant.token;
	}
	grants.delete( scope );
	return null;
}

/**
 * One token that covers every key: the global grant, or one per-key grant
 * shared by all of them (they were unlocked together).
 *
 * @param {string[]} keys Access keys.
 * @return {string|null} Token.
 */
function cachedToken( keys ) {
	const global = live( GLOBAL );
	if ( global ) {
		return global;
	}
	const tokens = new Set( keys.map( live ) );
	return tokens.size === 1 && ! tokens.has( null ) ? [ ...tokens ][ 0 ] : null;
}

/**
 * Open the PIN prompt for the keys a request needs.
 *
 * @param {string[]} keys Access keys.
 * @return {Promise<string>} The token; rejects when the prompt is closed.
 */
function openPrompt( keys ) {
	return new Promise( ( resolve, reject ) => {
		const node = document.createElement( 'div' );
		node.className = 'rtbp-root';
		document.body.appendChild( node );
		const root = createRoot( node );

		// Called from inside the dialog's own event handlers: unmount on the
		// next tick, not while React is still handling the event.
		const close = () =>
			setTimeout( () => {
				root.unmount();
				node.remove();
			} );

		root.render(
			<PasscodeDialog
				accessKeys={ keys }
				onUnlocked={ ( data ) => {
					const grant = {
						token: data.token,
						expires: Date.parse( data.expires_at ) || 0,
					};
					( data.scope === 'global' ? [ GLOBAL ] : keys ).forEach(
						( scope ) => grants.set( scope, grant )
					);
					close();
					resolve( data.token );
				} }
				onCancel={ () => {
					close();
					reject( new Error( 'cancelled' ) );
				} }
			/>
		);
	} );
}

/**
 * A token for the keys: cached, or from the prompt (one prompt at a time).
 *
 * @param {string[]} keys Access keys.
 * @return {Promise<string>} Token.
 */
async function tokenFor( keys ) {
	while ( prompt ) {
		try {
			await prompt;
		} catch {
			// The other prompt was closed; ask for these keys below.
		}
		const token = cachedToken( keys );
		if ( token ) {
			return token;
		}
	}

	prompt = openPrompt( keys );
	try {
		return await prompt;
	} finally {
		prompt = null;
	}
}

/**
 * A live grant covering these keys, for requests the API client does not
 * make (file downloads with fetch). Null when none is cached.
 *
 * @param {string[]} keys Access keys.
 * @return {string|null} Token.
 */
export const grantFor = ( keys ) => cachedToken( keys );

/**
 * Register the interceptor.
 *
 * @return {void}
 */
export function registerPasscode() {
	addFilter(
		'rtbp.api.error',
		'radius-hotel-booking-pro/passcode',
		( handled, error, { retry } ) => {
			if ( handled || error?.code !== 'passcode_required' ) {
				return handled;
			}

			// Every passcode key the request needs (all unlocked by one PIN).
			const keys = error.data?.keys?.length
				? error.data.keys
				: [ error.data?.key || '' ];

			return ( async () => {
				const cached = cachedToken( keys );
				if ( cached ) {
					try {
						return await retry( {
							headers: { [ HEADER ]: cached },
						} );
					} catch ( failure ) {
						if ( failure?.code !== 'passcode_required' ) {
							throw failure;
						}
						// Revoked on the server (PIN changed, scope changed).
						grants.clear();
					}
				}

				let token;
				try {
					token = await tokenFor( keys );
				} catch {
					// Closed without a PIN: the request fails as it was.
					throw error;
				}
				return retry( { headers: { [ HEADER ]: token } } );
			} )();
		}
	);
}

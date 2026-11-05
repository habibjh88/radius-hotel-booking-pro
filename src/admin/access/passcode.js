/**
 * The passcode level on the client (M13 T4b).
 *
 * Registers on the free `rtbp.api.error` filter (ADR-015): when a request
 * answers `passcode_required`, it retries once with a cached grant, otherwise
 * opens the PIN prompt, gets a grant from `POST access/unlock`, and retries
 * the request with the `X-RTBP-Passcode` header. The screen that made the
 * request just sees it succeed, without a second click.
 *
 * Grants are kept until they expire: under the `global` scope one grant covers
 * every key, under `per_key` each key has its own. They live in a session
 * cookie, so a page refresh or a second tab does not ask again, and closing
 * the browser (or signing out, which clears the cookie and voids the grants on
 * the server) does. The validity is idle time: each request a grant lets
 * through starts it again, here as on the server. The server checks every
 * grant again, so this cache only saves prompts.
 */
import { createRoot } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

import PasscodeDialog from './PasscodeDialog';

const HEADER = 'X-RTBP-Passcode';
const GLOBAL = '*';
const COOKIE = 'rtbp_pro_pc';

/**
 * The grants saved in the session cookie.
 *
 * @return {Map<string, {token: string, expires: number, ttl: number}>} Grants by scope.
 */
function load() {
	const raw = document.cookie
		.split( '; ' )
		.find( ( part ) => part.startsWith( COOKIE + '=' ) );
	try {
		const saved = raw
			? JSON.parse( decodeURIComponent( raw.slice( COOKIE.length + 1 ) ) )
			: null;
		return new Map(
			saved && typeof saved === 'object' ? Object.entries( saved ) : []
		);
	} catch {
		return new Map();
	}
}

/**
 * Save the grants to the session cookie (no expiry: gone when the browser
 * closes), or drop it when there are none.
 *
 * @return {void}
 */
function save() {
	const secure = window.location.protocol === 'https:' ? '; Secure' : '';
	const value = grants.size
		? encodeURIComponent( JSON.stringify( Object.fromEntries( grants ) ) )
		: '';
	const expiry = grants.size ? '' : '; Max-Age=0';
	document.cookie = `${ COOKIE }=${ value }; Path=/; SameSite=Strict${ expiry }${ secure }`;
}

/**
 * Grants by scope, re-read from the cookie before each use, since another tab
 * may have unlocked meanwhile.
 *
 * @type {Map<string, {token: string, expires: number, ttl: number}>}
 */
let grants = load();

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
	if ( grants.delete( scope ) ) {
		save();
	}
	return null;
}

/**
 * Start a grant's validity again after the server let a request through with
 * it (the server did the same).
 *
 * @param {string} token Token.
 * @return {void}
 */
function extend( token ) {
	grants = load();
	grants.forEach( ( grant ) => {
		if ( grant.token === token && grant.ttl ) {
			grant.expires = Date.now() + grant.ttl;
		}
	} );
	save();
}

/**
 * One token that covers every key: the global grant, or one per-key grant
 * shared by all of them (they were unlocked together).
 *
 * @param {string[]} keys Access keys.
 * @return {string|null} Token.
 */
function cachedToken( keys ) {
	grants = load();
	const global = live( GLOBAL );
	if ( global ) {
		return global;
	}
	const tokens = new Set( keys.map( live ) );
	return tokens.size === 1 && ! tokens.has( null )
		? [ ...tokens ][ 0 ]
		: null;
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
					const expires = Date.parse( data.expires_at ) || 0;
					const grant = {
						token: data.token,
						expires,
						// The validity, to start it again on each use.
						ttl: Math.max( 0, expires - Date.now() ),
					};
					grants = load();
					( data.scope === 'global' ? [ GLOBAL ] : keys ).forEach(
						( scope ) => grants.set( scope, grant )
					);
					save();
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
						const result = await retry( {
							headers: { [ HEADER ]: cached },
						} );
						extend( cached );
						return result;
					} catch ( failure ) {
						if ( failure?.code !== 'passcode_required' ) {
							throw failure;
						}
						// Revoked on the server (PIN changed, scope changed).
						grants.clear();
						save();
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

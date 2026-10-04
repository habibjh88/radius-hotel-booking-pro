/**
 * Staff self-service (M16): the signed-in user's own profile.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

export const PROFILE_KEY = [ 'me', 'profile' ];

/**
 * My profile.
 *
 * @return {Object} Query; data `{ username, has_file, employee, values, fields, options }`.
 */
export const useProfile = () =>
	useQuery( {
		queryKey: PROFILE_KEY,
		queryFn: () => api.get( 'me/profile' ).then( ( r ) => r.data.profile ),
	} );

/**
 * Save my profile (only the fields sent change).
 *
 * @return {Object} Mutation; `mutateAsync( fields )` resolves to the profile.
 */
export function useSaveProfile() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( fields ) =>
			api.put( 'me/profile', fields ).then( ( r ) => r.data.profile ),
		onSuccess: ( profile ) => {
			client.setQueryData( PROFILE_KEY, profile );
			// The staff screens show the same file.
			client.invalidateQueries( { queryKey: [ 'employees' ] } );
		},
	} );
}

/**
 * Use a new REST nonce from now on. Changing the password starts a new
 * session, and the nonce belongs to the session: without this, every
 * request after the change would be refused.
 *
 * @param {string} nonce The new nonce.
 */
function applyNonce( nonce ) {
	if ( ! nonce ) {
		return;
	}
	[
		window.radius_hotel_booking_param,
		window.radius_hotel_booking_site_param,
	].forEach( ( params ) => {
		if ( params && 'object' === typeof params ) {
			params.nonce = nonce;
		}
	} );
	// WordPress's own requests on the page (wp.apiFetch, wpApiSettings).
	if ( window.wp?.apiFetch?.nonceMiddleware ) {
		window.wp.apiFetch.nonceMiddleware.nonce = nonce;
	}
	if ( window.wpApiSettings ) {
		window.wpApiSettings.nonce = nonce;
	}
}

/**
 * Change my password (16.3); every other session is signed out.
 *
 * @return {Object} Mutation; `mutateAsync( { current_password, new_password } )`.
 */
export const useChangePassword = () =>
	useMutation( {
		mutationFn: ( input ) =>
			api.post( 'me/password', input ).then( ( r ) => {
				applyNonce( r.data?.nonce );
				return r;
			} ),
	} );

/**
 * Set my PIN (16.4) with my current PIN or password.
 *
 * @return {Object} Mutation; `mutateAsync( { pin, current_pin | current_password } )`.
 */
export function useChangePin() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( input ) =>
			api.post( 'me/pin', input ).then( ( r ) => r.data.security ),
		onSuccess: ( security ) =>
			client.setQueryData( PROFILE_KEY, ( profile ) =>
				profile ? { ...profile, security } : profile
			),
	} );
}

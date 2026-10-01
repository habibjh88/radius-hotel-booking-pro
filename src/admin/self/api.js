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

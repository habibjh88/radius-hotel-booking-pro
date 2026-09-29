/**
 * Access roles and staff access: every REST call of the Pro access screens
 * (M13 T5b), and their React Query hooks. The cache is the free app's.
 */
import {
	useMutation,
	useQuery,
	useQueryClient,
} from '@tanstack/react-query';
import { api, refreshAccess, toast, toastError } from './runtime';

const KEYS = {
	registry: [ 'access', 'registry' ],
	roles: [ 'access', 'roles' ],
	users: [ 'access', 'users' ],
	user: ( id ) => [ 'access', 'users', Number( id ) ],
};

/**
 * Keys, groups and built-in roles (free `GET access/registry`).
 *
 * @return {Object} Query.
 */
export const useRegistry = () =>
	useQuery( {
		queryKey: KEYS.registry,
		queryFn: () => api.get( 'access/registry' ).then( ( r ) => r.data ),
		staleTime: 5 * 60 * 1000,
	} );

/**
 * Profile fields and their global defaults.
 *
 * @return {Object} Query.
 */
export const useProfileFields = () =>
	useQuery( {
		queryKey: [ 'access', 'profile-fields' ],
		queryFn: () =>
			api.get( 'access/profile-fields' ).then( ( r ) => r.data ),
	} );

/**
 * @return {Object} Query: the access roles.
 */
export const useRoles = () =>
	useQuery( {
		queryKey: KEYS.roles,
		queryFn: () =>
			api.get( 'access/roles' ).then( ( r ) => r.data?.roles ?? [] ),
	} );

/**
 * @return {Object} Query: dashboard users.
 */
export const useUsers = () =>
	useQuery( {
		queryKey: KEYS.users,
		queryFn: () =>
			api.get( 'access/users' ).then( ( r ) => r.data?.users ?? [] ),
	} );

/**
 * @param {number} id User id.
 * @return {Object} Query: one user's access.
 */
export const useUserAccess = ( id ) =>
	useQuery( {
		queryKey: KEYS.user( id ),
		queryFn: () => api.get( `access/users/${ id }` ).then( ( r ) => r.data ),
		enabled: !! id,
	} );

/**
 * A mutation that toasts, refreshes the given queries and the viewer's own
 * access map (their own permissions may have changed).
 *
 * @param {Function} fn         Request.
 * @param {Array}    invalidate Query keys to refresh.
 * @return {Object} Mutation.
 */
function useAccessMutation( fn, invalidate ) {
	const queryClient = useQueryClient();
	return useMutation( {
		mutationFn: fn,
		onSuccess: ( result ) => {
			if ( result?.message ) {
				toast.success( result.message );
			}
			invalidate.forEach( ( queryKey ) =>
				queryClient.invalidateQueries( { queryKey } )
			);
			refreshAccess();
		},
		// Not `onError: toastError`: its second argument is the request data.
		onError: ( error ) => toastError( error ),
	} );
}

export const useSaveRole = () =>
	useAccessMutation(
		( { id, ...data } ) =>
			id
				? api.put( `access/roles/${ id }`, data )
				: api.post( 'access/roles', data ),
		[ KEYS.roles, KEYS.users ]
	);

export const useDeleteRole = () =>
	useAccessMutation(
		( id ) => api.del( `access/roles/${ id }` ),
		[ KEYS.roles, KEYS.users ]
	);

export const useSaveUserAccess = ( id ) =>
	useAccessMutation(
		( data ) => api.put( `access/users/${ id }`, data ),
		[ KEYS.user( id ), KEYS.users, KEYS.roles ]
	);

export const useSetPin = ( id ) =>
	useAccessMutation(
		( pin ) => api.post( `access/users/${ id }/pin`, { pin } ),
		[ KEYS.user( id ), KEYS.users ]
	);

export const useResetOverrides = () =>
	useAccessMutation(
		( userId ) =>
			api.post(
				'access/reset-overrides',
				userId ? { user_id: userId } : {}
			),
		[ [ 'access', 'users' ] ]
	);

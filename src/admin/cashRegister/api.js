/**
 * Cash register REST calls (R3-T5 – R3-T8). `register.use` for your own
 * register, `register.manage` for everyone's (the server enforces both).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

export const REGISTER_KEY = [ 'cash-register' ];

/**
 * My register: the open session with its live tally (refreshed every 30 s
 * while open), and my last closed ones.
 *
 * @return {Object} Query; data `{ session, recent }`.
 */
export const useMyRegister = () =>
	useQuery( {
		queryKey: [ ...REGISTER_KEY, 'mine' ],
		queryFn: () => api.get( 'cash-register' ).then( ( r ) => r.data ),
		refetchInterval: ( query ) =>
			query.state.data?.session ? 30000 : false,
	} );

/**
 * Open or close my register.
 *
 * @return {Object} Mutation; `mutateAsync( { action: 'open'|'close', ...body } )` resolves with the response.
 */
export function useRegisterAction() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { action, ...body } ) =>
			api.post( `cash-register/${ action }`, body ),
		onSuccess: () => client.invalidateQueries( { queryKey: REGISTER_KEY } ),
	} );
}

/**
 * Every register (managers).
 *
 * @param {Object}  filters `{ status?, from?, to?, page? }`.
 * @param {boolean} enabled Whether to fetch.
 * @return {Object} Query; data `{ sessions, total }`.
 */
export const useAllRegisters = ( filters, enabled ) =>
	useQuery( {
		queryKey: [ ...REGISTER_KEY, 'all', filters ],
		queryFn: () =>
			api
				.get( 'cash-register/sessions', { per_page: 20, ...filters } )
				.then( ( r ) => r.data ),
		enabled,
	} );

/**
 * Pricing rules: every REST call of the Pro pricing screen (M07 T5b) and its
 * React Query hooks. The cache is the free app's.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

export const KEYS = {
	templates: [ 'pricing', 'templates' ],
	rules: ( kind ) => [ 'pricing', 'rules', kind ],
};

/**
 * Pricing templates, with how many rules use each.
 *
 * @return {Object} Query.
 */
export const useTemplates = () =>
	useQuery( {
		queryKey: KEYS.templates,
		queryFn: () =>
			api.get( 'pricing/templates' ).then( ( r ) => r.data.templates ),
	} );

/**
 * The rules of one kind.
 *
 * @param {string} kind `seasonal`, … .
 * @return {Object} Query.
 */
export const useRules = ( kind ) =>
	useQuery( {
		queryKey: KEYS.rules( kind ),
		queryFn: () =>
			api.get( 'pricing/rules', { kind } ).then( ( r ) => r.data.rules ),
	} );

/**
 * Room types and rate plans for the rule scope (free endpoints; empty when
 * the viewer may not read them).
 *
 * @return {Object} `{ roomTypes, ratePlans, loading }`.
 */
export function useScopeOptions() {
	const roomTypes = useQuery( {
		queryKey: [ 'room-types' ],
		queryFn: () =>
			api.get( 'room-types' ).then( ( r ) => r.data.room_types ),
		retry: false,
	} );
	const ratePlans = useQuery( {
		queryKey: [ 'rate-plans' ],
		queryFn: () =>
			api.get( 'rate-plans' ).then( ( r ) => r.data.rate_plans ),
		retry: false,
	} );
	return {
		roomTypes: roomTypes.data || [],
		ratePlans: ratePlans.data || [],
		loading: roomTypes.isPending || ratePlans.isPending,
	};
}

/**
 * Invalidate everything pricing (templates change rule summaries).
 *
 * @param {Object} client Query client.
 * @return {void}
 */
const refresh = ( client ) =>
	client.invalidateQueries( { queryKey: [ 'pricing' ] } );

export function useSaveTemplate() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, values } ) =>
			( id
				? api.put( `pricing/templates/${ id }`, values )
				: api.post( 'pricing/templates', values )
			).then( ( r ) => r.data.template ),
		onSuccess: () => refresh( client ),
	} );
}

export function useDeleteTemplate() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( id ) => api.del( `pricing/templates/${ id }` ),
		onSuccess: () => refresh( client ),
	} );
}

export function useSaveRule() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, values } ) =>
			( id
				? api.put( `pricing/rules/${ id }`, values )
				: api.post( 'pricing/rules', values )
			).then( ( r ) => r.data.rule ),
		onSuccess: () => refresh( client ),
	} );
}

export function useDeleteRule() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( id ) => api.del( `pricing/rules/${ id }` ),
		onSuccess: () => refresh( client ),
	} );
}

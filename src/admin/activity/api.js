/**
 * Activity log REST calls and queries (M14 T3a).
 */
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

/**
 * A filtered page of the log.
 *
 * @param {Object} filters `user_id`, `from`, `to`, `kind`, `action`, `q`, `page`.
 * @return {Object} Query: `{ rows, meta }`.
 */
export const useActivity = ( filters ) =>
	useQuery( {
		queryKey: [ 'activity', 'list', filters ],
		queryFn: () =>
			api
				.get( 'activity', filters )
				.then( ( r ) => ( { rows: r.data?.rows ?? [], meta: r.meta } ) ),
		placeholderData: keepPreviousData,
	} );

/**
 * The filter data: actions, kinds, people.
 *
 * @return {Object} Query.
 */
export const useActivityFilters = () =>
	useQuery( {
		queryKey: [ 'activity', 'actions' ],
		queryFn: () => api.get( 'activity/actions' ).then( ( r ) => r.data ),
		staleTime: 5 * 60 * 1000,
	} );

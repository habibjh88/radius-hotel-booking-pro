/**
 * Pro reports (M10 T6a–T6b): the free `GET reports/{name}` endpoint, which
 * serves the reports Pro adds to its registry.
 */
import { useQuery } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

/**
 * One report (shares the free Reports screen's query keys and cache).
 *
 * @param {string} name   Report name.
 * @param {Object} params `{ from, to, mode }`.
 * @return {Object} Query; `data` is the payload.
 */
export const useProReport = ( name, params ) =>
	useQuery( {
		queryKey: [ 'reports', name, params ],
		queryFn: () =>
			api.get( `reports/${ name }`, params ).then( ( r ) => r.data ),
		placeholderData: ( previous ) => previous,
		staleTime: 60 * 1000,
	} );

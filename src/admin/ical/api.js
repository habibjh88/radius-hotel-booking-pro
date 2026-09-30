/**
 * Calendar sync (M08 T6a–T6b): every REST call of the screen and its React
 * Query hooks. Writes refresh the free calendar and blocked dates too, since
 * an import adds or removes blocks.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

export const FEEDS_KEY = [ 'ical', 'feeds' ];

/**
 * Every room with its calendar link and channel calendars.
 *
 * @return {Object} Query; data `{ rooms, intervals }`.
 */
export const useFeeds = () =>
	useQuery( {
		queryKey: FEEDS_KEY,
		queryFn: () => api.get( 'ical/feeds' ).then( ( r ) => r.data ),
	} );

/**
 * A mutation that refreshes the feeds, the calendar and the blocked dates.
 *
 * @param {Function} send Called with the variables; returns the request.
 * @return {Object} Mutation; resolves with the response `data`.
 */
function useFeedMutation( send ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( variables ) => send( variables ).then( ( r ) => r.data ),
		onSuccess: () => {
			client.invalidateQueries( { queryKey: FEEDS_KEY } );
			client.invalidateQueries( {
				queryKey: [ 'availability-calendar' ],
			} );
			client.invalidateQueries( { queryKey: [ 'blocks' ] } );
		},
	} );
}

/** Share a room's calendar, or give it a new link: `mutateAsync( roomId )`. */
export const usePublish = () =>
	useFeedMutation( ( roomId ) => api.post( `ical/exports/${ roomId }` ) );

/** Stop sharing a room's calendar: `mutateAsync( roomId )`. */
export const useUnpublish = () =>
	useFeedMutation( ( roomId ) => api.del( `ical/exports/${ roomId }` ) );

/** Add (`id` absent) or change a channel calendar; resolves `{ feed, sync }`. */
export const useSaveImport = () =>
	useFeedMutation( ( { id, ...fields } ) =>
		id
			? api.put( `ical/imports/${ id }`, fields )
			: api.post( 'ical/imports', fields )
	);

/** Remove a channel calendar and its blocks; resolves `{ removed }`. */
export const useDeleteImport = () =>
	useFeedMutation( ( id ) => api.del( `ical/imports/${ id }` ) );

/** Read a channel calendar now; resolves `{ sync, feed }`. */
export const useSyncImport = () =>
	useFeedMutation( ( id ) => api.post( `ical/imports/${ id }/sync` ) );

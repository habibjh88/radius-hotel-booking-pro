/**
 * Log archives (M14 T4, features 14.11–14.13): the next automatic run, past
 * archives with their files, and "Archive now".
 */
import { Suspense, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Archive, Download } from 'lucide-react';
import {
	Badge,
	Button,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	EmptyState,
	Skeleton,
	Switch,
} from '@rtbp/ui';
import { api, format, toast, toastError } from '@rtbp/lib';

import { grantFor } from '../access/passcode';

const KEY = [ 'activity', 'archives' ];

/**
 * Download a protected file with the REST nonce and, when the viewer holds
 * one, the PIN grant (a plain link cannot send the header).
 *
 * @param {string} url  File URL.
 * @param {string} name File name.
 * @return {Promise<void>}
 */
async function download( url, name ) {
	const headers = {};
	const nonce = window.radius_hotel_booking_param?.nonce;
	if ( nonce ) {
		headers[ 'X-WP-Nonce' ] = nonce;
	}
	const grant = grantFor( [ 'activity.archive' ] );
	if ( grant ) {
		headers[ 'X-RTBP-Passcode' ] = grant;
	}
	const response = await fetch( url, { credentials: 'same-origin', headers } );
	if ( ! response.ok ) {
		throw new Error(
			__( 'The file could not be downloaded.', 'radius-hotel-booking-pro' )
		);
	}
	const link = document.createElement( 'a' );
	link.href = URL.createObjectURL( await response.blob() );
	link.download = name;
	document.body.appendChild( link );
	link.click();
	link.remove();
	setTimeout( () => URL.revokeObjectURL( link.href ), 1000 );
}

/**
 * @param {Object}   props         Props.
 * @param {Function} props.onClose Close.
 * @return {JSX.Element} Dialog.
 */
export default function ArchivesDialog( { onClose } ) {
	const queryClient = useQueryClient();
	const [ purge, setPurge ] = useState( null );
	const archives = useQuery( {
		queryKey: KEY,
		queryFn: () => api.get( 'activity/archives' ).then( ( r ) => r.data ),
		retry: false,
	} );
	const run = useMutation( {
		mutationFn: ( remove ) => api.post( 'activity/archives', { purge: remove } ),
		onSuccess: ( { message } ) => {
			toast.success( message );
			queryClient.invalidateQueries( { queryKey: [ 'activity' ] } );
		},
		onError: ( error ) => toastError( error ),
	} );

	const data = archives.data;
	const removeAfter = purge ?? !! data?.settings?.purgeAfterArchive;
	const save = ( archive, type ) =>
		download(
			'json' === type ? archive.json_url : archive.csv_url,
			`activity-log-${ archive.first_id }-${ archive.last_id }.${ type }`
		).catch( ( error ) => toastError( error ) );

	return (
		<Suspense fallback={ null }>
			<Dialog open onOpenChange={ ( open ) => ! open && onClose() }>
				<DialogContent className="rtbp-root">
					<DialogHeader>
						<DialogTitle>
							{ __( 'Log archives', 'radius-hotel-booking-pro' ) }
						</DialogTitle>
						<DialogDescription>
							{ data?.next_run
								? sprintf(
										/* translators: %s: date and time. */
										__( 'Next automatic archive: %s', 'radius-hotel-booking-pro' ),
										format.formatDateTime( data.next_run )
								  )
								: __(
										'Automatic archiving is off, or nothing is logged yet.',
										'radius-hotel-booking-pro'
								  ) }
						</DialogDescription>
					</DialogHeader>

					{ archives.isPending ? (
						<Skeleton className="h-16 w-full rounded-md" />
					) : null }
					{ archives.error ? (
						<p role="alert" className="m-0 text-sm text-destructive">
							{ archives.error.message }
						</p>
					) : null }
					{ data && ! data.archives.length ? (
						<EmptyState
							icon={ Archive }
							title={ __( 'No archives yet', 'radius-hotel-booking-pro' ) }
							description={ __(
								'Archive now, or wait for the automatic archive.',
								'radius-hotel-booking-pro'
							) }
						/>
					) : null }
					{ data?.archives.length ? (
						<ul className="m-0 max-h-[300px] list-none divide-y divide-border overflow-auto p-0">
							{ data.archives.map( ( archive ) => (
								<li
									key={ archive.id }
									className="flex flex-col gap-2 py-2 md:flex-row md:items-center md:gap-4"
								>
									<div className="min-w-0 flex-1 text-sm">
										<div className="text-heading">
											{ sprintf(
												/* translators: 1: first date, 2: last date. */
												__( '%1$s to %2$s', 'radius-hotel-booking-pro' ),
												format.formatDate( archive.period_start ),
												format.formatDate( archive.period_end )
											) }
										</div>
										<div className="text-xs text-muted-foreground">
											{ [
												sprintf(
													/* translators: %d: number of log entries. */
													_n( '%d entry', '%d entries', archive.rows, 'radius-hotel-booking-pro' ),
													archive.rows
												),
												archive.created_by,
												format.formatDateTime( archive.created_at ),
											].join( ' · ' ) }
										</div>
									</div>
									<div className="flex shrink-0 flex-wrap items-center gap-1.5">
										{ archive.purged ? (
											<Badge variant="outline">
												{ __( 'Removed from the log', 'radius-hotel-booking-pro' ) }
											</Badge>
										) : null }
										<Button size="sm" variant="outline" onClick={ () => save( archive, 'csv' ) }>
											<Download aria-hidden="true" />
											CSV
										</Button>
										<Button size="sm" variant="ghost" onClick={ () => save( archive, 'json' ) }>
											JSON
										</Button>
									</div>
								</li>
							) ) }
						</ul>
					) : null }

					{ data ? (
						<DialogFooter className="flex-col justify-between gap-3 md:flex-row md:items-center">
							<label className="flex items-center gap-2 text-sm">
								<Switch
									checked={ removeAfter }
									onCheckedChange={ ( value ) => setPurge( value ) }
								/>
								{ __(
									'Remove the archived entries after checking the file',
									'radius-hotel-booking-pro'
								) }
							</label>
							<Button
								onClick={ () => run.mutate( removeAfter ) }
								disabled={ run.isPending }
							>
								{ run.isPending
									? __( 'Archiving…', 'radius-hotel-booking-pro' )
									: __( 'Archive now', 'radius-hotel-booking-pro' ) }
							</Button>
						</DialogFooter>
					) : null }
				</DialogContent>
			</Dialog>
		</Suspense>
	);
}

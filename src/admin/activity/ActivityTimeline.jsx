/**
 * The history of one record (M14 T3b): every logged action on a booking,
 * guest, room or employee, newest first. Embedded in detail screens through
 * `rtbp.booking.panels` / `rtbp.guest.panels` (./timeline.js), or rendered
 * directly by Pro screens.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { History } from 'lucide-react';
import { Badge, Button, EmptyState, Skeleton } from '@rtbp/ui';
import { api, format } from '@rtbp/lib';

import EntryDialog from './EntryDialog';

/**
 * "status: pending → confirmed" for up to two changed fields.
 *
 * @param {Object|null} changes `{ before, after }`.
 * @return {string} Summary ('' when nothing changed).
 */
const summary = ( changes ) => {
	if ( ! changes ) {
		return '';
	}
	const fields = Object.keys( { ...changes.before, ...changes.after } );
	const text = fields
		.slice( 0, 2 )
		.map( ( field ) =>
			sprintf(
				/* translators: 1: field, 2: old value, 3: new value. */
				__( '%1$s: %2$s → %3$s', 'radius-hotel-booking-pro' ),
				field,
				changes.before?.[ field ] ?? '—',
				changes.after?.[ field ] ?? '—'
			)
		)
		.join( ' · ' );
	return fields.length > 2
		? sprintf(
				/* translators: 1: the first changes, 2: how many more fields. */
				__( '%1$s (+%2$d more)', 'radius-hotel-booking-pro' ),
				text,
				fields.length - 2
		  )
		: text;
};

/**
 * @param {Object} props      Props.
 * @param {string} props.type Subject type (`booking`, `guest`, `room`, `employee` …).
 * @param {string} props.id   Subject id.
 * @return {JSX.Element|null} Timeline; nothing when the viewer may not read it.
 */
export default function ActivityTimeline( { type, id } ) {
	const [ open, setOpen ] = useState( null );
	const history = useQuery( {
		queryKey: [ 'activity', 'subject', type, String( id ) ],
		queryFn: () =>
			api
				.get( `activity/subject/${ type }/${ encodeURIComponent( id ) }` )
				.then( ( r ) => r.data?.rows ?? [] ),
		enabled: !! type && id !== undefined && id !== null && id !== '',
		retry: false,
	} );

	// Not allowed to read this record's history: show nothing.
	if ( history.error?.status === 403 ) {
		return null;
	}
	if ( history.isPending ) {
		return (
			<div className="space-y-2" role="status" aria-live="polite">
				{ [ 0, 1, 2 ].map( ( i ) => (
					<Skeleton key={ i } className="h-10 w-full rounded-md" />
				) ) }
			</div>
		);
	}
	if ( history.error ) {
		return (
			<div
				role="alert"
				className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-destructive-soft p-4 text-sm text-destructive"
			>
				<span>{ history.error.message }</span>
				<Button variant="outline" size="sm" onClick={ () => history.refetch() }>
					{ __( 'Try again', 'radius-hotel-booking-pro' ) }
				</Button>
			</div>
		);
	}
	if ( ! history.data.length ) {
		return (
			<EmptyState
				icon={ History }
				title={ __( 'No history yet', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Actions on this record will appear here.',
					'radius-hotel-booking-pro'
				) }
			/>
		);
	}

	return (
		<>
			<ol className="m-0 list-none divide-y divide-border p-0">
				{ history.data.map( ( entry ) => (
					<li key={ entry.id } className="flex gap-3 py-2">
						<span
							aria-hidden="true"
							className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary"
						/>
						<button
							type="button"
							onClick={ () => setOpen( entry ) }
							className="block w-full border-0 bg-transparent p-0 text-left"
						>
							<span className="flex flex-wrap items-center gap-1.5 text-sm text-heading">
								{ entry.description }
								{ entry.repeat_count > 1 && entry.action === 'page.view' ? (
									<Badge variant="outline">
										{ sprintf(
											/* translators: %d: how many times it happened. */
											__( '×%d', 'radius-hotel-booking-pro' ),
											entry.repeat_count
										) }
									</Badge>
								) : null }
							</span>
							<span className="block text-xs text-muted-foreground">
								{ [ entry.user.name, format.formatDateTime( entry.time ) ]
									.filter( Boolean )
									.join( ' · ' ) }
							</span>
							{ entry.changes ? (
								<span className="mt-0.5 block text-xs text-muted-foreground">
									{ summary( entry.changes ) }
								</span>
							) : null }
						</button>
					</li>
				) ) }
			</ol>
			{ open ? (
				<EntryDialog entry={ open } onClose={ () => setOpen( null ) } />
			) : null }
		</>
	);
}

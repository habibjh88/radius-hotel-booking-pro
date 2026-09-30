/**
 * Calendar sync (`#/calendar-sync`, feature 8.10): per room, the link that
 * shares its calendar with Booking.com, Airbnb and similar sites (T6a), and
 * the channel calendars it reads back as blocked dates (T6b). The links are
 * secrets, so the whole screen needs `availability.manage`.
 */
import { Suspense, useMemo, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Link } from 'react-router-dom';
import { CalendarSync, Copy, Plus, RefreshCw } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	EmptyState,
	Input,
	Panel,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
	StatusBadge,
} from '@rtbp/ui';
import { format, toast, toastError } from '@rtbp/lib';

import {
	useDeleteImport,
	useFeeds,
	usePublish,
	useSyncImport,
	useUnpublish,
} from './api';
import ImportDialog, { announceSync } from './ImportDialog';
import { intervalLabel } from './labels';

/**
 * Copy text, with a fallback for a page without the Clipboard API (plain http).
 *
 * @param {string} text Text.
 * @return {Promise<void>} Done.
 */
async function copy( text ) {
	if ( window.navigator.clipboard && window.isSecureContext ) {
		await window.navigator.clipboard.writeText( text );
		return;
	}
	const area = document.createElement( 'textarea' );
	area.value = text;
	area.setAttribute( 'readonly', '' );
	area.style.position = 'fixed';
	area.style.opacity = '0';
	document.body.appendChild( area );
	area.select();
	document.execCommand( 'copy' );
	document.body.removeChild( area );
}

/**
 * The room's own calendar link.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.room    Room entry.
 * @param {Function} props.confirm Opens a confirmation `{ title, description, label, run }`.
 * @return {JSX.Element} Section.
 */
function ExportSection( { room, confirm } ) {
	const publish = usePublish();
	const unpublish = useUnpublish();
	const inputId = `rtbp-ical-link-${ room.room_id }`;

	if ( ! room.published ) {
		return (
			<div className="flex flex-wrap items-center justify-between gap-2">
				<p className="m-0 text-sm text-muted-foreground">
					{ __(
						'This room’s calendar is not shared.',
						'radius-hotel-booking-pro'
					) }
				</p>
				<Button
					variant="outline"
					size="sm"
					disabled={ publish.isPending }
					onClick={ () =>
						publish
							.mutateAsync( room.room_id )
							.then( () =>
								toast.success(
									__(
										'Link ready: paste it into the channel.',
										'radius-hotel-booking-pro'
									)
								)
							)
							.catch( toastError )
					}
				>
					{ __( 'Share calendar', 'radius-hotel-booking-pro' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="space-y-2">
			<label
				htmlFor={ inputId }
				className="block text-xs font-semibold text-heading"
			>
				{ __(
					'Link for the channels (keep it private)',
					'radius-hotel-booking-pro'
				) }
			</label>
			<div className="flex gap-2">
				<Input
					id={ inputId }
					readOnly
					value={ room.url }
					onFocus={ ( e ) => e.target.select() }
					className="min-w-0 flex-1 font-mono text-xs"
				/>
				<Button
					variant="outline"
					size="sm"
					className="h-9 shrink-0"
					onClick={ () =>
						copy( room.url )
							.then( () =>
								toast.success(
									__(
										'Link copied.',
										'radius-hotel-booking-pro'
									)
								)
							)
							.catch( toastError )
					}
				>
					<Copy className="h-4 w-4" aria-hidden="true" />
					<span className="sr-only sm:not-sr-only">
						{ __( 'Copy', 'radius-hotel-booking-pro' ) }
					</span>
				</Button>
			</div>
			<div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
				<p className="m-0 text-xs text-muted-foreground">
					{ room.last_read_at
						? sprintf(
								/* translators: %s: date and time. */
								__(
									'Last read by a channel: %s',
									'radius-hotel-booking-pro'
								),
								format.formatDateTime( room.last_read_at )
						  )
						: __(
								'No channel has read it yet.',
								'radius-hotel-booking-pro'
						  ) }
				</p>
				<div className="flex gap-3">
					<Button
						variant="link"
						className="h-auto p-0 text-xs"
						onClick={ () =>
							confirm( {
								title: __(
									'Make a new link?',
									'radius-hotel-booking-pro'
								),
								description: __(
									'The current link stops working at once. Paste the new one into every channel that uses it.',
									'radius-hotel-booking-pro'
								),
								label: __(
									'Make a new link',
									'radius-hotel-booking-pro'
								),
								run: () => publish.mutateAsync( room.room_id ),
								done: __(
									'New link ready.',
									'radius-hotel-booking-pro'
								),
							} )
						}
					>
						{ __( 'New link', 'radius-hotel-booking-pro' ) }
					</Button>
					<Button
						variant="link"
						className="h-auto p-0 text-xs text-destructive"
						onClick={ () =>
							confirm( {
								title: __(
									'Stop sharing this calendar?',
									'radius-hotel-booking-pro'
								),
								description: __(
									'The link stops working; channels will no longer see when this room is taken.',
									'radius-hotel-booking-pro'
								),
								label: __(
									'Stop sharing',
									'radius-hotel-booking-pro'
								),
								run: () =>
									unpublish.mutateAsync( room.room_id ),
								done: __(
									'Calendar no longer shared.',
									'radius-hotel-booking-pro'
								),
							} )
						}
					>
						{ __( 'Stop sharing', 'radius-hotel-booking-pro' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}

/**
 * The channel calendars a room reads.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.room    Room entry.
 * @param {Function} props.onEdit  Opens the dialog (`null` = add).
 * @param {Function} props.confirm Opens a confirmation.
 * @return {JSX.Element} Section.
 */
function ImportsSection( { room, onEdit, confirm } ) {
	const sync = useSyncImport();
	const remove = useDeleteImport();
	const [ reading, setReading ] = useState( 0 );

	return (
		<div className="space-y-2">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<p className="m-0 text-xs font-semibold text-heading">
					{ __( 'Channel calendars', 'radius-hotel-booking-pro' ) }
				</p>
				<Button
					variant="link"
					className="h-auto p-0 text-xs"
					onClick={ () => onEdit( null ) }
				>
					<Plus className="h-3.5 w-3.5" aria-hidden="true" />
					{ __( 'Add', 'radius-hotel-booking-pro' ) }
				</Button>
			</div>
			{ room.imports.length ? (
				<ul className="m-0 list-none space-y-2 p-0">
					{ room.imports.map( ( feed ) => (
						<li
							key={ feed.id }
							className="m-0 space-y-1.5 rounded-lg border border-border p-3"
						>
							<div className="flex flex-wrap items-center gap-2">
								<span className="text-sm font-semibold text-heading">
									{ feed.name }
								</span>
								<span className="min-w-0 truncate text-xs text-muted-foreground">
									{ feed.host }
								</span>
								<StatusBadge
									domain="sync"
									value={ feed.last_status }
								/>
							</div>
							{ feed.last_error ? (
								<p
									className={
										'error' === feed.last_status
											? 'm-0 text-xs text-destructive'
											: 'm-0 text-xs text-warning'
									}
								>
									{ feed.last_error }
								</p>
							) : null }
							<p className="m-0 text-xs text-muted-foreground">
								{ [
									intervalLabel( feed.interval_minutes ),
									feed.last_synced_at
										? sprintf(
												/* translators: %s: date and time. */
												__(
													'last read %s',
													'radius-hotel-booking-pro'
												),
												format.formatDateTime(
													feed.last_synced_at
												)
										  )
										: null,
									sprintf(
										/* translators: %d: blocked periods. */
										_n(
											'%d blocked period',
											'%d blocked periods',
											feed.blocks,
											'radius-hotel-booking-pro'
										),
										feed.blocks
									),
								]
									.filter( Boolean )
									.join( ' · ' ) }
							</p>
							<div className="flex flex-wrap gap-x-4 gap-y-1">
								<Button
									variant="link"
									className="h-auto p-0 text-xs"
									disabled={ reading === feed.id }
									onClick={ () => {
										setReading( feed.id );
										sync.mutateAsync( feed.id )
											.then( ( data ) =>
												announceSync( data.sync )
											)
											.catch( toastError )
											.finally( () => setReading( 0 ) );
									} }
								>
									<RefreshCw
										className="h-3.5 w-3.5"
										aria-hidden="true"
									/>
									{ reading === feed.id
										? __(
												'Reading…',
												'radius-hotel-booking-pro'
										  )
										: __(
												'Read now',
												'radius-hotel-booking-pro'
										  ) }
								</Button>
								<Button
									variant="link"
									className="h-auto p-0 text-xs"
									onClick={ () => onEdit( feed ) }
								>
									{ __(
										'Change',
										'radius-hotel-booking-pro'
									) }
								</Button>
								<Button
									variant="link"
									className="h-auto p-0 text-xs text-destructive"
									onClick={ () =>
										confirm( {
											title: sprintf(
												/* translators: %s: channel name. */
												__(
													'Stop reading %s?',
													'radius-hotel-booking-pro'
												),
												feed.name
											),
											description: sprintf(
												/* translators: %d: blocked periods. */
												_n(
													'Its %d blocked period is removed and those dates can be sold again.',
													'Its %d blocked periods are removed and those dates can be sold again.',
													feed.blocks,
													'radius-hotel-booking-pro'
												),
												feed.blocks
											),
											label: __(
												'Remove',
												'radius-hotel-booking-pro'
											),
											run: () =>
												remove.mutateAsync( feed.id ),
											done: __(
												'Channel calendar removed.',
												'radius-hotel-booking-pro'
											),
										} )
									}
								>
									{ __(
										'Remove',
										'radius-hotel-booking-pro'
									) }
								</Button>
							</div>
						</li>
					) ) }
				</ul>
			) : (
				<p className="m-0 text-xs text-muted-foreground">
					{ __(
						'Not reading any channel calendar.',
						'radius-hotel-booking-pro'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * @return {JSX.Element} Screen.
 */
export default function CalendarSyncScreen() {
	const feeds = useFeeds();
	const [ typeFilter, setTypeFilter ] = useState( 'all' );
	// `{ room, feed }` while the dialog is open.
	const [ editing, setEditing ] = useState( null );
	const [ asking, setAsking ] = useState( null );

	const rooms = feeds.data?.rooms || [];
	const types = useMemo( () => {
		const seen = new Map();
		rooms.forEach( ( room ) =>
			seen.set( String( room.room_type_id ), room.room_type )
		);
		return [ ...seen.entries() ];
	}, [ rooms ] );
	const shown =
		'all' === typeFilter
			? rooms
			: rooms.filter(
					( room ) => String( room.room_type_id ) === typeFilter
			  );

	let body;
	if ( feeds.isPending ) {
		body = <Skeleton className="h-64 w-full rounded-xl" />;
	} else if ( feeds.error ) {
		body = (
			<div role="alert" className="space-y-2">
				<p className="m-0 text-sm text-destructive">
					{ feeds.error.message }
				</p>
				<Button
					variant="outline"
					size="sm"
					onClick={ () => feeds.refetch() }
				>
					{ __( 'Try again', 'radius-hotel-booking-pro' ) }
				</Button>
			</div>
		);
	} else if ( ! rooms.length ) {
		body = (
			<EmptyState
				icon={ CalendarSync }
				title={ __( 'No rooms yet', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Add rooms first; each room shares and reads its own calendar.',
					'radius-hotel-booking-pro'
				) }
				action={
					<Button asChild>
						<Link to="/rooms">
							{ __( 'Go to Rooms', 'radius-hotel-booking-pro' ) }
						</Link>
					</Button>
				}
				className="border-0"
			/>
		);
	} else {
		body = (
			<div className="space-y-3">
				{ types.length > 1 ? (
					<Select
						value={ typeFilter }
						onValueChange={ setTypeFilter }
					>
						<SelectTrigger
							className="w-full sm:w-64"
							aria-label={ __(
								'Room type',
								'radius-hotel-booking-pro'
							) }
						>
							<SelectValue />
						</SelectTrigger>
						<SelectContent>
							<SelectItem value="all">
								{ __(
									'All room types',
									'radius-hotel-booking-pro'
								) }
							</SelectItem>
							{ types.map( ( [ id, name ] ) => (
								<SelectItem key={ id } value={ id }>
									{ name }
								</SelectItem>
							) ) }
						</SelectContent>
					</Select>
				) : null }
				<ul className="m-0 grid list-none gap-3 p-0 lg:grid-cols-2">
					{ shown.map( ( room ) => (
						<li
							key={ room.room_id }
							className="m-0 space-y-4 rounded-xl border border-border bg-card p-4"
						>
							<div>
								<p className="m-0 text-base font-semibold text-heading">
									{ sprintf(
										/* translators: %s: room number. */
										__(
											'Room %s',
											'radius-hotel-booking-pro'
										),
										room.number
									) }
								</p>
								<p className="m-0 text-xs text-muted-foreground">
									{ [ room.room_type, room.floor ]
										.filter( Boolean )
										.join( ' · ' ) }
								</p>
							</div>
							<ExportSection
								room={ room }
								confirm={ setAsking }
							/>
							<ImportsSection
								room={ room }
								onEdit={ ( feed ) =>
									setEditing( { room, feed } )
								}
								confirm={ setAsking }
							/>
						</li>
					) ) }
				</ul>
			</div>
		);
	}

	return (
		// Select, Dialog and ConfirmDialog parts load on demand (window.rtbp.ui).
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<div className="mx-auto max-w-5xl space-y-4">
				<Panel
					title={ __( 'Calendar sync', 'radius-hotel-booking-pro' ) }
					description={ __(
						'Share each room’s calendar with Booking.com, Airbnb and similar sites, and read theirs back as blocked dates, so a night is never sold twice.',
						'radius-hotel-booking-pro'
					) }
				>
					{ body }
				</Panel>

				{ editing ? (
					<ImportDialog
						room={ editing.room }
						feed={ editing.feed }
						intervals={ feeds.data?.intervals || [ 60 ] }
						onClose={ () => setEditing( null ) }
					/>
				) : null }

				<ConfirmDialog
					open={ Boolean( asking ) }
					onOpenChange={ ( open ) => ! open && setAsking( null ) }
					title={ asking?.title || '' }
					description={ asking?.description || '' }
					confirmLabel={ asking?.label }
					destructive
					onConfirm={ () =>
						asking.run().then( () => toast.success( asking.done ) )
					}
				/>
			</div>
		</Suspense>
	);
}

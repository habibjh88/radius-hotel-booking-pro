/**
 * Add or change a channel calendar of a room: its name (Booking.com,
 * Airbnb…), the calendar address the channel gives, and how often to read
 * it. Saving reads it once and says what came in; a read that fails still
 * keeps the calendar, with the reason.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	Input,
	Label,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
} from '@rtbp/ui';
import { toast, toastError } from '@rtbp/lib';

import { useSaveImport } from './api';
import { intervalLabel } from './labels';

const FIELDS = [ 'room_id', 'name', 'url', 'interval_minutes' ];

/**
 * Tell staff what the first read brought in.
 *
 * @param {Object|null} sync Sync result, or null when nothing was read.
 * @return {void}
 */
export function announceSync( sync ) {
	if ( ! sync ) {
		return;
	}
	if ( ! sync.ok ) {
		toast.error( sync.error, { duration: 10000 } );
		return;
	}
	toast.success(
		sprintf(
			/* translators: 1: blocked periods added, 2: changed, 3: removed. */
			__(
				'Calendar read: %1$d added, %2$d changed, %3$d removed.',
				'radius-hotel-booking-pro'
			),
			sync.created,
			sync.updated,
			sync.removed
		)
	);
	if ( sync.overlaps ) {
		toast.warning(
			sprintf(
				/* translators: %d: bookings overlapping the channel's dates. */
				_n(
					'%d booking in this room overlaps the channel’s dates: check for a double booking.',
					'%d bookings in this room overlap the channel’s dates: check for double bookings.',
					sync.overlaps,
					'radius-hotel-booking-pro'
				),
				sync.overlaps
			),
			{ duration: 10000 }
		);
	}
}

/**
 * @param {Object}      props           Props.
 * @param {Object}      props.room      `{ room_id, number }`.
 * @param {Object|null} props.feed      Feed to change, or null to add one.
 * @param {number[]}    props.intervals Allowed intervals (minutes).
 * @param {Function}    props.onClose   Close.
 * @return {JSX.Element} Dialog.
 */
export default function ImportDialog( { room, feed, intervals, onClose } ) {
	const [ values, setValues ] = useState( {
		name: feed?.name || '',
		url: feed?.url || '',
		interval: String( feed?.interval_minutes || 60 ),
	} );
	const [ errors, setErrors ] = useState( {} );
	const save = useSaveImport();

	const set = ( changes ) => {
		setValues( ( prev ) => ( { ...prev, ...changes } ) );
		setErrors( {} );
	};

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const result = await save.mutateAsync( {
				id: feed?.id,
				room_id: room.room_id,
				name: values.name,
				url: values.url,
				interval_minutes: Number( values.interval ),
			} );
			if ( ! result.sync ) {
				toast.success(
					__( 'Channel calendar saved.', 'radius-hotel-booking-pro' )
				);
			}
			announceSync( result.sync );
			onClose();
		} catch ( err ) {
			const mapped = {};
			FIELDS.forEach( ( field ) => {
				const message = err?.errors?.[ field ]?.first_message;
				if ( message ) {
					mapped[ field === 'room_id' ? 'url' : field ] = message;
				}
			} );
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	const errorText = ( field ) =>
		errors[ field ] ? (
			<p role="alert" className="m-0 text-sm text-destructive">
				{ errors[ field ] }
			</p>
		) : null;

	return (
		<Dialog open onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="max-h-[90vh] max-w-md overflow-y-auto">
				<form onSubmit={ submit } noValidate className="space-y-5">
					<DialogHeader>
						<DialogTitle>
							{ sprintf(
								/* translators: %s: room number. */
								feed
									? __(
											'Channel calendar of room %s',
											'radius-hotel-booking-pro'
									  )
									: __(
											'Read a channel calendar into room %s',
											'radius-hotel-booking-pro'
									  ),
								room.number
							) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'Dates booked on the channel become blocked dates here, so the room is not sold twice.',
								'radius-hotel-booking-pro'
							) }
						</DialogDescription>
					</DialogHeader>

					<div className="space-y-1.5">
						<Label
							htmlFor="rtbp-ical-name"
							className="text-sm font-semibold text-heading"
						>
							{ __( 'Channel', 'radius-hotel-booking-pro' ) }
						</Label>
						<Input
							id="rtbp-ical-name"
							value={ values.name }
							maxLength={ 100 }
							placeholder={ __(
								'Booking.com, Airbnb…',
								'radius-hotel-booking-pro'
							) }
							onChange={ ( e ) =>
								set( { name: e.target.value } )
							}
							aria-invalid={ errors.name ? true : undefined }
						/>
						{ errorText( 'name' ) }
					</div>

					<div className="space-y-1.5">
						<Label
							htmlFor="rtbp-ical-url"
							className="text-sm font-semibold text-heading"
						>
							{ __(
								'Calendar address',
								'radius-hotel-booking-pro'
							) }
						</Label>
						<Input
							id="rtbp-ical-url"
							type="url"
							inputMode="url"
							value={ values.url }
							placeholder="https://…/calendar.ics"
							onChange={ ( e ) => set( { url: e.target.value } ) }
							aria-invalid={ errors.url ? true : undefined }
							aria-describedby="rtbp-ical-url-help"
							className="font-mono text-xs"
						/>
						<p
							id="rtbp-ical-url-help"
							className="m-0 text-xs text-muted-foreground"
						>
							{ __(
								'Copy the “export calendar” or iCal link from the channel’s extranet for this room.',
								'radius-hotel-booking-pro'
							) }
						</p>
						{ errorText( 'url' ) }
					</div>

					<div className="space-y-1.5">
						<Label
							htmlFor="rtbp-ical-interval"
							className="text-sm font-semibold text-heading"
						>
							{ __( 'Read it', 'radius-hotel-booking-pro' ) }
						</Label>
						<Select
							value={ values.interval }
							onValueChange={ ( interval ) =>
								set( { interval } )
							}
						>
							<SelectTrigger id="rtbp-ical-interval">
								<SelectValue />
							</SelectTrigger>
							<SelectContent>
								{ intervals.map( ( minutes ) => (
									<SelectItem
										key={ minutes }
										value={ String( minutes ) }
									>
										{ intervalLabel( minutes ) }
									</SelectItem>
								) ) }
							</SelectContent>
						</Select>
						{ errorText( 'interval_minutes' ) }
					</div>

					<DialogFooter className="gap-2">
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button type="submit" disabled={ save.isPending }>
							{ save.isPending
								? __( 'Reading…', 'radius-hotel-booking-pro' )
								: __(
										'Save and read',
										'radius-hotel-booking-pro'
								  ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

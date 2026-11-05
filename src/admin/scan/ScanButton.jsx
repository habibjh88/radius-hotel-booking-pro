/**
 * *Scan a booking* in the top bar (feature `booking_qr`), for whoever may
 * open bookings. The button opens the scan dialog (camera, or a handheld
 * scanner typing into its field).
 *
 * A handheld scanner also works with nothing focused: it "types" the code's
 * link much faster than a person and ends with Enter, so a fast burst ending
 * in a booking link opens that booking straight away. Normal typing and
 * keyboard shortcuts are never caught (only a burst holding the link counts).
 *
 * Hidden on phones (below 640 px): the top bar has no room, and a phone's own
 * camera app opens the code's link straight to the booking. The scanner
 * burst still works there.
 */
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { __ } from '@wordpress/i18n';
import { ScanLine } from 'lucide-react';
import { access } from '@rtbp/lib';

import { openScanned } from './resolve';

const ScanDialog = lazy( () => import( './ScanDialog' ) );

/** Longest pause between two keys of a scanner burst, in ms. */
const BURST_GAP = 60;

/** What a booking QR link looks like. */
const LINK = /[?&](rtbp_scan|rtbp_booking)=[a-f0-9]{32}/i;

/**
 * Whether a key goes to a field (then it is the field's, not ours).
 *
 * @param {EventTarget} target Event target.
 * @return {boolean} Editable.
 */
const isEditable = ( target ) =>
	!! target &&
	( target.isContentEditable ||
		[ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( target.tagName ) );

/**
 * @return {JSX.Element|null} The button and its dialog.
 */
export default function ScanButton() {
	const [ open, setOpen ] = useState( false );
	const navigate = useNavigate();
	const burst = useRef( { text: '', at: 0 } );
	const allowed = 'locked' !== access?.getAccessLevel?.( 'page.bookings' );

	useEffect( () => {
		if ( ! allowed ) {
			return undefined;
		}
		const onKey = ( event ) => {
			if (
				open ||
				event.ctrlKey ||
				event.metaKey ||
				event.altKey ||
				isEditable( event.target )
			) {
				return;
			}
			const now = Date.now();
			const state = burst.current;
			if ( now - state.at > BURST_GAP ) {
				state.text = '';
			}
			state.at = now;
			if ( 'Enter' === event.key ) {
				const text = state.text;
				state.text = '';
				if ( LINK.test( text ) ) {
					event.preventDefault();
					openScanned( text, navigate );
				}
				return;
			}
			if ( 1 === event.key.length ) {
				state.text += event.key;
			}
		};
		document.addEventListener( 'keydown', onKey, true );
		return () => document.removeEventListener( 'keydown', onKey, true );
	}, [ allowed, open, navigate ] );

	if ( ! allowed ) {
		return null;
	}

	return (
		<>
			<button
				type="button"
				onClick={ () => setOpen( true ) }
				className="hidden h-10 shrink-0 items-center sm:flex gap-2 rounded-lg border border-border bg-card px-3 text-[13px] font-medium text-heading transition-colors hover:border-primary"
				aria-label={ __(
					'Scan a booking',
					'radius-hotel-booking-pro'
				) }
				title={ __(
					'Scan a booking QR code',
					'radius-hotel-booking-pro'
				) }
			>
				<ScanLine className="h-4 w-4 text-primary" aria-hidden="true" />
				<span className="hidden md:inline">
					{ __( 'Scan', 'radius-hotel-booking-pro' ) }
				</span>
			</button>
			{ open ? (
				<Suspense fallback={ null }>
					<ScanDialog open={ open } onOpenChange={ setOpen } />
				</Suspense>
			) : null }
		</>
	);
}

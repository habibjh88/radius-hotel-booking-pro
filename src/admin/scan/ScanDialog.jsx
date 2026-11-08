/**
 * Scan a booking: the guest shows the QR code from their e-mail, invoice or
 * receipt, and the booking opens.
 *
 * - The field has focus, so a handheld (USB or Bluetooth) scanner just
 *   "types" the code into it and presses Enter. Staff can also type a
 *   booking reference.
 * - *Use the camera* reads the code with the device's camera: the browser's
 *   own BarcodeDetector where there is one, else jsQR (loaded only then).
 *   Browsers allow the camera only on https.
 */
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { __ } from '@wordpress/i18n';
import { Camera, CameraOff } from 'lucide-react';
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
} from '@rtbp/ui';

import { openScanned } from './resolve';

/** Time between two reads of the camera picture, in ms. */
const READ_EVERY = 200;

/** How long the same unknown code is ignored, in ms (no toast every frame). */
const SAME_CODE_PAUSE = 3000;

/**
 * A function that reads a QR code from the video, or null when it cannot.
 *
 * @param {HTMLVideoElement} video Video.
 * @return {Promise<Function>} `() => Promise<string|null>`.
 */
async function makeReader( video ) {
	if ( 'BarcodeDetector' in window ) {
		try {
			const formats = await window.BarcodeDetector.getSupportedFormats();
			if ( formats.includes( 'qr_code' ) ) {
				const detector = new window.BarcodeDetector( {
					formats: [ 'qr_code' ],
				} );
				return async () => {
					const codes = await detector.detect( video );
					return codes[ 0 ]?.rawValue || null;
				};
			}
		} catch ( e ) {
			// Fall through to jsQR.
		}
	}
	const { default: jsQR } = await import( 'jsqr' );
	const canvas = document.createElement( 'canvas' );
	const context = canvas.getContext( '2d', { willReadFrequently: true } );
	return async () => {
		const width = video.videoWidth;
		const height = video.videoHeight;
		if ( ! width || ! height ) {
			return null;
		}
		canvas.width = width;
		canvas.height = height;
		context.drawImage( video, 0, 0, width, height );
		const image = context.getImageData( 0, 0, width, height );
		return (
			jsQR( image.data, width, height, {
				inversionAttempts: 'dontInvert',
			} )?.data || null
		);
	};
}

/**
 * @param {Object}   props              Props.
 * @param {boolean}  props.open         Open.
 * @param {Function} props.onOpenChange Open state setter.
 * @return {JSX.Element} Dialog.
 */
export default function ScanDialog( { open, onOpenChange } ) {
	const navigate = useNavigate();
	const [ code, setCode ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ camera, setCamera ] = useState( 'off' ); // off | starting | on | error
	const [ cameraError, setCameraError ] = useState( '' );
	const video = useRef( null );
	const stream = useRef( null );
	const lastMiss = useRef( { code: '', at: 0 } );

	const stopCamera = () => {
		stream.current?.getTracks().forEach( ( track ) => track.stop() );
		stream.current = null;
	};

	// Stop the camera when the dialog goes.
	useEffect( () => stopCamera, [] );

	const open_ = async ( text ) => {
		if ( busy || ! text.trim() ) {
			return false;
		}
		setBusy( true );
		const ok = await openScanned( text, navigate );
		setBusy( false );
		if ( ok ) {
			stopCamera();
			onOpenChange( false );
		}
		return ok;
	};

	// Read the camera picture while it is on.
	useEffect( () => {
		if ( 'on' !== camera ) {
			return undefined;
		}
		let stopped = false;
		let timer = null;
		let read = null;
		const tick = async () => {
			if ( stopped ) {
				return;
			}
			try {
				read = read || ( await makeReader( video.current ) );
				const text = await read();
				const miss = lastMiss.current;
				const repeat =
					text === miss.code &&
					Date.now() - miss.at < SAME_CODE_PAUSE;
				if ( text && ! repeat && ! stopped ) {
					const ok = await open_( text );
					if ( ok ) {
						return;
					}
					lastMiss.current = { code: text, at: Date.now() };
				}
			} catch ( e ) {
				// A frame that cannot be read: try the next one.
			}
			timer = window.setTimeout( tick, READ_EVERY );
		};
		tick();
		return () => {
			stopped = true;
			window.clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- runs once per camera start.
	}, [ camera ] );

	const startCamera = async () => {
		setCameraError( '' );
		if (
			! window.isSecureContext ||
			! navigator.mediaDevices?.getUserMedia
		) {
			setCamera( 'error' );
			setCameraError(
				__(
					'The camera works only on a secure (https) address. Use a handheld scanner or type the reference.',
					'radius-hotel-booking-pro'
				)
			);
			return;
		}
		setCamera( 'starting' );
		try {
			stream.current = await navigator.mediaDevices.getUserMedia( {
				video: { facingMode: { ideal: 'environment' } },
				audio: false,
			} );
			video.current.srcObject = stream.current;
			await video.current.play();
			setCamera( 'on' );
		} catch ( error ) {
			stopCamera();
			setCamera( 'error' );
			setCameraError(
				error?.name === 'NotAllowedError'
					? __(
							'The browser was not allowed to use the camera. Allow it in the address bar, then try again.',
							'radius-hotel-booking-pro'
					  )
					: __(
							'No camera could be started on this device.',
							'radius-hotel-booking-pro'
					  )
			);
		}
	};

	const turnOff = () => {
		stopCamera();
		setCamera( 'off' );
	};

	const showVideo = 'on' === camera || 'starting' === camera;

	return (
		<Dialog
			open={ open }
			onOpenChange={ ( next ) => {
				if ( ! next ) {
					stopCamera();
				}
				onOpenChange( next );
			} }
		>
			<DialogContent className="rtbp-root max-w-md">
				<DialogHeader>
					<DialogTitle>
						{ __( 'Scan a booking', 'radius-hotel-booking-pro' ) }
					</DialogTitle>
					<DialogDescription>
						{ __(
							'Scan the QR code on the guest’s e-mail, invoice or receipt to open the booking.',
							'radius-hotel-booking-pro'
						) }
					</DialogDescription>
				</DialogHeader>

				<div
					className={
						showVideo
							? 'relative overflow-hidden rounded-lg border border-border bg-black'
							: 'hidden'
					}
				>
					<video
						ref={ video }
						className="block aspect-square w-full object-cover"
						muted
						playsInline
					/>
					<div
						className="pointer-events-none absolute rounded-lg border-2 border-white"
						// Inline: the free stylesheet has no inset-[15%] (add-ons are not scanned).
						style={ { inset: '15%' } }
						aria-hidden="true"
					/>
				</div>
				{ cameraError ? (
					<p className="m-0 rounded-md bg-warning-soft px-3 py-2 text-[13px] text-warning">
						{ cameraError }
					</p>
				) : null }

				<form
					className="space-y-2"
					onSubmit={ async ( event ) => {
						event.preventDefault();
						if ( await open_( code ) ) {
							setCode( '' );
						}
					} }
				>
					<Label htmlFor="rtbp-pro-scan-code">
						{ __(
							'Scanner or booking reference',
							'radius-hotel-booking-pro'
						) }
					</Label>
					<div className="flex gap-2">
						<Input
							id="rtbp-pro-scan-code"
							autoFocus
							autoComplete="off"
							value={ code }
							onChange={ ( event ) =>
								setCode( event.target.value )
							}
							placeholder={ __(
								'Scan now, or type the booking reference',
								'radius-hotel-booking-pro'
							) }
						/>
						<Button
							type="submit"
							disabled={ busy || ! code.trim() }
						>
							{ __( 'Open', 'radius-hotel-booking-pro' ) }
						</Button>
					</div>
				</form>

				<DialogFooter>
					{ showVideo ? (
						<Button
							type="button"
							variant="outline"
							onClick={ turnOff }
						>
							<CameraOff
								className="mr-2 h-4 w-4"
								aria-hidden="true"
							/>
							{ __(
								'Stop the camera',
								'radius-hotel-booking-pro'
							) }
						</Button>
					) : (
						<Button
							type="button"
							variant="outline"
							onClick={ startCamera }
						>
							<Camera
								className="mr-2 h-4 w-4"
								aria-hidden="true"
							/>
							{ __(
								'Use the camera',
								'radius-hotel-booking-pro'
							) }
						</Button>
					) }
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
}

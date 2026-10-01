/**
 * Archive and remove (M11 T3b, 11.4): a panel of the free Exports screen
 * (`rtbp.exports.panels`). Writes the period's booking export, verifies it,
 * then removes the period's finished bookings from the live database —
 * everything of theirs, invoices included. Hidden unless
 * `exports.archive_remove` is usable (locked by default, even for managers).
 */
import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { Archive, TriangleAlert } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	DateRangePicker,
	Field,
	Panel,
	SegmentedControl,
} from '@rtbp/ui';
import { access, api, format, toast, toastError } from '@rtbp/lib';

/**
 * The last day of last month, and the first of the month before (a typical
 * archive: a closed month).
 *
 * @return {Object} `{ from, to }`.
 */
const lastMonth = () => {
	const today = format.siteToday();
	const first = `${ today.slice( 0, 8 ) }01`;
	const to = format.addDaysYmd( first, -1 );
	return { from: `${ to.slice( 0, 8 ) }01`, to };
};

/**
 * @param {Object} props         Props from the Exports screen.
 * @param {Array}  props.formats Streamed formats `[ { key, label } ]`.
 * @return {JSX.Element|null} Panel.
 */
export default function ArchivePanel( { formats = [] } ) {
	const level = access.useAccess( 'exports.archive_remove' );
	const client = useQueryClient();
	const [ form, setForm ] = useState( () => ( {
		...lastMonth(),
		mode: 'arrival',
		format: 'csv',
	} ) );
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );

	if ( 'locked' === level ) {
		return null;
	}
	const set = ( changes ) =>
		setForm( ( prev ) => ( { ...prev, ...changes } ) );

	const run = ( reason ) => {
		setBusy( true );
		return api
			.post( 'exports/archive', { ...form, reason } )
			.then( ( { data } ) => {
				setResult( data );
				setConfirming( false );
				client.invalidateQueries( { queryKey: [ 'exports' ] } );
				toast.success(
					sprintf(
						/* translators: 1: bookings removed, 2: bookings kept. */
						__(
							'Archived: %1$d bookings removed, %2$d kept.',
							'radius-hotel-booking-pro'
						),
						data.removed,
						data.kept
					)
				);
			} )
			.catch( ( err ) => {
				toastError( err );
				throw err;
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<Panel
			title={ __( 'Archive and remove', 'radius-hotel-booking-pro' ) }
			description={ __(
				'Make the booking file for a past period, check it, then remove those finished bookings from the live system.',
				'radius-hotel-booking-pro'
			) }
		>
			<div className="space-y-4">
				<div className="flex gap-3 rounded-lg border border-destructive bg-destructive-soft p-3 text-sm text-destructive">
					<TriangleAlert
						className="mt-0.5 h-4 w-4 shrink-0"
						aria-hidden="true"
					/>
					<p className="m-0">
						{ __(
							'Removed bookings disappear for good, with their payments, invoices and notes. Only checked-out, cancelled, declined and no-show stays go; future and in-house stays are kept. Keep the archive file and a server backup for as long as the law requires.',
							'radius-hotel-booking-pro'
						) }
					</p>
				</div>
				<div className="grid gap-4 md:grid-cols-2">
					<Field label={ __( 'Period', 'radius-hotel-booking-pro' ) }>
						<div>
							<DateRangePicker
								value={ { from: form.from, to: form.to } }
								onChange={ ( next ) =>
									set( {
										from: next?.from || form.from,
										to: next?.to || form.to,
									} )
								}
								mode={ form.mode }
								onModeChange={ ( mode ) => set( { mode } ) }
							/>
						</div>
					</Field>
					<Field
						label={ __(
							'File format',
							'radius-hotel-booking-pro'
						) }
					>
						<div>
							<SegmentedControl
								options={ ( formats.length
									? formats
									: [ { key: 'csv', label: 'CSV' } ]
								).map( ( item ) => ( {
									value: item.key,
									label: item.label,
								} ) ) }
								value={ form.format }
								onChange={ ( value ) =>
									set( { format: value } )
								}
								label={ __(
									'File format',
									'radius-hotel-booking-pro'
								) }
							/>
						</div>
					</Field>
				</div>
				<Button
					type="button"
					variant="destructive"
					disabled={ busy }
					onClick={ () => setConfirming( true ) }
				>
					<Archive aria-hidden="true" />
					{ __( 'Archive and remove…', 'radius-hotel-booking-pro' ) }
				</Button>
				{ result ? (
					<p
						className="m-0 text-sm text-muted-foreground"
						role="status"
					>
						{ sprintf(
							/* translators: 1: removed, 2: kept, 3: first reference, 4: last reference, 5: file name. */
							__(
								'%1$d bookings removed (%3$s to %4$s), %2$d kept. The file %5$s is in the library.',
								'radius-hotel-booking-pro'
							),
							result.removed,
							result.kept,
							result.first_reference || '—',
							result.last_reference || '—',
							result.export?.file?.name || ''
						) }
					</p>
				) : null }
			</div>

			<ConfirmDialog
				open={ confirming }
				onOpenChange={ setConfirming }
				title={ __(
					'Archive and remove these bookings?',
					'radius-hotel-booking-pro'
				) }
				description={ sprintf(
					/* translators: 1: first day, 2: last day. */
					__(
						'The finished bookings from %1$s to %2$s will be written to a file, checked, then removed for good with their payments and invoices.',
						'radius-hotel-booking-pro'
					),
					form.from,
					form.to
				) }
				confirmLabel={ __(
					'Archive and remove',
					'radius-hotel-booking-pro'
				) }
				destructive
				requireReason
				reasonLabel={ __(
					'Why are they being removed?',
					'radius-hotel-booking-pro'
				) }
				reasonMinLength={ 3 }
				onConfirm={ run }
			/>
		</Panel>
	);
}

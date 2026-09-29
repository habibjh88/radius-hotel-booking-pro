/**
 * One log entry in full: before/after values, record, IP and device.
 */
import { Suspense } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	Badge,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogHeader,
	DialogTitle,
} from '@rtbp/ui';
import { format } from '@rtbp/lib';

/**
 * A value as text.
 *
 * @param {*} value Value.
 * @return {string} Text.
 */
const show = ( value ) => {
	if ( value === null || value === undefined || value === '' ) {
		return '—';
	}
	if ( typeof value === 'boolean' ) {
		return value
			? __( 'Yes', 'radius-hotel-booking-pro' )
			: __( 'No', 'radius-hotel-booking-pro' );
	}
	return typeof value === 'object' ? JSON.stringify( value ) : String( value );
};

/**
 * @param {Object}   props         Props.
 * @param {Object}   props.entry   Row from `GET activity`.
 * @param {Function} props.onClose Close.
 * @return {JSX.Element} Dialog.
 */
export default function EntryDialog( { entry, onClose } ) {
	const before = entry.changes?.before ?? {};
	const after = entry.changes?.after ?? {};
	const fields = [ ...new Set( [ ...Object.keys( before ), ...Object.keys( after ) ] ) ];
	const meta = Object.entries( entry.meta ?? {} );

	const facts = [
		[ __( 'Who', 'radius-hotel-booking-pro' ), entry.user.name || __( 'Nobody signed in', 'radius-hotel-booking-pro' ) ],
		[ __( 'When', 'radius-hotel-booking-pro' ), format.formatDateTime( entry.time ) ],
		[ __( 'Record', 'radius-hotel-booking-pro' ), entry.subject ? entry.subject.label || `${ entry.subject.type } #${ entry.subject.id }` : '—' ],
		[ __( 'IP address', 'radius-hotel-booking-pro' ), entry.ip || '—' ],
		[ __( 'Device', 'radius-hotel-booking-pro' ), entry.device || '—' ],
	];
	if ( entry.repeat_count > 1 && entry.action === 'page.view' ) {
		facts.push( [
			__( 'Repeated', 'radius-hotel-booking-pro' ),
			sprintf(
				/* translators: 1: number of times, 2: date and time of the last one. */
				__( '%1$d times, last %2$s', 'radius-hotel-booking-pro' ),
				entry.repeat_count,
				format.formatDateTime( entry.last_seen )
			),
		] );
	}

	return (
		<Suspense fallback={ null }>
			<Dialog open onOpenChange={ ( open ) => ! open && onClose() }>
				<DialogContent className="rtbp-root">
					<DialogHeader>
						<DialogTitle>{ entry.action_label }</DialogTitle>
						<DialogDescription>{ entry.description }</DialogDescription>
					</DialogHeader>

					<dl className="m-0 space-y-1.5 text-sm">
						{ facts.map( ( [ label, value ] ) => (
							<div key={ label } className="flex gap-4">
								<dt className="w-28 shrink-0 text-muted-foreground">{ label }</dt>
								<dd className="m-0 min-w-0 break-words text-heading">{ value }</dd>
							</div>
						) ) }
					</dl>

					{ fields.length ? (
						<div className="space-y-2">
							<h3 className="m-0 text-sm font-semibold text-heading">
								{ __( 'What changed', 'radius-hotel-booking-pro' ) }
							</h3>
							<div className="max-h-[300px] overflow-auto rounded-md border border-border">
								<table className="w-full text-left text-sm">
									<thead className="bg-muted text-xs text-muted-foreground">
										<tr>
											<th className="px-3 py-2 font-medium">{ __( 'Field', 'radius-hotel-booking-pro' ) }</th>
											<th className="px-3 py-2 font-medium">{ __( 'Before', 'radius-hotel-booking-pro' ) }</th>
											<th className="px-3 py-2 font-medium">{ __( 'After', 'radius-hotel-booking-pro' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ fields.map( ( field ) => (
											<tr key={ field } className="border-t border-border">
												<td className="px-3 py-2 text-muted-foreground">{ field }</td>
												<td className="break-words px-3 py-2">{ show( before[ field ] ) }</td>
												<td className="break-words px-3 py-2 font-medium text-heading">{ show( after[ field ] ) }</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</div>
						</div>
					) : null }

					{ meta.length ? (
						<div className="flex flex-wrap gap-1.5">
							{ meta.map( ( [ key, value ] ) => (
								<Badge key={ key } variant="outline">
									{ `${ key }: ${ show( value ) }` }
								</Badge>
							) ) }
						</div>
					) : null }
				</DialogContent>
			</Dialog>
		</Suspense>
	);
}

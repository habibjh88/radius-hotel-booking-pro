/**
 * Ask before removing a payroll figure (a contribution, a tax bracket, a
 * bonus): the person types DELETE to confirm, since these rows decide what
 * everyone is paid.
 *
 *   const [ confirmRemove, removeDialog ] = useConfirmRemove();
 *   onClick={ () => confirmRemove( name, () => remove( index ) ) }
 *   …
 *   { removeDialog }
 */
import { Suspense, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ConfirmDialog } from '@rtbp/ui';

/**
 * The word typed to confirm a deletion (for a ConfirmDialog's `confirmText`).
 *
 * @return {string} Word.
 */
export const deleteWord = () =>
	// translators: the word typed to confirm a deletion; one short word in capitals.
	__( 'DELETE', 'radius-hotel-booking-pro' );

/**
 * @return {Array} `[ confirmRemove( name, onConfirm ), dialog ]`.
 */
export default function useConfirmRemove() {
	const [ pending, setPending ] = useState( null );

	const confirmRemove = ( name, onConfirm ) =>
		setPending( { name, onConfirm } );

	const dialog = pending ? (
		// Its own boundary: the dialog loads lazily without blanking the page.
		<Suspense fallback={ null }>
			<ConfirmDialog
				open
				onOpenChange={ ( next ) => ! next && setPending( null ) }
				destructive
				title={ sprintf(
					/* translators: %s: what is removed, e.g. a contribution name. */
					__( 'Delete “%s”?', 'radius-hotel-booking-pro' ),
					pending.name
				) }
				description={ __(
					'It decides what employees are paid. It goes from the list now and for good once you save the settings.',
					'radius-hotel-booking-pro'
				) }
				confirmLabel={ __( 'Delete', 'radius-hotel-booking-pro' ) }
				cancelLabel={ __( 'Keep it', 'radius-hotel-booking-pro' ) }
				confirmText={ deleteWord() }
				onConfirm={ () => pending.onConfirm() }
			/>
		</Suspense>
	) : null;

	return [ confirmRemove, dialog ];
}

/**
 * An employee's photo: the image chosen from the media library, else their
 * account's Gravatar (`avatar_url` from the API). On the staff file, people
 * who may edit it change or remove it through the WordPress media library.
 */
import { useRef } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Camera, Trash2 } from 'lucide-react';
import { Button } from '@rtbp/ui';
import { toast, toastError } from '@rtbp/lib';

import { useUpdateEmployee } from './api';

/**
 * The round photo.
 *
 * @param {Object} props          Props.
 * @param {Object} props.employee `{ name, avatar_url }`.
 * @param {string} props.size     Tailwind size classes.
 * @return {JSX.Element} Photo.
 */
export function StaffAvatar( { employee, size = 'h-9 w-9' } ) {
	return employee.avatar_url ? (
		<img
			src={ employee.avatar_url }
			alt=""
			loading="lazy"
			className={ `${ size } shrink-0 rounded-full border border-border bg-muted object-cover` }
		/>
	) : (
		<span
			className={ `${ size } shrink-0 rounded-full border border-border bg-muted` }
			aria-hidden="true"
		/>
	);
}

/**
 * The photo with Change / Remove, for the staff file.
 *
 * @param {Object}  props          Props.
 * @param {Object}  props.employee Employee (`id`, `name`, `avatar_id`, `avatar_url`).
 * @param {boolean} props.canEdit  May change it.
 * @return {JSX.Element} Control.
 */
export default function StaffPhoto( { employee, canEdit } ) {
	const update = useUpdateEmployee( employee.id );
	const frame = useRef( null );
	const media = window.wp?.media;

	const save = ( avatarId, message ) =>
		update
			.mutateAsync( { avatar_id: avatarId } )
			.then( () => toast.success( message ) )
			.catch( toastError );

	const choose = () => {
		if ( ! frame.current ) {
			frame.current = media( {
				title: sprintf(
					/* translators: %s: employee name. */
					__( 'Photo of %s', 'radius-hotel-booking-pro' ),
					employee.name
				),
				library: { type: 'image' },
				button: {
					text: __( 'Use this photo', 'radius-hotel-booking-pro' ),
				},
				multiple: false,
			} );
			frame.current.on( 'select', () => {
				const picked = frame.current
					.state()
					.get( 'selection' )
					.first()
					?.toJSON();
				if ( picked?.id ) {
					save(
						picked.id,
						__( 'Photo saved.', 'radius-hotel-booking-pro' )
					);
				}
			} );
		}
		frame.current.open();
	};

	return (
		<div className="flex shrink-0 flex-col items-center gap-1.5">
			<StaffAvatar employee={ employee } size="h-16 w-16" />
			{ canEdit && media ? (
				<div className="flex items-center gap-1">
					<Button
						type="button"
						variant="ghost"
						size="sm"
						className="h-7 px-2 text-xs"
						disabled={ update.isPending }
						onClick={ choose }
					>
						<Camera className="h-3.5 w-3.5" aria-hidden="true" />
						{ employee.avatar_id
							? __( 'Change', 'radius-hotel-booking-pro' )
							: __( 'Add photo', 'radius-hotel-booking-pro' ) }
					</Button>
					{ employee.avatar_id ? (
						<Button
							type="button"
							variant="ghost"
							size="sm"
							className="h-7 px-2 text-xs text-muted-foreground hover:text-destructive"
							disabled={ update.isPending }
							onClick={ () =>
								save(
									0,
									__(
										'Photo removed.',
										'radius-hotel-booking-pro'
									)
								)
							}
							aria-label={ __(
								'Remove the photo',
								'radius-hotel-booking-pro'
							) }
						>
							<Trash2
								className="h-3.5 w-3.5"
								aria-hidden="true"
							/>
						</Button>
					) : null }
				</div>
			) : null }
		</div>
	);
}

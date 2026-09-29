/**
 * Staff access (M13 T5b): who has which access role, personal overrides and
 * a PIN; one person at `?user=<id>`.
 */
import { Suspense, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { UserCog } from 'lucide-react';
import { useSearchParams } from 'react-router-dom';
import { Badge, Button, ConfirmDialog, EmptyState, Panel } from '@rtbp/ui';
import { usePageActions } from '@rtbp/lib';

import StaffAccess from './StaffAccess';
import { LoadError, ListSkeleton } from './RolesScreen';
import { useResetOverrides, useUsers } from './api';

/**
 * @return {JSX.Element} Screen.
 */
export default function StaffScreen() {
	const [ params, setParams ] = useSearchParams();
	const users = useUsers();
	const reset = useResetOverrides();
	const [ confirmAll, setConfirmAll ] = useState( false );

	const selected = Number( params.get( 'user' ) ) || 0;
	const anyOverrides = ( users.data ?? [] ).some( ( user ) => user.overrides );

	usePageActions(
		! selected && anyOverrides ? (
			<Button size="sm" variant="outline" onClick={ () => setConfirmAll( true ) }>
				{ __( 'Reset all overrides', 'radius-hotel-booking-pro' ) }
			</Button>
		) : null,
		[ selected, anyOverrides ]
	);

	if ( selected ) {
		return (
			<StaffAccess
				key={ selected }
				userId={ selected }
				onBack={ () => setParams( {} ) }
			/>
		);
	}
	if ( users.error ) {
		return <LoadError error={ users.error } onRetry={ users.refetch } />;
	}
	if ( users.isPending ) {
		return <ListSkeleton />;
	}

	return (
		<Panel
			title={ __( 'Staff access', 'radius-hotel-booking-pro' ) }
			description={ __(
				'Give each person an access role, a PIN, and single exceptions where needed.',
				'radius-hotel-booking-pro'
			) }
		>
			{ users.data.length ? (
				<ul className="m-0 list-none divide-y divide-border p-0">
					{ users.data.map( ( user ) => (
						<li
							key={ user.id }
							className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center"
						>
							<div className="min-w-0 flex-1">
								<div className="text-sm font-semibold text-heading">
									{ user.name }
								</div>
								<div className="truncate text-xs text-muted-foreground">
									{ user.email }
								</div>
								<div className="mt-1 flex flex-wrap gap-1.5">
									<Badge variant="outline">
										{ user.access_role?.name ??
											__( 'No access role', 'radius-hotel-booking-pro' ) }
									</Badge>
									{ user.overrides ? (
										<Badge variant="outline">
											{ sprintf(
												/* translators: %d: number of overrides. */
												_n(
													'%d override',
													'%d overrides',
													user.overrides,
													'radius-hotel-booking-pro'
												),
												user.overrides
											) }
										</Badge>
									) : null }
									<Badge variant="outline">
										{ user.has_pin
											? __( 'PIN set', 'radius-hotel-booking-pro' )
											: __( 'No PIN', 'radius-hotel-booking-pro' ) }
									</Badge>
								</div>
							</div>
							<Button
								size="sm"
								variant="outline"
								className="shrink-0"
								onClick={ () =>
									setParams( { user: String( user.id ) } )
								}
							>
								{ __( 'Manage', 'radius-hotel-booking-pro' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) : (
				<EmptyState
					icon={ UserCog }
					title={ __( 'No staff yet', 'radius-hotel-booking-pro' ) }
					description={ __(
						'People with the Manager or Staff role appear here.',
						'radius-hotel-booking-pro'
					) }
				/>
			) }

			<Suspense fallback={ null }>
				{ confirmAll ? (
					<ConfirmDialog
						open
						onOpenChange={ ( next ) => ! next && setConfirmAll( false ) }
						destructive
						title={ __(
							'Reset everyone’s personal overrides?',
							'radius-hotel-booking-pro'
						) }
						description={ __(
							'Every personal override is removed. Access roles stay; everyone gets their role and the defaults again.',
							'radius-hotel-booking-pro'
						) }
						confirmLabel={ __( 'Reset all', 'radius-hotel-booking-pro' ) }
						onConfirm={ () =>
							reset
								.mutateAsync( null )
								.then( () => setConfirmAll( false ) )
								.catch( () => {} )
						}
					/>
				) : null }
			</Suspense>
		</Panel>
	);
}

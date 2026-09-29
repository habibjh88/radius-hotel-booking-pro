/**
 * Access roles (M13 T5b, features 13.13, 13.14): the list, and the editor at
 * `?role=<id|new>`.
 */
import { Suspense, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Plus, ShieldHalf } from 'lucide-react';
import { useSearchParams } from 'react-router-dom';
import {
	Badge,
	Button,
	ConfirmDialog,
	EmptyState,
	Panel,
	Skeleton,
} from '@rtbp/ui';
import { usePageActions } from '@rtbp/lib';

import RoleEditor from './RoleEditor';
import { useDeleteRole, useRegistry, useRoles } from './api';

/**
 * @param {Object}   props         Props.
 * @param {Object}   props.error   Query error.
 * @param {Function} props.onRetry Retry.
 * @return {JSX.Element} Error line.
 */
export function LoadError( { error, onRetry } ) {
	return (
		<div
			role="alert"
			className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-destructive-soft p-4 text-sm text-destructive"
		>
			<span>{ error.message }</span>
			<Button variant="outline" size="sm" onClick={ onRetry }>
				{ __( 'Try again', 'radius-hotel-booking-pro' ) }
			</Button>
		</div>
	);
}

/**
 * @return {JSX.Element} Placeholder.
 */
export function ListSkeleton() {
	return (
		<div className="space-y-3" role="status" aria-live="polite">
			{ [ 0, 1, 2, 3 ].map( ( i ) => (
				<Skeleton key={ i } className="h-16 w-full rounded-xl" />
			) ) }
		</div>
	);
}

/**
 * @return {JSX.Element} Screen.
 */
export default function RolesScreen() {
	const [ params, setParams ] = useSearchParams();
	const roles = useRoles();
	const registry = useRegistry();
	const remove = useDeleteRole();
	const [ deleting, setDeleting ] = useState( null );

	const selected = params.get( 'role' );
	const open = ( id ) => setParams( id ? { role: String( id ) } : {} );

	usePageActions(
		selected ? null : (
			<Button size="sm" onClick={ () => open( 'new' ) }>
				<Plus aria-hidden="true" />
				{ __( 'New role', 'radius-hotel-booking-pro' ) }
			</Button>
		),
		[ selected ]
	);

	if ( roles.error || registry.error ) {
		return (
			<LoadError
				error={ roles.error || registry.error }
				onRetry={ () => {
					roles.refetch();
					registry.refetch();
				} }
			/>
		);
	}
	if ( roles.isPending || registry.isPending ) {
		return <ListSkeleton />;
	}

	if ( selected ) {
		const role =
			'new' === selected
				? null
				: roles.data.find( ( item ) => String( item.id ) === selected );
		return (
			<RoleEditor
				key={ selected }
				role={ role }
				registry={ registry.data }
				onDone={ () => open( null ) }
			/>
		);
	}

	return (
		<Panel
			title={ __( 'Access roles', 'radius-hotel-booking-pro' ) }
			description={ __(
				'Set up a role once and assign it to each new member of staff. Changing a role changes everyone who has it.',
				'radius-hotel-booking-pro'
			) }
		>
			{ roles.data.length ? (
				<ul className="m-0 list-none divide-y divide-border p-0">
					{ roles.data.map( ( role ) => (
						<li
							key={ role.id }
							className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center"
						>
							<div className="min-w-0 flex-1">
								<div className="flex flex-wrap items-center gap-2">
									<span className="text-sm font-semibold text-heading">
										{ role.name }
									</span>
									{ role.is_template ? (
										<Badge variant="outline">
											{ __(
												'Template',
												'radius-hotel-booking-pro'
											) }
										</Badge>
									) : null }
									<span className="text-xs text-muted-foreground">
										{ sprintf(
											/* translators: %d: number of staff. */
											_n(
												'%d person',
												'%d people',
												role.users,
												'radius-hotel-booking-pro'
											),
											role.users
										) }
									</span>
								</div>
								{ role.description ? (
									<p className="m-0 mt-0.5 text-[13px] text-muted-foreground">
										{ role.description }
									</p>
								) : null }
							</div>
							<div className="flex shrink-0 gap-2">
								<Button
									size="sm"
									variant="outline"
									onClick={ () => open( role.id ) }
								>
									{ __( 'Edit', 'radius-hotel-booking-pro' ) }
								</Button>
								{ role.is_template ? null : (
									<Button
										size="sm"
										variant="ghost"
										onClick={ () => setDeleting( role ) }
									>
										{ __(
											'Delete',
											'radius-hotel-booking-pro'
										) }
									</Button>
								) }
							</div>
						</li>
					) ) }
				</ul>
			) : (
				<EmptyState
					icon={ ShieldHalf }
					title={ __( 'No access roles yet', 'radius-hotel-booking-pro' ) }
					description={ __(
						'Create a role such as Receptionist and assign it to your staff.',
						'radius-hotel-booking-pro'
					) }
					action={
						<Button onClick={ () => open( 'new' ) }>
							{ __( 'New role', 'radius-hotel-booking-pro' ) }
						</Button>
					}
				/>
			) }

			<Suspense fallback={ null }>
				{ deleting ? (
					<ConfirmDialog
						open
						onOpenChange={ ( next ) => ! next && setDeleting( null ) }
						destructive
						title={ sprintf(
							/* translators: %s: role name. */
							__( 'Delete %s?', 'radius-hotel-booking-pro' ),
							deleting.name
						) }
						description={ __(
							'Everyone with this role goes back to the default permissions. Their personal overrides stay.',
							'radius-hotel-booking-pro'
						) }
						confirmLabel={ __( 'Delete role', 'radius-hotel-booking-pro' ) }
						onConfirm={ () =>
							remove
								.mutateAsync( deleting.id )
								.then( () => setDeleting( null ) )
								.catch( () => {} )
						}
					/>
				) : null }
			</Suspense>
		</Panel>
	);
}

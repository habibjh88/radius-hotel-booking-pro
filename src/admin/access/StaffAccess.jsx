/**
 * One person's access (features 13.5, 13.12, 13.15): access role, personal
 * PIN, and per-key overrides next to the effective level and its source.
 */
import { Suspense, useEffect, useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeft } from 'lucide-react';
import {
	Badge,
	Button,
	ConfirmDialog,
	Field,
	Input,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	SettingsSection,
} from '@rtbp/ui';

import LevelMatrix from './LevelMatrix';
import { levelLabel, sourceLabel, withUnset } from './levels';
import { LoadError, ListSkeleton } from './RolesScreen';
import ProfileFieldsControl from './ProfileFieldsControl';
import {
	useProfileFields,
	useRegistry,
	useResetOverrides,
	useRoles,
	useSaveUserAccess,
	useSetPin,
	useUserAccess,
} from './api';

/**
 * The personal PIN: set or remove. Never shown.
 *
 * @param {Object}  props        Props.
 * @param {number}  props.userId User id.
 * @param {boolean} props.hasPin Whether one is set.
 * @return {JSX.Element} Control.
 */
function PinField( { userId, hasPin } ) {
	const [ pin, setPin ] = useState( '' );
	const save = useSetPin( userId );
	const valid = /^\d{4,8}$/.test( pin );

	return (
		<Field
			label={ __( 'Personal PIN', 'radius-hotel-booking-pro' ) }
			description={ __(
				'4 to 8 digits, used to confirm protected actions. Tell the person their PIN; it is never shown again.',
				'radius-hotel-booking-pro'
			) }
		>
			<div className="flex flex-wrap items-center gap-2">
				<Input
					type="password"
					inputMode="numeric"
					autoComplete="new-password"
					maxLength={ 8 }
					value={ pin }
					onChange={ ( event ) =>
						setPin( event.target.value.replace( /\D/g, '' ) )
					}
					className="w-32"
				/>
				<Button
					size="sm"
					disabled={ ! valid || save.isPending }
					onClick={ () =>
						save.mutate( pin, { onSuccess: () => setPin( '' ) } )
					}
				>
					{ hasPin
						? __( 'Change PIN', 'radius-hotel-booking-pro' )
						: __( 'Set PIN', 'radius-hotel-booking-pro' ) }
				</Button>
				{ hasPin ? (
					<Button
						size="sm"
						variant="ghost"
						disabled={ save.isPending }
						onClick={ () => save.mutate( null ) }
					>
						{ __( 'Remove', 'radius-hotel-booking-pro' ) }
					</Button>
				) : null }
				<Badge variant="outline">
					{ hasPin
						? __( 'PIN set', 'radius-hotel-booking-pro' )
						: __( 'No PIN', 'radius-hotel-booking-pro' ) }
				</Badge>
			</div>
		</Field>
	);
}

/**
 * @param {Object}   props        Props.
 * @param {number}   props.userId User id.
 * @param {Function} props.onBack Back to the list.
 * @return {JSX.Element} Screen.
 */
export default function StaffAccess( { userId, onBack } ) {
	const profile = useUserAccess( userId );
	const registry = useRegistry();
	const roles = useRoles();
	const save = useSaveUserAccess( userId );
	const fields = useProfileFields();
	const reset = useResetOverrides();
	const [ draft, setDraft ] = useState( {} );
	const [ confirmReset, setConfirmReset ] = useState( false );

	const saved = profile.data?.overrides;
	useEffect( () => setDraft( { ...( saved ?? {} ) } ), [ saved ] );

	const effective = useMemo(
		() =>
			Object.fromEntries(
				( profile.data?.effective ?? [] ).map( ( row ) => [
					row.key,
					row,
				] )
			),
		[ profile.data ]
	);

	// Keys whose override differs from the saved one: level, or null to remove.
	const changes = useMemo( () => {
		const out = {};
		new Set( [
			...Object.keys( saved ?? {} ),
			...Object.keys( draft ),
		] ).forEach( ( key ) => {
			if ( ( saved?.[ key ] ?? '' ) !== ( draft[ key ] ?? '' ) ) {
				out[ key ] = draft[ key ] || null;
			}
		} );
		return out;
	}, [ saved, draft ] );
	const dirty = Object.keys( changes ).length > 0;

	const error = profile.error || registry.error || roles.error;
	if ( error ) {
		return (
			<LoadError
				error={ error }
				onRetry={ () => {
					profile.refetch();
					registry.refetch();
					roles.refetch();
				} }
			/>
		);
	}
	if ( profile.isPending || registry.isPending || roles.isPending ) {
		return <ListSkeleton />;
	}

	const { user } = profile.data;
	const overrideCount = Object.keys( saved ?? {} ).length;

	return (
		<div className="space-y-6">
			<Button variant="ghost" size="sm" onClick={ onBack }>
				<ArrowLeft aria-hidden="true" />
				{ __( 'All staff', 'radius-hotel-booking-pro' ) }
			</Button>

			<SettingsSection
				title={ user.name }
				description={ user.email }
				actions={
					user.is_admin ? (
						<Badge variant="outline">
							{ __( 'Administrator', 'radius-hotel-booking-pro' ) }
						</Badge>
					) : null
				}
			>
				<Field
					label={ __( 'Access role', 'radius-hotel-booking-pro' ) }
					description={ __(
						'The role sets most permissions; the overrides below change single ones.',
						'radius-hotel-booking-pro'
					) }
				>
					<div>
						<Suspense fallback={ null }>
							<Select
								value={ String( profile.data.access_role ?? 'none' ) }
								onValueChange={ ( next ) =>
									save.mutate( {
										access_role:
											'none' === next ? null : Number( next ),
									} )
								}
								disabled={ save.isPending }
							>
								<SelectTrigger className="w-full max-w-xs">
									<SelectValue />
								</SelectTrigger>
								<SelectContent className="rtbp-root">
									<SelectItem value="none">
										{ __(
											'No access role (defaults)',
											'radius-hotel-booking-pro'
										) }
									</SelectItem>
									{ roles.data.map( ( role ) => (
										<SelectItem
											key={ role.id }
											value={ String( role.id ) }
										>
											{ role.name }
										</SelectItem>
									) ) }
								</SelectContent>
							</Select>
						</Suspense>
					</div>
				</Field>
				<PinField userId={ userId } hasPin={ profile.data.has_pin } />
			</SettingsSection>

			<SettingsSection
				title={ __( 'Personal overrides', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Inherit keeps what the role or the defaults give. Anything else applies to this person only. “Now” shows what applies today and why.',
					'radius-hotel-booking-pro'
				) }
				actions={
					overrideCount ? (
						<Button
							size="sm"
							variant="ghost"
							onClick={ () => setConfirmReset( true ) }
						>
							{ __( 'Reset overrides', 'radius-hotel-booking-pro' ) }
						</Button>
					) : null
				}
			>
				<p className="m-0 text-sm text-muted-foreground">
					{ overrideCount
						? __( 'This person has personal overrides.', 'radius-hotel-booking-pro' )
						: __( 'No personal overrides.', 'radius-hotel-booking-pro' ) }
				</p>
			</SettingsSection>

			<LevelMatrix
				registry={ registry.data }
				value={ draft }
				onChange={ ( key, level ) =>
					setDraft( ( current ) => {
						const next = { ...current };
						if ( level ) {
							next[ key ] = level;
						} else {
							delete next[ key ];
						}
						return next;
					} )
				}
				options={ withUnset( __( 'Inherit', 'radius-hotel-booking-pro' ) ) }
				aside={ ( key ) =>
					effective[ key.key ] ? (
						<span className="mt-0.5 block text-xs text-muted-foreground">
							{ sprintf(
								/* translators: 1: a level such as Open, 2: where it comes from, e.g. Access role. */
								__( 'Now: %1$s · %2$s', 'radius-hotel-booking-pro' ),
								levelLabel( effective[ key.key ].level ),
								sourceLabel( effective[ key.key ].source )
							) }
						</span>
					) : null
				}
			/>

			{ fields.data ? (
				<SettingsSection
					title={ __( 'Profile fields', 'radius-hotel-booking-pro' ) }
					description={ __(
						'Which fields this person may change on their own profile. Inherit keeps the role or default.',
						'radius-hotel-booking-pro'
					) }
				>
					<ProfileFieldsControl
						fields={ fields.data.fields }
						value={ profile.data.profile_fields?.overrides ?? {} }
						unsetLabel={ __( 'Inherit', 'radius-hotel-booking-pro' ) }
						effective={ Object.fromEntries(
							( profile.data.profile_fields?.effective ?? [] ).map(
								( row ) => [ row.key, row ]
							)
						) }
						onChange={ ( key, next ) =>
							save.mutate( {
								profile_fields: { [ key ]: next || null },
							} )
						}
					/>
				</SettingsSection>
			) : null }

			{ dirty ? (
				<div className="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 border-t border-border bg-background py-3">
					<Button
						variant="outline"
						onClick={ () => setDraft( { ...( saved ?? {} ) } ) }
						disabled={ save.isPending }
					>
						{ __( 'Discard', 'radius-hotel-booking-pro' ) }
					</Button>
					<Button
						onClick={ () => save.mutate( { overrides: changes } ) }
						disabled={ save.isPending }
					>
						{ __( 'Save overrides', 'radius-hotel-booking-pro' ) }
					</Button>
				</div>
			) : null }

			<Suspense fallback={ null }>
				{ confirmReset ? (
					<ConfirmDialog
						open
						onOpenChange={ ( next ) => ! next && setConfirmReset( false ) }
						destructive
						title={ __(
							'Reset this person’s overrides?',
							'radius-hotel-booking-pro'
						) }
						description={ __(
							'Every personal override is removed; the access role and the defaults apply again.',
							'radius-hotel-booking-pro'
						) }
						confirmLabel={ __( 'Reset overrides', 'radius-hotel-booking-pro' ) }
						onConfirm={ () =>
							reset
								.mutateAsync( userId )
								.then( () => setConfirmReset( false ) )
								.catch( () => {} )
						}
					/>
				) : null }
			</Suspense>
		</div>
	);
}

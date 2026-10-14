/**
 * Payroll → Work shifts (M15 T3b, 15.10): named shifts (*Jour*, *Nuit*,
 * *Jour de repos*) with their hours on each day of the week. An end at or
 * before the start runs past midnight. Hours a week turn a salary into an
 * hourly rate.
 */
import { Suspense, useEffect, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Clock, FilePenLine, Moon, Plus, Trash2 } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	DataTable,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	EmptyState,
	Field,
	Input,
	Panel,
	Skeleton,
	Switch,
	Textarea,
} from '@rtbp/ui';
import { access, toast, toastError, usePageActions } from '@rtbp/lib';

import { TimeField } from '../pickers';
import { useDeleteShift, useSaveShift, useWorkShifts } from './api';

/**
 * ISO weekdays 1–7 with their names.
 *
 * @return {Array<{n: number, label: string, short: string}>} Days.
 */
const weekdays = () => [
	{
		n: 1,
		label: __( 'Monday', 'radius-hotel-booking-pro' ),
		short: __( 'Mon', 'radius-hotel-booking-pro' ),
	},
	{
		n: 2,
		label: __( 'Tuesday', 'radius-hotel-booking-pro' ),
		short: __( 'Tue', 'radius-hotel-booking-pro' ),
	},
	{
		n: 3,
		label: __( 'Wednesday', 'radius-hotel-booking-pro' ),
		short: __( 'Wed', 'radius-hotel-booking-pro' ),
	},
	{
		n: 4,
		label: __( 'Thursday', 'radius-hotel-booking-pro' ),
		short: __( 'Thu', 'radius-hotel-booking-pro' ),
	},
	{
		n: 5,
		label: __( 'Friday', 'radius-hotel-booking-pro' ),
		short: __( 'Fri', 'radius-hotel-booking-pro' ),
	},
	{
		n: 6,
		label: __( 'Saturday', 'radius-hotel-booking-pro' ),
		short: __( 'Sat', 'radius-hotel-booking-pro' ),
	},
	{
		n: 7,
		label: __( 'Sunday', 'radius-hotel-booking-pro' ),
		short: __( 'Sun', 'radius-hotel-booking-pro' ),
	},
];

const BLANK_DAY = { is_day_off: false, start_time: '08:00', end_time: '17:00' };

/**
 * Hours with up to two decimals ("14", "8.75").
 *
 * @param {number} hours Hours.
 * @return {string} Text.
 */
const hoursText = ( hours ) =>
	sprintf(
		/* translators: %s: number of hours. */
		__( '%s h', 'radius-hotel-booking-pro' ),
		String( Math.round( hours * 100 ) / 100 )
	);

/**
 * A field error from the API client (`{ first_message }`) as text.
 *
 * @param {*} value Error.
 * @return {string} Message.
 */
const message = ( value ) =>
	value?.first_message ??
	( Array.isArray( value ) ? value[ 0 ] : String( value ) );

/**
 * Add or change a shift.
 *
 * @param {Object}   props         Props.
 * @param {boolean}  props.open    Open.
 * @param {Object}   props.shift   The shift being changed, or null.
 * @param {Function} props.onClose Close.
 * @return {JSX.Element} Dialog.
 */
function ShiftDialog( { open, shift, onClose } ) {
	const [ name, setName ] = useState( '' );
	const [ description, setDescription ] = useState( '' );
	const [ days, setDays ] = useState( {} );
	const [ errors, setErrors ] = useState( {} );
	const save = useSaveShift();
	const DAYS = weekdays();

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		setName( shift?.name || '' );
		setDescription( shift?.description || '' );
		const next = {};
		DAYS.forEach( ( { n } ) => {
			const day = shift?.days?.[ n ];
			next[ n ] = day
				? {
						is_day_off: day.is_day_off,
						start_time: day.start_time || '08:00',
						end_time: day.end_time || '17:00',
				  }
				: { ...BLANK_DAY };
		} );
		setDays( next );
		setErrors( {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ open, shift ] );

	const setDay = ( n, patch ) => {
		setDays( ( current ) => ( {
			...current,
			[ n ]: { ...current[ n ], ...patch },
		} ) );
		setErrors( ( current ) => ( {
			...current,
			[ `days.${ n }.start_time` ]: undefined,
			[ `days.${ n }.end_time` ]: undefined,
		} ) );
	};

	const copyMonday = () =>
		setDays( ( current ) => {
			const next = {};
			DAYS.forEach( ( { n } ) => ( next[ n ] = { ...current[ 1 ] } ) );
			return next;
		} );

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await save.mutateAsync( {
				id: shift?.id,
				name,
				description,
				days,
			} );
			toast.success( response.message );
			onClose();
		} catch ( err ) {
			const mapped = {};
			Object.entries( err.errors || {} ).forEach(
				( [ key, value ] ) => ( mapped[ key ] = message( value ) )
			);
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-h-[90vh] max-w-lg overflow-y-auto">
				<form className="space-y-4" onSubmit={ submit }>
					<DialogHeader>
						<DialogTitle>
							{ shift
								? __(
										'Change the work shift',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Add a work shift',
										'radius-hotel-booking-pro'
								  ) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'The hours on each day of the week. An end at or before the start runs past midnight.',
								'radius-hotel-booking-pro'
							) }
						</DialogDescription>
					</DialogHeader>
					<Field
						label={ __( 'Name', 'radius-hotel-booking-pro' ) }
						error={ errors.name }
						required
					>
						<Input
							value={ name }
							maxLength={ 100 }
							autoComplete="off"
							onChange={ ( e ) => {
								setName( e.target.value );
								setErrors( ( c ) => ( {
									...c,
									name: undefined,
								} ) );
							} }
						/>
					</Field>
					<div className="space-y-2">
						<div className="flex items-center justify-between gap-2">
							<span className="text-sm font-medium">
								{ __( 'Hours', 'radius-hotel-booking-pro' ) }
							</span>
							<Button
								type="button"
								variant="ghost"
								size="sm"
								onClick={ copyMonday }
							>
								{ __(
									"Use Monday's hours every day",
									'radius-hotel-booking-pro'
								) }
							</Button>
						</div>
						{ DAYS.map( ( { n, label } ) => {
							const day = days[ n ] || BLANK_DAY;
							const error =
								errors[ `days.${ n }.start_time` ] ||
								errors[ `days.${ n }.end_time` ];
							return (
								<div
									key={ n }
									className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3 py-2"
								>
									<span className="w-24 text-sm">
										{ label }
									</span>
									<label className="flex items-center gap-2 text-sm text-muted-foreground">
										<Switch
											checked={ ! day.is_day_off }
											onCheckedChange={ ( on ) =>
												setDay( n, {
													is_day_off: ! on,
												} )
											}
											aria-label={ sprintf(
												/* translators: %s: day of the week. */
												__(
													'Works on %s',
													'radius-hotel-booking-pro'
												),
												label
											) }
										/>
										{ day.is_day_off
											? __(
													'Day off',
													'radius-hotel-booking-pro'
											  )
											: null }
									</label>
									{ ! day.is_day_off ? (
										<div className="flex items-center gap-2">
											<TimeField
												className="w-32"
												value={ day.start_time }
												aria-label={ sprintf(
													/* translators: %s: day of the week. */
													__(
														'%s start',
														'radius-hotel-booking-pro'
													),
													label
												) }
												onChange={ ( time ) =>
													setDay( n, {
														start_time: time,
													} )
												}
											/>
											<span aria-hidden="true">–</span>
											<TimeField
												className="w-32"
												value={ day.end_time }
												aria-label={ sprintf(
													/* translators: %s: day of the week. */
													__(
														'%s end',
														'radius-hotel-booking-pro'
													),
													label
												) }
												onChange={ ( time ) =>
													setDay( n, {
														end_time: time,
													} )
												}
											/>
										</div>
									) : null }
									{ error ? (
										<p className="w-full text-xs text-destructive">
											{ error }
										</p>
									) : null }
								</div>
							);
						} ) }
					</div>
					<Field
						label={ __(
							'Description',
							'radius-hotel-booking-pro'
						) }
						error={ errors.description }
					>
						<Textarea
							rows={ 2 }
							maxLength={ 500 }
							value={ description }
							onChange={ ( e ) =>
								setDescription( e.target.value )
							}
						/>
					</Field>
					<DialogFooter>
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button type="submit" disabled={ save.isPending }>
							{ shift
								? __( 'Save', 'radius-hotel-booking-pro' )
								: __(
										'Add work shift',
										'radius-hotel-booking-pro'
								  ) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

/**
 * A shift's week, one chip per day.
 *
 * @param {Object} props       Props.
 * @param {Object} props.shift Shift.
 * @return {JSX.Element} Week.
 */
function Week( { shift } ) {
	return (
		<div className="flex flex-wrap gap-1">
			{ weekdays().map( ( { n, short, label } ) => {
				const day = shift.days[ n ];
				return (
					<span
						key={ n }
						title={ label }
						className={
							day.is_day_off
								? 'inline-flex items-center gap-1 rounded border border-dashed px-1.5 py-0.5 text-xs text-muted-foreground'
								: 'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs'
						}
					>
						<span className="font-medium">{ short }</span>
						{ day.is_day_off
							? __( 'off', 'radius-hotel-booking-pro' )
							: `${ day.start_time }–${ day.end_time }` }
						{ day.overnight ? (
							<Moon
								className="h-3 w-3"
								aria-label={ __(
									'Past midnight',
									'radius-hotel-booking-pro'
								) }
							/>
						) : null }
					</span>
				);
			} ) }
		</div>
	);
}

/**
 * @return {JSX.Element} Tab.
 */
export default function ShiftsTab() {
	const list = useWorkShifts();
	const remove = useDeleteShift();
	const canManage = access?.useAccess
		? access.useAccess( 'payroll.shifts' ) !== 'locked'
		: false;
	const [ editing, setEditing ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );

	usePageActions(
		canManage ? (
			<Button onClick={ () => setEditing( { shift: null } ) }>
				<Plus className="h-4 w-4" aria-hidden="true" />
				<span className="sr-only sm:not-sr-only">
					{ __( 'Add work shift', 'radius-hotel-booking-pro' ) }
				</span>
			</Button>
		) : null
	);

	const columns = [
		{
			id: 'name',
			header: __( 'Work shift', 'radius-hotel-booking-pro' ),
			mobile: 'title',
			cell: ( shift ) => (
				<span className="font-semibold text-heading">
					{ shift.name }
				</span>
			),
		},
		{
			id: 'week',
			header: __( 'Week', 'radius-hotel-booking-pro' ),
			cell: ( shift ) => <Week shift={ shift } />,
		},
		{
			id: 'hours',
			header: __( 'Hours a week', 'radius-hotel-booking-pro' ),
			mobile: 'subtitle',
			cell: ( shift ) => hoursText( shift.hours_per_week ),
		},
		{
			id: 'employees',
			header: __( 'Employees', 'radius-hotel-booking-pro' ),
			cell: ( shift ) =>
				sprintf(
					/* translators: %d: number of employees. */
					_n(
						'%d employee',
						'%d employees',
						shift.employees,
						'radius-hotel-booking-pro'
					),
					shift.employees
				),
		},
	];

	const rowActions = ( shift ) =>
		canManage
			? [
					{
						label: __( 'Change', 'radius-hotel-booking-pro' ),
						icon: FilePenLine,
						onSelect: () => setEditing( { shift } ),
					},
					{
						label: __( 'Delete', 'radius-hotel-booking-pro' ),
						icon: Trash2,
						destructive: true,
						onSelect: () => setDeleting( shift ),
					},
			  ]
			: [];

	return (
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<Panel>
				<DataTable
					columns={ columns }
					rows={ list.data?.shifts || [] }
					total={ list.data?.shifts?.length || 0 }
					loading={ list.isPending }
					error={ list.error }
					onRetry={ () => list.refetch() }
					rowActions={ rowActions }
					onRowClick={
						canManage
							? ( shift ) => setEditing( { shift } )
							: undefined
					}
					empty={
						<EmptyState
							icon={ Clock }
							title={ __(
								'No work shifts yet',
								'radius-hotel-booking-pro'
							) }
							description={ __(
								'A work shift gives the working hours of each day, such as Jour, Nuit or Jour de repos.',
								'radius-hotel-booking-pro'
							) }
							className="border-0 py-12"
						/>
					}
					caption={ __( 'Work shifts', 'radius-hotel-booking-pro' ) }
				/>
			</Panel>

			<ShiftDialog
				open={ Boolean( editing ) }
				shift={ editing?.shift || null }
				onClose={ () => setEditing( null ) }
			/>

			<ConfirmDialog
				open={ Boolean( deleting ) }
				onOpenChange={ ( open ) => ! open && setDeleting( null ) }
				title={
					deleting
						? sprintf(
								/* translators: %s: work shift name. */
								__( 'Delete %s?', 'radius-hotel-booking-pro' ),
								deleting.name
						  )
						: ''
				}
				description={ __(
					'A work shift that employees work cannot be deleted.',
					'radius-hotel-booking-pro'
				) }
				confirmLabel={ __( 'Delete', 'radius-hotel-booking-pro' ) }
				destructive
				onConfirm={ () =>
					remove
						.mutateAsync( deleting.id )
						.then( ( response ) =>
							toast.success( response.message )
						)
						.catch( ( err ) => toastError( err ) )
				}
			/>
		</Suspense>
	);
}

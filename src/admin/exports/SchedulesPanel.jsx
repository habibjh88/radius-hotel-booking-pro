/**
 * Scheduled exports (M11 T4, 11.8): a panel of the free Exports screen
 * (`rtbp.exports.panels`). Each schedule makes a file on its own — weekly
 * (the previous full week) or monthly (the previous month) — into the file
 * library; the list shows when each runs next.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { CalendarClock, Pencil, Plus, Trash2 } from 'lucide-react';
import {
	Badge,
	Button,
	ConfirmDialog,
	DateTime,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	Field,
	Input,
	Panel,
	SegmentedControl,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Switch,
} from '@rtbp/ui';
import { access, api, toast, toastError } from '@rtbp/lib';

import { TimeField } from '../pickers';

const KEY = [ 'exports', 'schedules' ];

/**
 * Weekday names, Monday = 1.
 *
 * @return {Array<{value: string, label: string}>} Options.
 */
const weekdays = () => [
	{ value: '1', label: __( 'Monday', 'radius-hotel-booking-pro' ) },
	{ value: '2', label: __( 'Tuesday', 'radius-hotel-booking-pro' ) },
	{ value: '3', label: __( 'Wednesday', 'radius-hotel-booking-pro' ) },
	{ value: '4', label: __( 'Thursday', 'radius-hotel-booking-pro' ) },
	{ value: '5', label: __( 'Friday', 'radius-hotel-booking-pro' ) },
	{ value: '6', label: __( 'Saturday', 'radius-hotel-booking-pro' ) },
	{ value: '7', label: __( 'Sunday', 'radius-hotel-booking-pro' ) },
];

const EMPTY = {
	kind: 'bookings',
	frequency: 'monthly',
	weekday: 1,
	day: 1,
	time: '06:00',
	mode: 'arrival',
	format: 'csv',
	enabled: true,
};

/**
 * @param {Object} props         Props from the Exports screen.
 * @param {Array}  props.kinds   `[ { key, label, period } ]`.
 * @param {Array}  props.formats `[ { key, label } ]`.
 * @return {JSX.Element} Panel.
 */
export default function SchedulesPanel( { kinds = [], formats = [] } ) {
	const canEdit = access.useAccess( 'exports.generate' ) !== 'locked';
	const client = useQueryClient();
	const list = useQuery( {
		queryKey: KEY,
		queryFn: () => api.get( 'exports/schedules' ).then( ( r ) => r.data ),
	} );
	const save = useMutation( {
		mutationFn: ( { id, ...data } ) =>
			id
				? api.put( `exports/schedules/${ id }`, data )
				: api.post( 'exports/schedules', data ),
		onSuccess: () => client.invalidateQueries( { queryKey: KEY } ),
	} );
	const remove = useMutation( {
		mutationFn: ( id ) => api.del( `exports/schedules/${ id }` ),
		onSuccess: () => client.invalidateQueries( { queryKey: KEY } ),
	} );
	// The schedule in the dialog (null = closed, no id = new).
	const [ editing, setEditing ] = useState( null );
	const [ errors, setErrors ] = useState( {} );
	const [ deleting, setDeleting ] = useState( null );

	const items = list.data?.items || [];
	const kindLabel = ( key ) =>
		kinds.find( ( kind ) => kind.key === key )?.label || key;
	const hasPeriod = ( key ) =>
		false !== kinds.find( ( kind ) => kind.key === key )?.period;
	const rhythm = ( item ) =>
		'monthly' === item.frequency
			? sprintf(
					/* translators: 1: day of the month, 2: time. */
					__(
						'Monthly, on day %1$d at %2$s — the previous month',
						'radius-hotel-booking-pro'
					),
					item.day,
					item.time
			  )
			: sprintf(
					/* translators: 1: weekday, 2: time. */
					__(
						'Every %1$s at %2$s — the previous week',
						'radius-hotel-booking-pro'
					),
					weekdays().find(
						( d ) => Number( d.value ) === item.weekday
					)?.label,
					item.time
			  );

	const submit = () =>
		save
			.mutateAsync( editing )
			.then( () => {
				toast.success(
					__( 'Scheduled export saved.', 'radius-hotel-booking-pro' )
				);
				setEditing( null );
				setErrors( {} );
			} )
			.catch( ( err ) => {
				const fields = {};
				Object.entries( err?.errors || {} ).forEach(
					( [ key, value ] ) => {
						fields[ key ] = value?.first_message || '';
					}
				);
				setErrors( fields );
				if ( ! Object.keys( fields ).length ) {
					toastError( err );
				}
			} );

	const set = ( changes ) =>
		setEditing( ( prev ) => ( { ...prev, ...changes } ) );

	return (
		<Panel
			title={ __( 'Scheduled exports', 'radius-hotel-booking-pro' ) }
			description={ __(
				'Files made on their own into the library, weekly or monthly.',
				'radius-hotel-booking-pro'
			) }
			actions={
				canEdit ? (
					<Button
						type="button"
						variant="outline"
						size="sm"
						onClick={ () => {
							setErrors( {} );
							setEditing( { ...EMPTY } );
						} }
					>
						<Plus aria-hidden="true" />
						{ __( 'Add', 'radius-hotel-booking-pro' ) }
					</Button>
				) : null
			}
		>
			{ items.length ? (
				<ul className="m-0 list-none divide-y divide-border p-0">
					{ items.map( ( item ) => (
						<li
							key={ item.id }
							className="flex flex-wrap items-center justify-between gap-3 py-3"
						>
							<div className="min-w-0 space-y-0.5">
								<div className="font-semibold text-heading">
									{ kindLabel( item.kind ) }
									<span className="ml-2 font-normal text-muted-foreground">
										{ item.format.toUpperCase() }
									</span>
									{ ! item.enabled ? (
										<Badge
											variant="outline"
											className="ml-2"
										>
											{ __(
												'Paused',
												'radius-hotel-booking-pro'
											) }
										</Badge>
									) : null }
									{ 'failed' === item.last_status ? (
										<Badge
											variant="destructive"
											className="ml-2"
											title={ item.last_error }
										>
											{ __(
												'Last run failed',
												'radius-hotel-booking-pro'
											) }
										</Badge>
									) : null }
								</div>
								<p className="m-0 text-sm text-muted-foreground">
									{ rhythm( item ) }
								</p>
								<p className="m-0 flex flex-wrap gap-x-3 text-sm text-muted-foreground">
									<span>
										{ __(
											'Next:',
											'radius-hotel-booking-pro'
										) }{ ' ' }
										{ item.next_run ? (
											<DateTime value={ item.next_run } />
										) : (
											'—'
										) }
									</span>
									<span>
										{ __(
											'Last:',
											'radius-hotel-booking-pro'
										) }{ ' ' }
										{ item.last_run ? (
											<DateTime value={ item.last_run } />
										) : (
											__(
												'not yet',
												'radius-hotel-booking-pro'
											)
										) }
									</span>
								</p>
							</div>
							{ canEdit ? (
								<div className="flex items-center gap-2">
									<Switch
										checked={ item.enabled }
										onCheckedChange={ ( enabled ) =>
											save
												.mutateAsync( {
													id: item.id,
													enabled,
												} )
												.catch( toastError )
										}
										aria-label={ __(
											'Run this schedule',
											'radius-hotel-booking-pro'
										) }
									/>
									<Button
										type="button"
										variant="ghost"
										size="icon"
										onClick={ () => {
											setErrors( {} );
											setEditing( { ...item } );
										} }
										aria-label={ __(
											'Edit',
											'radius-hotel-booking-pro'
										) }
									>
										<Pencil aria-hidden="true" />
									</Button>
									<Button
										type="button"
										variant="ghost"
										size="icon"
										onClick={ () => setDeleting( item ) }
										aria-label={ __(
											'Remove',
											'radius-hotel-booking-pro'
										) }
									>
										<Trash2 aria-hidden="true" />
									</Button>
								</div>
							) : null }
						</li>
					) ) }
				</ul>
			) : (
				<p className="m-0 flex items-center gap-2 text-sm text-muted-foreground">
					<CalendarClock className="h-4 w-4" aria-hidden="true" />
					{ list.isPending
						? __( 'Loading…', 'radius-hotel-booking-pro' )
						: __(
								'No scheduled export yet.',
								'radius-hotel-booking-pro'
						  ) }
				</p>
			) }

			<Dialog
				open={ Boolean( editing ) }
				onOpenChange={ ( open ) => ! open && setEditing( null ) }
			>
				<DialogContent className="rtbp-root max-h-[90vh] max-w-md overflow-y-auto">
					<DialogHeader>
						<DialogTitle>
							{ editing?.id
								? __(
										'Edit the schedule',
										'radius-hotel-booking-pro'
								  )
								: __(
										'New scheduled export',
										'radius-hotel-booking-pro'
								  ) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'Times are the hotel’s. Files go to the library, marked “Scheduled”.',
								'radius-hotel-booking-pro'
							) }
						</DialogDescription>
					</DialogHeader>
					{ editing ? (
						<div className="space-y-4">
							<Field
								label={ __(
									'Contents',
									'radius-hotel-booking-pro'
								) }
								error={ errors.kind }
							>
								<Select
									value={ editing.kind }
									onValueChange={ ( kind ) =>
										set( { kind } )
									}
								>
									<SelectTrigger className="w-full">
										<SelectValue />
									</SelectTrigger>
									<SelectContent className="rtbp-root">
										{ kinds.map( ( kind ) => (
											<SelectItem
												key={ kind.key }
												value={ kind.key }
											>
												{ kind.label }
											</SelectItem>
										) ) }
									</SelectContent>
								</Select>
							</Field>
							<Field
								label={ __(
									'How often',
									'radius-hotel-booking-pro'
								) }
							>
								<div>
									<SegmentedControl
										options={ [
											{
												value: 'weekly',
												label: __(
													'Weekly',
													'radius-hotel-booking-pro'
												),
											},
											{
												value: 'monthly',
												label: __(
													'Monthly',
													'radius-hotel-booking-pro'
												),
											},
										] }
										value={ editing.frequency }
										onChange={ ( frequency ) =>
											set( { frequency } )
										}
										label={ __(
											'How often',
											'radius-hotel-booking-pro'
										) }
									/>
								</div>
							</Field>
							<div className="grid grid-cols-2 gap-3">
								{ 'weekly' === editing.frequency ? (
									<Field
										label={ __(
											'Day',
											'radius-hotel-booking-pro'
										) }
										error={ errors.weekday }
									>
										<Select
											value={ String( editing.weekday ) }
											onValueChange={ ( value ) =>
												set( {
													weekday: Number( value ),
												} )
											}
										>
											<SelectTrigger className="w-full">
												<SelectValue />
											</SelectTrigger>
											<SelectContent className="rtbp-root">
												{ weekdays().map( ( day ) => (
													<SelectItem
														key={ day.value }
														value={ day.value }
													>
														{ day.label }
													</SelectItem>
												) ) }
											</SelectContent>
										</Select>
									</Field>
								) : (
									<Field
										label={ __(
											'Day of the month',
											'radius-hotel-booking-pro'
										) }
										error={ errors.day }
									>
										<Input
											type="number"
											min={ 1 }
											max={ 28 }
											value={ editing.day }
											onChange={ ( e ) =>
												set( {
													day: Number(
														e.target.value
													),
												} )
											}
										/>
									</Field>
								) }
								<Field
									label={ __(
										'Time',
										'radius-hotel-booking-pro'
									) }
									error={ errors.time }
								>
									<TimeField
										value={ editing.time }
										onChange={ ( time ) => set( { time } ) }
									/>
								</Field>
							</div>
							{ hasPeriod( editing.kind ) ? (
								<Field
									label={ __(
										'Bookings by',
										'radius-hotel-booking-pro'
									) }
								>
									<div>
										<SegmentedControl
											options={ [
												{
													value: 'arrival',
													label: __(
														'Arrival date',
														'radius-hotel-booking-pro'
													),
												},
												{
													value: 'created',
													label: __(
														'Booking date',
														'radius-hotel-booking-pro'
													),
												},
											] }
											value={ editing.mode }
											onChange={ ( mode ) =>
												set( { mode } )
											}
											label={ __(
												'Bookings by',
												'radius-hotel-booking-pro'
											) }
										/>
									</div>
								</Field>
							) : null }
							<Field
								label={ __(
									'File format',
									'radius-hotel-booking-pro'
								) }
								error={ errors.format }
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
										value={ editing.format }
										onChange={ ( format ) =>
											set( { format } )
										}
										label={ __(
											'File format',
											'radius-hotel-booking-pro'
										) }
									/>
								</div>
							</Field>
						</div>
					) : null }
					<DialogFooter>
						<Button
							type="button"
							variant="outline"
							onClick={ () => setEditing( null ) }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button
							type="button"
							onClick={ submit }
							disabled={ save.isPending }
						>
							{ __( 'Save', 'radius-hotel-booking-pro' ) }
						</Button>
					</DialogFooter>
				</DialogContent>
			</Dialog>

			<ConfirmDialog
				open={ Boolean( deleting ) }
				onOpenChange={ ( open ) => ! open && setDeleting( null ) }
				title={ __(
					'Remove this scheduled export?',
					'radius-hotel-booking-pro'
				) }
				description={ __(
					'No more files will be made by it. Files it already made stay in the library.',
					'radius-hotel-booking-pro'
				) }
				confirmLabel={ __( 'Remove', 'radius-hotel-booking-pro' ) }
				destructive
				onConfirm={ () =>
					remove
						.mutateAsync( deleting.id )
						.then( () => setDeleting( null ) )
						.catch( toastError )
				}
			/>
		</Panel>
	);
}

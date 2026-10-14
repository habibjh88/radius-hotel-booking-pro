/**
 * Payroll → Pay schedules (M15 T3a, 15.9): named pay intervals with their
 * next pay period. A schedule is set by one pay period — its last day and
 * its pay day; the following periods follow from it.
 */
import { Suspense, useEffect, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CalendarClock, FilePenLine, Plus, Trash2 } from 'lucide-react';
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
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
} from '@rtbp/ui';
import { access, format, toast, toastError, usePageActions } from '@rtbp/lib';

import { DateField } from '../pickers';
import { useDeleteSchedule, usePaySchedules, useSaveSchedule } from './api';

const BLANK = {
	name: '',
	pay_interval: 'monthly',
	period_end: '',
	next_pay_day: '',
};

/**
 * A period as "1 Oct – 31 Oct 2026".
 *
 * @param {Object} period `{ start, end }`.
 * @return {string} Text.
 */
const span = ( period ) =>
	sprintf(
		/* translators: 1: first day, 2: last day. */
		__( '%1$s – %2$s', 'radius-hotel-booking-pro' ),
		format.formatDate( period.start ),
		format.formatDate( period.end )
	);

/**
 * Add or change a schedule.
 *
 * @param {Object}   props           Props.
 * @param {boolean}  props.open      Open.
 * @param {Object}   props.schedule  The schedule being changed, or null.
 * @param {Array}    props.intervals `{ value, label }`.
 * @param {Function} props.onClose   Close.
 * @return {JSX.Element} Dialog.
 */
function ScheduleDialog( { open, schedule, intervals, onClose } ) {
	const [ values, setValues ] = useState( BLANK );
	const [ errors, setErrors ] = useState( {} );
	const save = useSaveSchedule();

	useEffect( () => {
		if ( open ) {
			setValues(
				schedule
					? {
							name: schedule.name,
							pay_interval: schedule.pay_interval,
							period_end: schedule.next_period.end,
							next_pay_day: schedule.next_period.pay_date,
					  }
					: BLANK
			);
			setErrors( {} );
		}
	}, [ open, schedule ] );

	const set = ( key, value ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
		setErrors( ( current ) => ( { ...current, [ key ]: undefined } ) );
	};

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await save.mutateAsync( {
				id: schedule?.id,
				...values,
			} );
			toast.success( response.message );
			onClose();
		} catch ( err ) {
			// The client gives `{ field: { messages, first_message } }`.
			const mapped = {};
			Object.entries( err.errors || {} ).forEach( ( [ key, value ] ) => {
				mapped[ key ] =
					value?.first_message ??
					( Array.isArray( value ) ? value[ 0 ] : String( value ) );
			} );
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-w-lg">
				<form className="space-y-4" onSubmit={ submit }>
					<DialogHeader>
						<DialogTitle>
							{ schedule
								? __(
										'Change the pay schedule',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Add a pay schedule',
										'radius-hotel-booking-pro'
								  ) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'Give the next pay period: its last day and the day it is paid. The periods after it follow.',
								'radius-hotel-booking-pro'
							) }
						</DialogDescription>
					</DialogHeader>
					<div className="grid gap-4 sm:grid-cols-2">
						<Field
							label={ __( 'Name', 'radius-hotel-booking-pro' ) }
							error={ errors.name }
							required
						>
							<Input
								value={ values.name }
								maxLength={ 100 }
								autoComplete="off"
								onChange={ ( e ) =>
									set( 'name', e.target.value )
								}
							/>
						</Field>
						<Field
							label={ __( 'Paid', 'radius-hotel-booking-pro' ) }
							error={ errors.pay_interval }
						>
							<Select
								value={ values.pay_interval }
								onValueChange={ ( value ) =>
									set( 'pay_interval', value )
								}
							>
								<SelectTrigger>
									<SelectValue />
								</SelectTrigger>
								<SelectContent className="rtbp-root">
									{ intervals.map( ( item ) => (
										<SelectItem
											key={ item.value }
											value={ item.value }
										>
											{ item.label }
										</SelectItem>
									) ) }
								</SelectContent>
							</Select>
						</Field>
						<Field
							label={ __(
								'End of the next pay period',
								'radius-hotel-booking-pro'
							) }
							description={
								'twicemonthly' === values.pay_interval
									? __(
											'The 15th or the last day of a month.',
											'radius-hotel-booking-pro'
									  )
									: ''
							}
							error={ errors.period_end }
							required
						>
							<DateField
								value={ values.period_end }
								onChange={ ( day ) => set( 'period_end', day ) }
							/>
						</Field>
						<Field
							label={ __(
								'Its pay day',
								'radius-hotel-booking-pro'
							) }
							error={ errors.next_pay_day }
							required
						>
							<DateField
								value={ values.next_pay_day }
								onChange={ ( day ) =>
									set( 'next_pay_day', day )
								}
							/>
						</Field>
					</div>
					<DialogFooter>
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button type="submit" disabled={ save.isPending }>
							{ schedule
								? __( 'Save', 'radius-hotel-booking-pro' )
								: __(
										'Add pay schedule',
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
 * @return {JSX.Element} Tab.
 */
export default function SchedulesTab() {
	const list = usePaySchedules();
	const remove = useDeleteSchedule();
	const canManage = access?.useAccess
		? access.useAccess( 'payroll.schedules' ) !== 'locked'
		: false;
	const [ editing, setEditing ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );

	usePageActions(
		canManage ? (
			<Button onClick={ () => setEditing( { schedule: null } ) }>
				<Plus className="h-4 w-4" aria-hidden="true" />
				<span className="sr-only sm:not-sr-only">
					{ __( 'Add pay schedule', 'radius-hotel-booking-pro' ) }
				</span>
			</Button>
		) : null
	);

	const columns = [
		{
			id: 'name',
			header: __( 'Pay schedule', 'radius-hotel-booking-pro' ),
			mobile: 'title',
			cell: ( schedule ) => (
				<span className="font-semibold text-heading">
					{ schedule.name }
				</span>
			),
		},
		{
			id: 'interval',
			header: __( 'Paid', 'radius-hotel-booking-pro' ),
			mobile: 'subtitle',
			cell: ( schedule ) => schedule.interval_label,
		},
		{
			id: 'period',
			header: __( 'Next pay period', 'radius-hotel-booking-pro' ),
			cell: ( schedule ) => span( schedule.next_period ),
		},
		{
			id: 'pay',
			header: __( 'Pay day', 'radius-hotel-booking-pro' ),
			cell: ( schedule ) =>
				format.formatDate( schedule.next_period.pay_date ),
		},
		{
			id: 'employees',
			header: __( 'Employees', 'radius-hotel-booking-pro' ),
			cell: ( schedule ) =>
				sprintf(
					/* translators: %d: number of employees. */
					_n(
						'%d employee',
						'%d employees',
						schedule.employees,
						'radius-hotel-booking-pro'
					),
					schedule.employees
				),
		},
	];

	const rowActions = ( schedule ) =>
		canManage
			? [
					{
						label: __( 'Change', 'radius-hotel-booking-pro' ),
						icon: FilePenLine,
						onSelect: () => setEditing( { schedule } ),
					},
					{
						label: __( 'Delete', 'radius-hotel-booking-pro' ),
						icon: Trash2,
						destructive: true,
						onSelect: () => setDeleting( schedule ),
					},
			  ]
			: [];

	return (
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<Panel>
				<DataTable
					columns={ columns }
					rows={ list.data?.schedules || [] }
					total={ list.data?.schedules?.length || 0 }
					loading={ list.isPending }
					error={ list.error }
					onRetry={ () => list.refetch() }
					rowActions={ rowActions }
					onRowClick={
						canManage
							? ( schedule ) => setEditing( { schedule } )
							: undefined
					}
					empty={
						<EmptyState
							icon={ CalendarClock }
							title={ __(
								'No pay schedules yet',
								'radius-hotel-booking-pro'
							) }
							description={ __(
								'A pay schedule says how often employees are paid. Add one, then put employees on it.',
								'radius-hotel-booking-pro'
							) }
							className="border-0 py-12"
						/>
					}
					caption={ __(
						'Pay schedules',
						'radius-hotel-booking-pro'
					) }
				/>
			</Panel>

			<ScheduleDialog
				open={ Boolean( editing ) }
				schedule={ editing?.schedule || null }
				intervals={ list.data?.intervals || [] }
				onClose={ () => setEditing( null ) }
			/>

			<ConfirmDialog
				open={ Boolean( deleting ) }
				onOpenChange={ ( open ) => ! open && setDeleting( null ) }
				title={
					deleting
						? sprintf(
								/* translators: %s: pay schedule name. */
								__( 'Delete %s?', 'radius-hotel-booking-pro' ),
								deleting.name
						  )
						: ''
				}
				description={ __(
					'A pay schedule with employees or pay runs cannot be deleted.',
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

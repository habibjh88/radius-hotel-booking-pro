/**
 * The *Pay* tab of an employee file (M15 T4b, 12.12, 12.13), added to Pro's
 * staff file through `rtbp.staff.tabs`: the pay schedule and work shift the
 * employee is on, and how they are paid — a salary per week, month or year,
 * or an hourly rate — with what that means per pay period. Read with
 * `page.payroll`, saved with `payroll.pay_profile`.
 */
import { Suspense, useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Field,
	Input,
	Panel,
	SegmentedControl,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
} from '@rtbp/ui';
import { access, format, toast, toastError } from '@rtbp/lib';

import AdditionalPay from './AdditionalPay';
import Deductions from './Deductions';
import { useEmployeePay, useSaveEmployeePay } from './api';

const NONE = '0';

/**
 * Values of the form from the saved profile.
 *
 * @param {Object} pay Profile.
 * @return {Object} Values.
 */
const toValues = ( pay ) => ( {
	pay_schedule_id: String( pay?.pay_schedule_id || NONE ),
	work_shift_id: String( pay?.work_shift_id || NONE ),
	pay_type: pay?.pay_type || 'salaried',
	amount: pay ? String( Number( pay.amount ) ) : '',
	amount_period:
		'hour' === pay?.amount_period ? 'month' : pay?.amount_period || 'month',
} );

/**
 * A choice list with a "none" entry.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.value    Value.
 * @param {Function} props.onChange Change.
 * @param {Array}    props.items    `{ id, name, hint }`.
 * @param {string}   props.none     The "none" label.
 * @param {boolean}  props.disabled Read only.
 * @return {JSX.Element} Select.
 */
function Choice( { value, onChange, items, none, disabled } ) {
	return (
		<Select
			value={ value }
			onValueChange={ onChange }
			disabled={ disabled }
		>
			<SelectTrigger>
				<SelectValue />
			</SelectTrigger>
			<SelectContent className="rtbp-root">
				<SelectItem value={ NONE }>{ none }</SelectItem>
				{ items.map( ( item ) => (
					<SelectItem key={ item.id } value={ String( item.id ) }>
						{ item.hint
							? sprintf(
									/* translators: 1: name, 2: detail. */
									__(
										'%1$s (%2$s)',
										'radius-hotel-booking-pro'
									),
									item.name,
									item.hint
							  )
							: item.name }
					</SelectItem>
				) ) }
			</SelectContent>
		</Select>
	);
}

/**
 * @param {Object} props          Props from `rtbp.staff.tabs`.
 * @param {Object} props.employee The employee (Pro).
 * @return {JSX.Element} Tab.
 */
export default function PayTab( { employee } ) {
	const query = useEmployeePay( employee.id );
	const save = useSaveEmployeePay( employee.id );
	const canEdit = access?.useAccess
		? access.useAccess( 'payroll.pay_profile' ) !== 'locked'
		: false;
	const [ values, setValues ] = useState( toValues( null ) );
	const [ errors, setErrors ] = useState( {} );
	const pay = query.data?.pay;

	useEffect( () => {
		if ( pay ) {
			setValues( toValues( pay ) );
		}
	}, [ pay ] );

	if ( query.isPending ) {
		return <Skeleton className="h-64 w-full rounded-xl" />;
	}
	if ( query.error ) {
		return (
			<Panel>
				<p className="m-0 text-sm text-destructive">
					{ query.error.message }
				</p>
			</Panel>
		);
	}

	const set = ( key, value ) => {
		setValues( ( current ) => ( { ...current, [ key ]: value } ) );
		setErrors( ( current ) => ( { ...current, [ key ]: undefined } ) );
	};
	const hourly = 'hourly' === values.pay_type;
	const dirty =
		JSON.stringify( values ) !== JSON.stringify( toValues( pay ) );

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await save.mutateAsync( {
				pay_schedule_id: Number( values.pay_schedule_id ),
				work_shift_id: Number( values.work_shift_id ),
				pay_type: values.pay_type,
				amount: values.amount,
				amount_period: hourly ? 'hour' : values.amount_period,
			} );
			toast.success( response.message );
		} catch ( err ) {
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

	const schedule = query.data.schedules.find(
		( s ) => String( s.id ) === String( pay.pay_schedule_id )
	);

	return (
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<Panel>
				<form className="space-y-5" onSubmit={ submit }>
					<div className="grid gap-4 sm:grid-cols-2">
						<Field
							label={ __(
								'Pay schedule',
								'radius-hotel-booking-pro'
							) }
							description={ __(
								'How often they are paid.',
								'radius-hotel-booking-pro'
							) }
							error={ errors.pay_schedule_id }
						>
							<Choice
								value={ values.pay_schedule_id }
								onChange={ ( v ) =>
									set( 'pay_schedule_id', v )
								}
								items={ query.data.schedules.map( ( s ) => ( {
									id: s.id,
									name: s.name,
									hint: s.interval_label,
								} ) ) }
								none={ __(
									'Not on a pay schedule',
									'radius-hotel-booking-pro'
								) }
								disabled={ ! canEdit }
							/>
						</Field>
						<Field
							label={ __(
								'Work shift',
								'radius-hotel-booking-pro'
							) }
							description={ __(
								'Their working hours.',
								'radius-hotel-booking-pro'
							) }
							error={ errors.work_shift_id }
						>
							<Choice
								value={ values.work_shift_id }
								onChange={ ( v ) => set( 'work_shift_id', v ) }
								items={ query.data.shifts.map( ( s ) => ( {
									id: s.id,
									name: s.name,
									hint: sprintf(
										/* translators: %s: hours a week. */
										__(
											'%s h a week',
											'radius-hotel-booking-pro'
										),
										String(
											Math.round(
												s.hours_per_week * 100
											) / 100
										)
									),
								} ) ) }
								none={ __(
									'No work shift',
									'radius-hotel-booking-pro'
								) }
								disabled={ ! canEdit }
							/>
						</Field>
					</div>

					<SegmentedControl
						label={ __( 'Paid', 'radius-hotel-booking-pro' ) }
						value={ values.pay_type }
						onChange={ ( v ) => canEdit && set( 'pay_type', v ) }
						options={ [
							{
								value: 'salaried',
								label: __(
									'A salary',
									'radius-hotel-booking-pro'
								),
							},
							{
								value: 'hourly',
								label: __(
									'By the hour',
									'radius-hotel-booking-pro'
								),
							},
						] }
					/>

					<div className="grid gap-4 sm:grid-cols-2">
						<Field
							label={
								hourly
									? __(
											'Hourly rate',
											'radius-hotel-booking-pro'
									  )
									: __( 'Salary', 'radius-hotel-booking-pro' )
							}
							error={ errors.amount }
							required
						>
							<Input
								type="number"
								inputMode="decimal"
								min="0"
								step="any"
								value={ values.amount }
								disabled={ ! canEdit }
								onChange={ ( e ) =>
									set( 'amount', e.target.value )
								}
							/>
						</Field>
						{ ! hourly ? (
							<Field
								label={ __(
									'Per',
									'radius-hotel-booking-pro'
								) }
								error={ errors.amount_period }
							>
								<SegmentedControl
									label={ __(
										'Salary period',
										'radius-hotel-booking-pro'
									) }
									value={ values.amount_period }
									onChange={ ( v ) =>
										canEdit && set( 'amount_period', v )
									}
									options={ [
										{
											value: 'week',
											label: __(
												'Week',
												'radius-hotel-booking-pro'
											),
										},
										{
											value: 'month',
											label: __(
												'Month',
												'radius-hotel-booking-pro'
											),
										},
										{
											value: 'year',
											label: __(
												'Year',
												'radius-hotel-booking-pro'
											),
										},
									] }
								/>
							</Field>
						) : null }
					</div>

					{ pay.saved ? (
						<dl className="m-0 grid gap-3 rounded-lg border bg-muted p-4 text-sm sm:grid-cols-2">
							{ null !== pay.salary_per_period && schedule ? (
								<div>
									<dt className="text-muted-foreground">
										{ sprintf(
											/* translators: %s: pay schedule name. */
											__(
												'Salary per pay period (%s)',
												'radius-hotel-booking-pro'
											),
											schedule.interval_label
										) }
									</dt>
									<dd className="m-0 font-semibold text-heading">
										{ format.formatMoney(
											pay.salary_per_period
										) }
									</dd>
								</div>
							) : null }
							<div>
								<dt className="text-muted-foreground">
									{ __(
										'Hourly rate',
										'radius-hotel-booking-pro'
									) }
								</dt>
								<dd className="m-0 font-semibold text-heading">
									{ null !== pay.hourly_rate
										? format.formatMoney( pay.hourly_rate )
										: __(
												'Set a work shift to work it out.',
												'radius-hotel-booking-pro'
										  ) }
								</dd>
							</div>
						</dl>
					) : null }

					{ canEdit ? (
						<div className="flex justify-end gap-2">
							<Button
								type="button"
								variant="ghost"
								disabled={ ! dirty }
								onClick={ () => {
									setValues( toValues( pay ) );
									setErrors( {} );
								} }
							>
								{ __(
									'Undo changes',
									'radius-hotel-booking-pro'
								) }
							</Button>
							<Button
								type="submit"
								disabled={ ! dirty || save.isPending }
							>
								{ __( 'Save', 'radius-hotel-booking-pro' ) }
							</Button>
						</div>
					) : null }
				</form>
			</Panel>
			<div className="mt-4">
				<AdditionalPay
					employeeId={ employee.id }
					saved={ query.data.items }
					types={ query.data.item_types }
					canEdit={ canEdit }
				/>
			</div>
			<div className="mt-4">
				<Deductions employeeId={ employee.id } canEdit={ canEdit } />
			</div>
		</Suspense>
	);
}

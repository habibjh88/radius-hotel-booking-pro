/**
 * The editor of a rule with its own adjustment and a threshold (features
 * 7.13–7.15): occupancy (from N % full, increase or decrease), early bird
 * (N+ days ahead, a discount) or last minute (N days or less, a discount).
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Trash2 } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	Field,
	Input,
	SegmentedControl,
	Switch,
} from '@rtbp/ui';
import { format, toast, toastError } from '@rtbp/lib';

import Drawer from './Drawer';
import { ScopePicker } from './scope';
import { useDeleteRule, useSaveRule } from './api';

/**
 * @param {Object}   props          Props.
 * @param {string}   props.kind     `occupancy`, `early_bird` or `last_minute` (for a new rule).
 * @param {boolean}  props.kinds    Offer the early bird / last minute switch (new booking-window rule).
 * @param {Object}   props.rule     Rule, or null to add one.
 * @param {Object}   props.scope    `{ roomTypes, ratePlans }`.
 * @param {boolean}  props.readOnly Show without saving.
 * @param {Function} props.onClose  Close.
 * @return {JSX.Element} Drawer.
 */
export default function AdjustedRuleEditor( {
	kind,
	kinds = false,
	rule,
	scope,
	readOnly,
	onClose,
} ) {
	const save = useSaveRule();
	const remove = useDeleteRule();
	const [ values, setValues ] = useState( {
		kind: rule?.kind ?? kind,
		name: rule?.name ?? '',
		threshold: rule
			? String( rule.threshold )
			: kind === 'occupancy'
			? '80'
			: kind === 'early_bird'
			? '30'
			: '3',
		mode: rule?.mode ?? 'increase',
		amount_type: rule?.amount_type ?? 'percent',
		amount: rule ? String( rule.amount ) : '',
		room_type_ids: rule?.room_type_ids ?? [],
		rate_plan_ids: rule?.rate_plan_ids ?? [],
		is_active: rule?.is_active ?? true,
	} );
	const [ errors, setErrors ] = useState( {} );
	const [ confirm, setConfirm ] = useState( false );
	const set = ( key ) => ( value ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setErrors( ( prev ) => ( { ...prev, [ key ]: undefined } ) );
	};

	const occupancy = values.kind === 'occupancy';
	const early = values.kind === 'early_bird';
	const symbol = format.currencyInfo?.().symbol ?? '';

	const titles = {
		occupancy: __( 'Occupancy rule', 'radius-hotel-booking-pro' ),
		early_bird: __( 'Early-bird discount', 'radius-hotel-booking-pro' ),
		last_minute: __( 'Last-minute discount', 'radius-hotel-booking-pro' ),
	};

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			await save.mutateAsync( {
				id: rule?.id,
				values: {
					...values,
					// Booking-window rules are always discounts.
					mode: occupancy ? values.mode : 'decrease',
					// Raw text: an empty field gets the server's field error
					// instead of silently becoming 0.
					threshold: values.threshold.trim(),
					amount: values.amount.trim(),
				},
			} );
			toast.success(
				rule
					? __( 'Rule saved.', 'radius-hotel-booking-pro' )
					: __( 'Rule added.', 'radius-hotel-booking-pro' )
			);
			onClose();
		} catch ( error ) {
			const fields = Object.fromEntries(
				Object.entries( error?.errors || {} ).map( ( [ k, d ] ) => [
					k,
					d?.first_message,
				] )
			);
			setErrors( fields );
			if ( ! Object.keys( fields ).length ) {
				toastError( error );
			}
		}
	};

	return (
		<Drawer
			title={ rule ? rule.name : titles[ values.kind ] }
			description={
				occupancy
					? __(
							'The most specific rule applies, then the highest occupancy reached.',
							'radius-hotel-booking-pro'
					  )
					: __(
							'Counted in days between the booking and the arrival.',
							'radius-hotel-booking-pro'
					  )
			}
			onClose={ onClose }
			footer={
				readOnly ? null : (
					<>
						{ rule ? (
							<Button
								type="button"
								variant="ghost"
								className="text-destructive"
								onClick={ () => setConfirm( true ) }
							>
								<Trash2
									className="h-4 w-4"
									aria-hidden="true"
								/>
								{ __( 'Delete', 'radius-hotel-booking-pro' ) }
							</Button>
						) : (
							<span />
						) }
						<div className="flex gap-2">
							<Button
								type="button"
								variant="ghost"
								onClick={ onClose }
							>
								{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
							</Button>
							<Button
								type="submit"
								form="rtbp-adjusted-rule-form"
								disabled={ save.isPending }
							>
								{ __( 'Save', 'radius-hotel-booking-pro' ) }
							</Button>
						</div>
					</>
				)
			}
		>
			<form
				id="rtbp-adjusted-rule-form"
				onSubmit={ submit }
				noValidate
				className="space-y-5"
			>
				<fieldset
					disabled={ readOnly }
					className="m-0 space-y-5 border-0 p-0"
				>
					{ kinds && ! rule ? (
						<SegmentedControl
							label={ __(
								'Kind of discount',
								'radius-hotel-booking-pro'
							) }
							value={ values.kind }
							onChange={ ( value ) => {
								set( 'kind' )( value );
								set( 'threshold' )(
									value === 'early_bird' ? '30' : '3'
								);
							} }
							options={ [
								{
									value: 'early_bird',
									label: __(
										'Early bird',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'last_minute',
									label: __(
										'Last minute',
										'radius-hotel-booking-pro'
									),
								},
							] }
						/>
					) : null }

					<Field
						label={
							occupancy
								? __(
										'When the room type is at least (% full)',
										'radius-hotel-booking-pro'
								  )
								: early
								? __(
										'Booked at least (days before arrival)',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Booked at most (days before arrival)',
										'radius-hotel-booking-pro'
								  )
						}
						error={ errors.threshold }
						required
					>
						<Input
							type="number"
							inputMode={ occupancy ? 'decimal' : 'numeric' }
							min={ occupancy ? 1 : 0 }
							max={ occupancy ? 100 : 730 }
							step={ occupancy ? 'any' : 1 }
							value={ values.threshold }
							onChange={ ( e ) =>
								set( 'threshold' )( e.target.value )
							}
							className="w-32"
						/>
					</Field>

					{ occupancy ? (
						<SegmentedControl
							label={ __(
								'Adjustment',
								'radius-hotel-booking-pro'
							) }
							value={ values.mode }
							onChange={ readOnly ? () => {} : set( 'mode' ) }
							options={ [
								{
									value: 'increase',
									label: __(
										'Increase',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'decrease',
									label: __(
										'Decrease',
										'radius-hotel-booking-pro'
									),
								},
							] }
						/>
					) : null }
					<SegmentedControl
						label={ __(
							'Amount type',
							'radius-hotel-booking-pro'
						) }
						value={ values.amount_type }
						onChange={ readOnly ? () => {} : set( 'amount_type' ) }
						options={ [
							{
								value: 'percent',
								label: __(
									'Percentage',
									'radius-hotel-booking-pro'
								),
							},
							{
								value: 'fixed',
								label: __(
									'Fixed amount',
									'radius-hotel-booking-pro'
								),
							},
						] }
					/>
					<Field
						label={
							occupancy
								? __( 'Amount', 'radius-hotel-booking-pro' )
								: __( 'Discount', 'radius-hotel-booking-pro' )
						}
						error={ errors.amount }
						required
					>
						<div className="relative w-40">
							<Input
								type="number"
								inputMode="decimal"
								min={ 0 }
								step="any"
								value={ values.amount }
								onChange={ ( e ) =>
									set( 'amount' )( e.target.value )
								}
								className="pr-12 tabular-nums"
							/>
							<span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-muted-foreground">
								{ values.amount_type === 'percent'
									? '%'
									: symbol }
							</span>
						</div>
					</Field>

					<Field
						label={ __( 'Name', 'radius-hotel-booking-pro' ) }
						error={ errors.name }
					>
						<Input
							value={ values.name }
							onChange={ ( e ) =>
								set( 'name' )( e.target.value )
							}
							maxLength={ 120 }
							placeholder={ __(
								'Named automatically when empty',
								'radius-hotel-booking-pro'
							) }
						/>
					</Field>
					<ScopePicker
						ready={ ! scope.loading }
						legend={ __(
							'Room types',
							'radius-hotel-booking-pro'
						) }
						allText={ __(
							'None ticked: every room type.',
							'radius-hotel-booking-pro'
						) }
						options={ scope.roomTypes }
						value={ values.room_type_ids }
						onChange={ set( 'room_type_ids' ) }
						error={ errors.room_type_ids }
					/>
					<ScopePicker
						ready={ ! scope.loading }
						legend={ __(
							'Rate plans',
							'radius-hotel-booking-pro'
						) }
						allText={ __(
							'None ticked: every rate plan.',
							'radius-hotel-booking-pro'
						) }
						options={ scope.ratePlans }
						value={ values.rate_plan_ids }
						onChange={ set( 'rate_plan_ids' ) }
						error={ errors.rate_plan_ids }
					/>
					<label className="flex items-center justify-between gap-4">
						<span className="text-sm font-semibold text-heading">
							{ __( 'Active', 'radius-hotel-booking-pro' ) }
						</span>
						<Switch
							checked={ values.is_active }
							onCheckedChange={ set( 'is_active' ) }
							disabled={ readOnly }
						/>
					</label>
				</fieldset>
			</form>
			{ rule ? (
				<ConfirmDialog
					open={ confirm }
					onOpenChange={ setConfirm }
					title={ __(
						'Delete this rule?',
						'radius-hotel-booking-pro'
					) }
					description={ __(
						'Bookings keep their price.',
						'radius-hotel-booking-pro'
					) }
					confirmLabel={ __(
						'Delete rule',
						'radius-hotel-booking-pro'
					) }
					destructive
					onConfirm={ () =>
						remove.mutateAsync( rule.id ).then( () => {
							toast.success(
								__(
									'Rule deleted.',
									'radius-hotel-booking-pro'
								)
							);
							onClose();
						} )
					}
				/>
			) : null }
		</Drawer>
	);
}

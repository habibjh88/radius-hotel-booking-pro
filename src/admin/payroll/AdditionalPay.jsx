/**
 * The additional pay types of an employee (M15 T4c, 12.14), under the pay
 * profile on the *Pay* tab: which ones they can receive and their settings
 * (an overtime rate, yearly hours, the vacation pay method and percentage).
 * The amounts are entered on each pay run.
 */
import { useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Field,
	Input,
	Panel,
	SegmentedControl,
	Switch,
} from '@rtbp/ui';
import { toast, toastError } from '@rtbp/lib';

import { useSaveEmployeePayItems } from './api';

/**
 * Field labels and units, by type and field.
 *
 * @param {string} type  Type key.
 * @param {string} field Field key.
 * @return {{label: string, unit?: string, hint?: string}} Text.
 */
const fieldText = ( type, field ) => {
	if ( 'rate' === field ) {
		return {
			label: __( 'Overtime rate', 'radius-hotel-booking-pro' ),
			unit: __( 'per hour', 'radius-hotel-booking-pro' ),
			hint: __(
				'Empty: the employee’s hourly rate.',
				'radius-hotel-booking-pro'
			),
		};
	}
	if ( 'max' === field ) {
		return 'unpaid_time_off' === type
			? {
					label: __( 'Most hours', 'radius-hotel-booking-pro' ),
					unit: __( 'hours', 'radius-hotel-booking-pro' ),
					hint: __( '0: no limit.', 'radius-hotel-booking-pro' ),
			  }
			: {
					label: __( 'Hours allowed', 'radius-hotel-booking-pro' ),
					unit: __( 'hours a year', 'radius-hotel-booking-pro' ),
			  };
	}
	if ( 'percent' === field ) {
		return {
			label: __( 'Percentage', 'radius-hotel-booking-pro' ),
			unit: '%',
		};
	}
	if ( 'method' === field ) {
		return { label: __( 'How', 'radius-hotel-booking-pro' ) };
	}
	return {
		label: __( 'Earnings that count', 'radius-hotel-booking-pro' ),
	};
};

const BASE_LABELS = () => ( {
	regular: __( 'Regular pay', 'radius-hotel-booking-pro' ),
	overtime: __( 'Overtime', 'radius-hotel-booking-pro' ),
	bonus: __( 'Bonus', 'radius-hotel-booking-pro' ),
	commission: __( 'Commission', 'radius-hotel-booking-pro' ),
} );

/**
 * What a run asks for, per type.
 *
 * @param {string} entry `hours`, `amount` or `none`.
 * @return {string} Hint.
 */
const entryHint = ( entry ) =>
	'hours' === entry
		? __( 'Hours are entered on each pay run.', 'radius-hotel-booking-pro' )
		: 'amount' === entry
		? __(
				'The amount is entered on each pay run.',
				'radius-hotel-booking-pro'
		  )
		: __( 'Worked out on each pay run.', 'radius-hotel-booking-pro' );

/**
 * A field error from the API client as text.
 *
 * @param {*} value Error.
 * @return {string} Message.
 */
const message = ( value ) =>
	value?.first_message ??
	( Array.isArray( value ) ? value[ 0 ] : String( value ) );

/**
 * @param {Object}  props            Props.
 * @param {number}  props.employeeId Pro employee id.
 * @param {Object}  props.saved      Saved items: type => settings.
 * @param {Array}   props.types      The catalogue.
 * @param {boolean} props.canEdit    May change them.
 * @return {JSX.Element} Panel.
 */
export default function AdditionalPay( { employeeId, saved, types, canEdit } ) {
	const [ items, setItems ] = useState( saved || {} );
	const [ errors, setErrors ] = useState( {} );
	const save = useSaveEmployeePayItems( employeeId );
	const bases = BASE_LABELS();

	useEffect( () => {
		setItems( saved || {} );
		setErrors( {} );
	}, [ saved ] );

	const dirty = JSON.stringify( items ) !== JSON.stringify( saved || {} );

	const toggle = ( type, on ) => {
		setItems( ( current ) => {
			const next = { ...current };
			if ( on ) {
				next[ type.key ] =
					'vacation_pay' === type.key
						? {
								method: 'pay',
								percent: '',
								payitems: [ 'regular' ],
						  }
						: {};
			} else {
				delete next[ type.key ];
			}
			return next;
		} );
	};
	const setField = ( type, field, value ) => {
		setItems( ( current ) => ( {
			...current,
			[ type ]: { ...current[ type ], [ field ]: value },
		} ) );
		setErrors( ( current ) => ( {
			...current,
			[ `items.${ type }.${ field }` ]: undefined,
		} ) );
	};

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			const response = await save.mutateAsync( items );
			toast.success( response.message );
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

	const input = ( type, field, def ) => {
		const text = fieldText( type.key, field );
		const value = items[ type.key ]?.[ field ];
		const error = errors[ `items.${ type.key }.${ field }` ];
		if ( 'enum' === def.type ) {
			return (
				<Field key={ field } label={ text.label } error={ error }>
					<SegmentedControl
						label={ text.label }
						value={ value || 'pay' }
						onChange={ ( v ) =>
							canEdit && setField( type.key, field, v )
						}
						options={ [
							{
								value: 'pay',
								label: __(
									'Add to every pay',
									'radius-hotel-booking-pro'
								),
							},
							{
								value: 'retain',
								label: __(
									'Keep through the year',
									'radius-hotel-booking-pro'
								),
							},
						] }
					/>
				</Field>
			);
		}
		if ( 'set' === def.type ) {
			const chosen = value || [ 'regular' ];
			return (
				<Field key={ field } label={ text.label } error={ error }>
					<div className="flex flex-wrap gap-x-4 gap-y-2">
						{ def.options.map( ( base ) => (
							<label
								key={ base }
								className="flex items-center gap-2 text-sm"
							>
								<input
									type="checkbox"
									checked={ chosen.includes( base ) }
									disabled={ ! canEdit || 'regular' === base }
									onChange={ ( e ) =>
										setField(
											type.key,
											field,
											e.target.checked
												? [ ...chosen, base ]
												: chosen.filter(
														( b ) => b !== base
												  )
										)
									}
								/>
								{ bases[ base ] || base }
							</label>
						) ) }
					</div>
				</Field>
			);
		}
		return (
			<Field
				key={ field }
				label={ text.label }
				description={ text.hint }
				error={ error }
				required={ def.required }
			>
				<div className="flex items-center gap-2">
					<Input
						type="number"
						inputMode="decimal"
						min={ def.min ?? 0 }
						max={ def.max }
						step={ 'int' === def.type ? '1' : 'any' }
						className="w-28"
						value={ value ?? '' }
						disabled={ ! canEdit }
						onChange={ ( e ) =>
							setField( type.key, field, e.target.value )
						}
					/>
					{ text.unit ? (
						<span className="text-sm text-muted-foreground">
							{ text.unit }
						</span>
					) : null }
				</div>
			</Field>
		);
	};

	return (
		<Panel>
			<form className="space-y-4" onSubmit={ submit } noValidate>
				<div>
					<h3 className="m-0 text-base font-semibold text-heading">
						{ __( 'Additional pay', 'radius-hotel-booking-pro' ) }
					</h3>
					<p className="m-0 text-sm text-muted-foreground">
						{ __(
							'What this employee can receive on top of their pay. Unpaid time off is taken off it.',
							'radius-hotel-booking-pro'
						) }
					</p>
				</div>
				<div className="space-y-2">
					{ types.map( ( type ) => {
						const on = Object.prototype.hasOwnProperty.call(
							items,
							type.key
						);
						const fields = Object.entries( type.fields || {} );
						return (
							<div
								key={ type.key }
								className="space-y-3 rounded-lg border px-3 py-2"
							>
								<label className="flex items-center justify-between gap-3">
									<span className="min-w-0">
										<span className="block text-sm font-medium">
											{ type.label }
										</span>
										{ on ? (
											<span className="block text-xs text-muted-foreground">
												{ entryHint( type.entry ) }
											</span>
										) : null }
									</span>
									<Switch
										checked={ on }
										disabled={ ! canEdit }
										onCheckedChange={ ( v ) =>
											toggle( type, v )
										}
										aria-label={ sprintf(
											/* translators: %s: pay type. */
											__(
												'Pays %s',
												'radius-hotel-booking-pro'
											),
											type.label
										) }
									/>
								</label>
								{ on && fields.length ? (
									<div className="grid gap-3 pb-1 sm:grid-cols-2">
										{ fields.map( ( [ field, def ] ) =>
											input( type, field, def )
										) }
									</div>
								) : null }
							</div>
						);
					} ) }
				</div>
				{ canEdit ? (
					<div className="flex justify-end gap-2">
						<Button
							type="button"
							variant="ghost"
							disabled={ ! dirty }
							onClick={ () => {
								setItems( saved || {} );
								setErrors( {} );
							} }
						>
							{ __( 'Undo changes', 'radius-hotel-booking-pro' ) }
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
	);
}

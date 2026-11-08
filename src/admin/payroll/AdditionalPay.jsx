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
import { format, toast, toastError } from '@rtbp/lib';

import { useSaveEmployeePayItems } from './api';

/**
 * Field labels and units, by type and field.
 *
 * @param {string} type     Type key.
 * @param {string} field    Field key.
 * @param {Object} settings The type's settings (the unit changes the words).
 * @return {{label: string, unit?: string, hint?: string}} Text.
 */
const fieldText = ( type, field, settings = {} ) => {
	const days = 'day' === settings.unit;
	if ( 'unit' === field ) {
		return { label: __( 'Counted', 'radius-hotel-booking-pro' ) };
	}
	if ( 'rate' === field ) {
		return {
			label: __( 'Overtime rate', 'radius-hotel-booking-pro' ),
			unit: days
				? __( 'per day', 'radius-hotel-booking-pro' )
				: __( 'per hour', 'radius-hotel-booking-pro' ),
			hint: days
				? __(
						'Empty: the employee’s hourly rate × a day’s hours on their work shift.',
						'radius-hotel-booking-pro'
				  )
				: __(
						'Empty: the employee’s hourly rate.',
						'radius-hotel-booking-pro'
				  ),
		};
	}
	if ( 'max' === field ) {
		if ( 'unpaid_time_off' === type ) {
			return {
				label: days
					? __( 'Most days', 'radius-hotel-booking-pro' )
					: __( 'Most hours', 'radius-hotel-booking-pro' ),
				unit: days
					? __( 'days', 'radius-hotel-booking-pro' )
					: __( 'hours', 'radius-hotel-booking-pro' ),
				hint: __( '0: no limit.', 'radius-hotel-booking-pro' ),
			};
		}
		return days
			? {
					label: __( 'Days allowed', 'radius-hotel-booking-pro' ),
					unit: __( 'days a year', 'radius-hotel-booking-pro' ),
			  }
			: {
					label: __( 'Hours allowed', 'radius-hotel-booking-pro' ),
					unit: __( 'hours a year', 'radius-hotel-booking-pro' ),
			  };
	}
	// Paid each period (R2-T3, R2-T5) or worked out from earnings (R2-T2, R2-T4).
	const accrues = [ 'vacation_pay', 'gratification' ].includes( type );
	if ( 'calc' === field ) {
		return { label: __( 'Paid as', 'radius-hotel-booking-pro' ) };
	}
	if ( 'percent' === field ) {
		return {
			label: accrues
				? __( 'Percentage', 'radius-hotel-booking-pro' )
				: __( 'Percentage of base pay', 'radius-hotel-booking-pro' ),
			unit: '%',
			hint: accrues
				? __(
						'Of the earnings that count, below.',
						'radius-hotel-booking-pro'
				  )
				: __(
						'Each pay period. A pay run can change it.',
						'radius-hotel-booking-pro'
				  ),
		};
	}
	if ( 'amount' === field ) {
		return {
			label: __( 'Amount', 'radius-hotel-booking-pro' ),
			unit: sprintf(
				/* translators: %s: currency symbol. */
				__( '%s each pay period', 'radius-hotel-booking-pro' ),
				format.currencyInfo?.().symbol || ''
			),
			hint:
				'vacation_pay' === type
					? ''
					: __(
							'Empty: typed on each pay run. A pay run can change it.',
							'radius-hotel-booking-pro'
					  ),
		};
	}
	if ( 'method' === field ) {
		return { label: __( 'How', 'radius-hotel-booking-pro' ) };
	}
	return {
		label: __( 'Earnings that count', 'radius-hotel-booking-pro' ),
	};
};

/**
 * The choices of each switch field, by field.
 *
 * @return {Object<string, Object<string, string>>} Field => value => label.
 */
const CHOICES = () => ( {
	method: {
		pay: __( 'Add to every pay', 'radius-hotel-booking-pro' ),
		retain: __( 'Keep through the year', 'radius-hotel-booking-pro' ),
	},
	unit: {
		hour: __( 'Per hour', 'radius-hotel-booking-pro' ),
		day: __( 'Per day', 'radius-hotel-booking-pro' ),
	},
	calc: {
		amount: __( 'An amount', 'radius-hotel-booking-pro' ),
		percent: __( 'A percentage', 'radius-hotel-booking-pro' ),
	},
} );

/**
 * Whether a field applies to the type's current choices (its `when`).
 *
 * @param {Object} def      Field definition.
 * @param {Object} fields   The type's field definitions.
 * @param {Object} settings The type's settings.
 * @return {boolean} Shown.
 */
const applies = ( def, fields, settings = {} ) =>
	Object.entries( def.when || {} ).every(
		( [ other, wanted ] ) =>
			( settings[ other ] || fields[ other ]?.default ) === wanted
	);

const BASE_LABELS = () => ( {
	regular: __( 'Regular pay', 'radius-hotel-booking-pro' ),
	overtime: __( 'Overtime', 'radius-hotel-booking-pro' ),
	bonus: __( 'Bonus', 'radius-hotel-booking-pro' ),
	commission: __( 'Commission', 'radius-hotel-booking-pro' ),
	transportation: __( 'Transport allowance', 'radius-hotel-booking-pro' ),
	gratification: __( 'Gratification', 'radius-hotel-booking-pro' ),
	bonus_rules: __(
		'Bonus rules, such as seniority',
		'radius-hotel-booking-pro'
	),
} );

/**
 * What a run asks for, per type.
 *
 * @param {string} entry    `hours`, `amount` or `none`.
 * @param {Object} settings The type's settings (its unit).
 * @return {string} Hint.
 */
const entryHint = ( entry, settings = {} ) =>
	'hours' === entry
		? 'day' === settings.unit
			? __(
					'Days are entered on each pay run.',
					'radius-hotel-booking-pro'
			  )
			: __(
					'Hours are entered on each pay run.',
					'radius-hotel-booking-pro'
			  )
		: 'amount' === entry
		? settings.amount || settings.percent
			? __(
					'Paid each pay period. A pay run can change it.',
					'radius-hotel-booking-pro'
			  )
			: __(
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
	const choices = CHOICES();

	useEffect( () => {
		setItems( saved || {} );
		setErrors( {} );
	}, [ saved ] );

	const dirty = JSON.stringify( items ) !== JSON.stringify( saved || {} );

	const toggle = ( type, on ) => {
		setItems( ( current ) => {
			const next = { ...current };
			if ( on ) {
				next[ type.key ] = [ 'vacation_pay', 'gratification' ].includes(
					type.key
				)
					? {
							method: 'pay',
							calc:
								'vacation_pay' === type.key
									? 'percent'
									: 'amount',
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
		const text = fieldText( type.key, field, items[ type.key ] );
		const value = items[ type.key ]?.[ field ];
		const error = errors[ `items.${ type.key }.${ field }` ];
		if ( 'enum' === def.type ) {
			const labels = choices[ field ] || {};
			return (
				<Field key={ field } label={ text.label } error={ error }>
					<SegmentedControl
						label={ text.label }
						value={
							value ||
							def.default ||
							( 'method' === field ? 'pay' : def.options[ 0 ] )
						}
						onChange={ ( v ) =>
							canEdit && setField( type.key, field, v )
						}
						options={ def.options.map( ( option ) => ( {
							value: option,
							label: labels[ option ] || option,
						} ) ) }
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
												{ entryHint(
													type.entry,
													items[ type.key ]
												) }
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
										{ fields
											.filter( ( [ , def ] ) =>
												applies(
													def,
													type.fields,
													items[ type.key ]
												)
											)
											.map( ( [ field, def ] ) =>
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

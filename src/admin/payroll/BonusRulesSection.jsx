/**
 * Settings → Payroll → Bonuses (R1-T17): bonuses every pay run adds on its
 * own — a fixed amount or a percentage of base pay (or a kind a country
 * add-on brings, such as the Côte d'Ivoire seniority bonus), taxable or not,
 * for every employee or chosen ones. A run line still takes one-off bonuses
 * typed in by hand.
 */
import { __ } from '@wordpress/i18n';
import { Plus, Trash2 } from 'lucide-react';
import {
	Button,
	Field,
	Input,
	SegmentedControl,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	SettingsSection,
	Switch,
} from '@rtbp/ui';

import { useEmployees } from '../staff/api';
import EmployeeMultiSelect from './EmployeeMultiSelect';
import useConfirmRemove from './useConfirmRemove';

const NEW_RULE = {
	key: '',
	label: '',
	kind: 'fixed',
	amount: 0,
	percent: 0,
	taxable: true,
	applies: 'all',
	employees: [],
	active: true,
};

/**
 * A number that keeps what is typed while it is not a number yet.
 *
 * @param {Object}   props          Props.
 * @param {*}        props.value    Value.
 * @param {Function} props.onChange Called with a number, or the raw text.
 * @return {JSX.Element} Input.
 */
function Num( { value, onChange, ...props } ) {
	return (
		<Input
			type="number"
			inputMode="decimal"
			step="any"
			min={ 0 }
			value={ value ?? '' }
			onChange={ ( event ) => {
				const raw = event.target.value;
				onChange(
					raw === '' || Number.isNaN( Number( raw ) )
						? raw
						: Number( raw )
				);
			} }
			{ ...props }
		/>
	);
}

/**
 * @param {Object}   props          Props.
 * @param {Array}    props.rules    The `bonusRules` setting.
 * @param {Function} props.onChange Called with the new list.
 * @param {string}   props.error    Server message.
 * @return {JSX.Element} Section.
 */
export default function BonusRulesSection( { rules = [], onChange, error } ) {
	const kinds = window.rtbpPro?.bonusKinds || {
		fixed: __( 'Fixed amount each pay period', 'radius-hotel-booking-pro' ),
		percent: __( 'Percentage of base pay', 'radius-hotel-booking-pro' ),
	};
	const staff = useEmployees( { active: 'active', per_page: 100 } );
	const employees = staff.data?.employees || [];
	const [ confirmRemove, removeDialog ] = useConfirmRemove();
	const change = ( index, fields ) =>
		onChange(
			rules.map( ( row, i ) =>
				i === index ? { ...row, ...fields } : row
			)
		);

	return (
		<SettingsSection
			title={ __( 'Bonuses', 'radius-hotel-booking-pro' ) }
			description={ __(
				'Added by every pay run on its own. A taxable bonus counts in gross pay, before contributions and tax; a non-taxable one is added to net pay. One-off bonuses are still typed into a pay run line.',
				'radius-hotel-booking-pro'
			) }
		>
			{ error ? (
				<p className="m-0 text-sm text-destructive" role="alert">
					{ error }
				</p>
			) : null }
			{ rules.length ? (
				<div className="space-y-3">
					{ rules.map( ( row, index ) => (
						<div
							key={ row.key || index }
							className="space-y-3 rounded-lg border border-border p-3"
						>
							<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
								<Field
									label={ __(
										'Name',
										'radius-hotel-booking-pro'
									) }
								>
									<Input
										value={ row.label }
										maxLength={ 60 }
										placeholder={ __(
											'e.g. Transport allowance',
											'radius-hotel-booking-pro'
										) }
										onChange={ ( e ) =>
											change( index, {
												label: e.target.value,
											} )
										}
									/>
								</Field>
								<Field
									label={ __(
										'Calculated as',
										'radius-hotel-booking-pro'
									) }
								>
									<Select
										value={ row.kind }
										onValueChange={ ( kind ) =>
											change( index, { kind } )
										}
									>
										<SelectTrigger>
											<SelectValue />
										</SelectTrigger>
										<SelectContent>
											{ Object.entries( kinds ).map(
												( [ key, label ] ) => (
													<SelectItem
														key={ key }
														value={ key }
													>
														{ label }
													</SelectItem>
												)
											) }
										</SelectContent>
									</Select>
								</Field>
								{ 'fixed' === row.kind ? (
									<Field
										label={ __(
											'Amount each period',
											'radius-hotel-booking-pro'
										) }
									>
										<Num
											value={ row.amount }
											onChange={ ( amount ) =>
												change( index, { amount } )
											}
										/>
									</Field>
								) : null }
								{ 'percent' === row.kind ? (
									<Field
										label={ __(
											'Percentage of base pay',
											'radius-hotel-booking-pro'
										) }
									>
										<Num
											value={ row.percent }
											max={ 100 }
											onChange={ ( percent ) =>
												change( index, { percent } )
											}
										/>
									</Field>
								) : null }
							</div>

							<div className="flex flex-wrap items-center gap-x-6 gap-y-3">
								<label className="flex items-center gap-2 text-sm text-heading">
									<Switch
										checked={ Boolean( row.taxable ) }
										onCheckedChange={ ( taxable ) =>
											change( index, { taxable } )
										}
									/>
									{ __(
										'Taxable',
										'radius-hotel-booking-pro'
									) }
								</label>
								<label className="flex items-center gap-2 text-sm text-heading">
									<Switch
										checked={ false !== row.active }
										onCheckedChange={ ( active ) =>
											change( index, { active } )
										}
									/>
									{ __( 'On', 'radius-hotel-booking-pro' ) }
								</label>
								<SegmentedControl
									label={ __(
										'Applies to',
										'radius-hotel-booking-pro'
									) }
									value={ row.applies || 'all' }
									onChange={ ( applies ) =>
										change( index, { applies } )
									}
									options={ [
										{
											value: 'all',
											label: __(
												'All employees',
												'radius-hotel-booking-pro'
											),
										},
										{
											value: 'selected',
											label: __(
												'Chosen employees',
												'radius-hotel-booking-pro'
											),
										},
									] }
								/>
								<Button
									type="button"
									variant="ghost"
									size="sm"
									className="ml-auto text-destructive"
									onClick={ () =>
										confirmRemove(
											row.label ||
												__(
													'Untitled bonus',
													'radius-hotel-booking-pro'
												),
											() =>
												onChange(
													rules.filter(
														( _, i ) => i !== index
													)
												)
										)
									}
								>
									<Trash2
										className="h-4 w-4"
										aria-hidden="true"
									/>
									{ __(
										'Remove',
										'radius-hotel-booking-pro'
									) }
								</Button>
							</div>

							{ 'selected' === row.applies ? (
								<EmployeeMultiSelect
									options={ employees }
									loading={ staff.isPending }
									value={ row.employees || [] }
									onChange={ ( ids ) =>
										change( index, { employees: ids } )
									}
								/>
							) : null }
						</div>
					) ) }
				</div>
			) : (
				<p className="m-0 text-sm text-muted-foreground">
					{ __(
						'No bonus yet. Add one for allowances or bonuses every employee (or a group) gets each pay period.',
						'radius-hotel-booking-pro'
					) }
				</p>
			) }
			<Button
				type="button"
				variant="outline"
				onClick={ () => onChange( [ ...rules, { ...NEW_RULE } ] ) }
				disabled={ rules.length >= 20 }
			>
				<Plus className="h-4 w-4" aria-hidden="true" />
				{ __( 'Add a bonus', 'radius-hotel-booking-pro' ) }
			</Button>
			{ removeDialog }
		</SettingsSection>
	);
}

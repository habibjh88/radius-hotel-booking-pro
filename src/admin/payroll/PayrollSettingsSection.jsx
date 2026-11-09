/**
 * Settings → Payroll (M15): the yearly vacation quota and, for the built-in
 * calculator, the hotel's social contributions and income tax brackets. A
 * country add-on that brings its own calculator (Residence TATA: Côte
 * d'Ivoire) replaces the rates; the screen then says which one is used.
 * The business details on payslips are Settings → General's (15.21).
 */
import { __, sprintf } from '@wordpress/i18n';
import { Field, NumberInput, SettingsSection } from '@rtbp/ui';

import BonusRulesSection from './BonusRulesSection';
import ContributionsEditor from './ContributionsEditor';
import IncomeTaxEditor from './IncomeTaxEditor';

/**
 * @param {Object}   props          Props from the free Settings screen.
 * @param {Object}   props.value    Section values.
 * @param {Function} props.setField `setField( key )( value )`.
 * @param {Object}   props.errors   Key => server message.
 * @param {Object}   props.schema   Key => schema.
 * @return {JSX.Element} Tab.
 */
export default function PayrollSettingsSection( {
	value,
	setField,
	errors,
	schema,
} ) {
	const calculator = window.radius_hotel_booking_param
		?.payroll_calculator || { built_in: true };
	const contributions = Array.isArray( value.contributions )
		? value.contributions
		: [];

	return (
		<>
			{ calculator.built_in || calculator.contributions ? (
				<SettingsSection
					title={ __(
						'Social contributions',
						'radius-hotel-booking-pro'
					) }
					description={
						calculator.built_in
							? __(
									'Pension, health, unemployment or other contributions, as a percentage of taxable pay. A ceiling limits the monthly pay it applies to. Paid by the employee (taken from pay) or by the hotel (added to its cost).',
									'radius-hotel-booking-pro'
							  )
							: sprintf(
									/* translators: %s: the calculator's name, e.g. "Côte d'Ivoire (CNPS, IS, CN, IGR)". */
									__(
										'Used by %s: each contribution as a percentage of pay, up to its monthly ceiling, paid by the employee (taken from pay) or by the hotel (added to its cost).',
										'radius-hotel-booking-pro'
									),
									calculator.label
							  )
					}
				>
					<ContributionsEditor
						value={ contributions }
						onChange={ setField( 'contributions' ) }
						error={ errors.contributions }
						presets={ Boolean( calculator.built_in ) }
					/>
				</SettingsSection>
			) : null }

			{ calculator.built_in ? (
				<SettingsSection
					title={ __( 'Income tax', 'radius-hotel-booking-pro' ) }
					description={ __(
						'Tax withheld from pay, by monthly brackets. Brackets apply to monthly taxable pay; weekly and other pay periods are converted.',
						'radius-hotel-booking-pro'
					) }
				>
					<IncomeTaxEditor
						value={ value }
						setField={ setField }
						errors={ errors }
					/>
				</SettingsSection>
			) : (
				<SettingsSection
					title={ __( 'Taxes', 'radius-hotel-booking-pro' ) }
				>
					<p className="m-0 text-sm text-muted-foreground">
						{ sprintf(
							/* translators: %s: the calculator's name, e.g. "Côte d'Ivoire (CNPS, IS, CN, IGR)". */
							__(
								'Worked out by: %s. Its tax figures are in the settings tab of the add-on that provides it.',
								'radius-hotel-booking-pro'
							),
							calculator.label
						) }
					</p>
				</SettingsSection>
			) }

			<BonusRulesSection
				rules={
					Array.isArray( value.bonusRules ) ? value.bonusRules : []
				}
				onChange={ setField( 'bonusRules' ) }
				error={ errors.bonusRules }
			/>

			<SettingsSection
				title={ __( 'Time off', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Day-off requests beyond the quota are refused. Sick leave and other absences do not count against it.',
					'radius-hotel-booking-pro'
				) }
			>
				<Field
					label={ __(
						'Vacation days a year',
						'radius-hotel-booking-pro'
					) }
					description={ __(
						'Per employee, per calendar year. 0 means unlimited.',
						'radius-hotel-booking-pro'
					) }
					error={ errors.vacationDaysYear }
				>
					<NumberInput
						value={ value.vacationDaysYear }
						onChange={ setField( 'vacationDaysYear' ) }
						unit={ __( 'days', 'radius-hotel-booking-pro' ) }
						limits={ schema.vacationDaysYear }
					/>
				</Field>
			</SettingsSection>
		</>
	);
}

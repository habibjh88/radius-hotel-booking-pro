/**
 * Settings → Payroll (M15): the yearly vacation quota and, for the built-in
 * calculator, the hotel's social contributions and income tax brackets. A
 * country add-on that brings its own calculator (Residence TATA: Côte
 * d'Ivoire) replaces the rates; the screen then says which one is used.
 * The business details on payslips are Settings → General's (15.21).
 */
import { __, sprintf } from '@wordpress/i18n';
import { Plus, Trash2 } from 'lucide-react';
import {
	Button,
	Field,
	Input,
	NumberInput,
	SegmentedControl,
	SettingsSection,
	ToggleRow,
} from '@rtbp/ui';

const NEW_CONTRIBUTION = {
	code: '',
	label: '',
	authority: '',
	side: 'employee',
	rate: 0,
	cap: 0,
};

/**
 * A number field that keeps what is typed while it is not a number yet.
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
	const brackets = Array.isArray( value.taxBrackets )
		? value.taxBrackets
		: [];
	const saveContributions = setField( 'contributions' );
	const saveBrackets = setField( 'taxBrackets' );
	const change = ( index, fields ) =>
		saveContributions(
			contributions.map( ( row, i ) =>
				i === index ? { ...row, ...fields } : row
			)
		);
	const changeBracket = ( index, fields ) =>
		saveBrackets(
			brackets.map( ( row, i ) =>
				i === index ? { ...row, ...fields } : row
			)
		);

	return (
		<>
			{ calculator.built_in ? (
				<>
					<SettingsSection
						title={ __(
							'Social contributions',
							'radius-hotel-booking-pro'
						) }
						description={ __(
							'Pension, health, unemployment or other contributions, as a percentage of taxable pay. A ceiling limits the monthly pay it applies to. Paid by the employee (taken from pay) or by the hotel (added to its cost).',
							'radius-hotel-booking-pro'
						) }
					>
						{ errors.contributions ? (
							<p
								className="m-0 text-sm text-destructive"
								role="alert"
							>
								{ errors.contributions }
							</p>
						) : null }
						{ contributions.length ? (
							<div className="space-y-3">
								{ contributions.map( ( row, index ) => (
									<div
										key={ index }
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
														'e.g. Pension fund',
														'radius-hotel-booking-pro'
													) }
													onChange={ ( e ) =>
														change( index, {
															label: e.target
																.value,
														} )
													}
												/>
											</Field>
											<Field
												label={ __(
													'Paid to',
													'radius-hotel-booking-pro'
												) }
											>
												<Input
													value={ row.authority }
													maxLength={ 40 }
													placeholder={ __(
														'e.g. Social security office',
														'radius-hotel-booking-pro'
													) }
													onChange={ ( e ) =>
														change( index, {
															authority:
																e.target.value,
														} )
													}
												/>
											</Field>
											<Field
												label={ __(
													'Code',
													'radius-hotel-booking-pro'
												) }
												description={ __(
													'Short, for reports. Made from the name when empty.',
													'radius-hotel-booking-pro'
												) }
											>
												<Input
													value={ row.code }
													maxLength={ 30 }
													onChange={ ( e ) =>
														change( index, {
															code: e.target
																.value,
														} )
													}
												/>
											</Field>
										</div>
										<div className="flex flex-wrap items-end gap-3">
											<Field
												label={ __(
													'Paid by',
													'radius-hotel-booking-pro'
												) }
											>
												<div>
													<SegmentedControl
														label={ __(
															'Paid by',
															'radius-hotel-booking-pro'
														) }
														value={ row.side }
														onChange={ ( side ) =>
															change( index, {
																side,
															} )
														}
														options={ [
															{
																value: 'employee',
																label: __(
																	'Employee',
																	'radius-hotel-booking-pro'
																),
															},
															{
																value: 'employer',
																label: __(
																	'Hotel',
																	'radius-hotel-booking-pro'
																),
															},
														] }
													/>
												</div>
											</Field>
											<Field
												label={ __(
													'Rate (%)',
													'radius-hotel-booking-pro'
												) }
											>
												<Num
													className="w-28"
													max={ 100 }
													value={ row.rate }
													onChange={ ( rate ) =>
														change( index, {
															rate,
														} )
													}
												/>
											</Field>
											<Field
												label={ __(
													'Monthly ceiling',
													'radius-hotel-booking-pro'
												) }
												description={ __(
													'0 = no ceiling.',
													'radius-hotel-booking-pro'
												) }
											>
												<Num
													className="w-40"
													value={ row.cap }
													onChange={ ( cap ) =>
														change( index, {
															cap,
														} )
													}
												/>
											</Field>
											<Button
												type="button"
												variant="ghost"
												size="icon"
												className="ml-auto text-muted-foreground hover:text-destructive"
												onClick={ () =>
													saveContributions(
														contributions.filter(
															( r, i ) =>
																i !== index
														)
													)
												}
												aria-label={ sprintf(
													/* translators: %s: contribution name. */
													__(
														'Remove %s',
														'radius-hotel-booking-pro'
													),
													row.label ||
														__(
															'this contribution',
															'radius-hotel-booking-pro'
														)
												) }
											>
												<Trash2 aria-hidden="true" />
											</Button>
										</div>
									</div>
								) ) }
							</div>
						) : (
							<p className="m-0 rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
								{ __(
									'No contributions: pay is taxed only by the income tax below, if any.',
									'radius-hotel-booking-pro'
								) }
							</p>
						) }
						<Button
							type="button"
							variant="outline"
							disabled={ contributions.length >= 20 }
							onClick={ () =>
								saveContributions( [
									...contributions,
									{ ...NEW_CONTRIBUTION },
								] )
							}
						>
							<Plus aria-hidden="true" />
							{ __(
								'Add a contribution',
								'radius-hotel-booking-pro'
							) }
						</Button>
					</SettingsSection>

					<SettingsSection
						title={ __( 'Income tax', 'radius-hotel-booking-pro' ) }
						description={ __(
							'Tax withheld from pay, by monthly brackets. Brackets apply to monthly taxable pay; weekly and other pay periods are converted.',
							'radius-hotel-booking-pro'
						) }
					>
						<ToggleRow
							label={ __(
								'Withhold income tax',
								'radius-hotel-booking-pro'
							) }
							checked={ Boolean( value.incomeTax ) }
							onChange={ setField( 'incomeTax' ) }
							error={ errors.incomeTax }
						>
							<div className="grid gap-4 sm:grid-cols-2">
								<Field
									label={ __(
										'Name on payslips',
										'radius-hotel-booking-pro'
									) }
									error={ errors.incomeTaxLabel }
								>
									<Input
										value={ value.incomeTaxLabel ?? '' }
										maxLength={ 60 }
										placeholder={ __(
											'Income tax',
											'radius-hotel-booking-pro'
										) }
										onChange={ ( e ) =>
											setField( 'incomeTaxLabel' )(
												e.target.value
											)
										}
									/>
								</Field>
								<Field
									label={ __(
										'Paid to',
										'radius-hotel-booking-pro'
									) }
									error={ errors.taxAuthority }
								>
									<Input
										value={ value.taxAuthority ?? '' }
										maxLength={ 40 }
										placeholder={ __(
											'Tax office',
											'radius-hotel-booking-pro'
										) }
										onChange={ ( e ) =>
											setField( 'taxAuthority' )(
												e.target.value
											)
										}
									/>
								</Field>
							</div>
							<Field
								label={ __(
									'How brackets apply',
									'radius-hotel-booking-pro'
								) }
							>
								<div>
									<SegmentedControl
										label={ __(
											'How brackets apply',
											'radius-hotel-booking-pro'
										) }
										value={ value.taxMode || 'progressive' }
										onChange={ setField( 'taxMode' ) }
										options={ [
											{
												value: 'progressive',
												label: __(
													'Each slice at its rate',
													'radius-hotel-booking-pro'
												),
											},
											{
												value: 'flat',
												label: __(
													'One rate on the whole pay',
													'radius-hotel-booking-pro'
												),
											},
										] }
									/>
								</div>
							</Field>
							<ToggleRow
								label={ __(
									'Take employee contributions off first',
									'radius-hotel-booking-pro'
								) }
								description={ __(
									'The tax is worked out on pay less the employee’s contributions.',
									'radius-hotel-booking-pro'
								) }
								checked={
									false !== value.taxAfterContributions
								}
								onChange={ setField( 'taxAfterContributions' ) }
							/>
							<div className="space-y-2">
								<p className="m-0 text-sm font-semibold text-heading">
									{ __(
										'Brackets',
										'radius-hotel-booking-pro'
									) }
								</p>
								{ errors.taxBrackets ? (
									<p
										className="m-0 text-sm text-destructive"
										role="alert"
									>
										{ errors.taxBrackets }
									</p>
								) : null }
								{ brackets.map( ( row, index ) => (
									<div
										key={ index }
										className="flex flex-wrap items-center gap-2"
									>
										<span className="text-sm text-muted-foreground">
											{ __(
												'Up to',
												'radius-hotel-booking-pro'
											) }
										</span>
										<Num
											className="w-40"
											value={ row.upTo }
											placeholder={ __(
												'and above',
												'radius-hotel-booking-pro'
											) }
											aria-label={ __(
												'Monthly pay up to',
												'radius-hotel-booking-pro'
											) }
											onChange={ ( upTo ) =>
												changeBracket( index, {
													upTo,
												} )
											}
										/>
										<Num
											className="w-28"
											max={ 100 }
											value={ row.rate }
											aria-label={ __(
												'Rate (%)',
												'radius-hotel-booking-pro'
											) }
											onChange={ ( rate ) =>
												changeBracket( index, {
													rate,
												} )
											}
										/>
										<span className="text-sm text-muted-foreground">
											%
										</span>
										<Button
											type="button"
											variant="ghost"
											size="icon"
											className="text-muted-foreground hover:text-destructive"
											onClick={ () =>
												saveBrackets(
													brackets.filter(
														( r, i ) => i !== index
													)
												)
											}
											aria-label={ __(
												'Remove this bracket',
												'radius-hotel-booking-pro'
											) }
										>
											<Trash2 aria-hidden="true" />
										</Button>
									</div>
								) ) }
								<p className="m-0 text-xs text-muted-foreground">
									{ __(
										'From the lowest. Leave the last limit empty for “and above”.',
										'radius-hotel-booking-pro'
									) }
								</p>
								<Button
									type="button"
									variant="outline"
									size="sm"
									disabled={ brackets.length >= 20 }
									onClick={ () =>
										saveBrackets( [
											...brackets,
											{ upTo: '', rate: 0 },
										] )
									}
								>
									<Plus aria-hidden="true" />
									{ __(
										'Add a bracket',
										'radius-hotel-booking-pro'
									) }
								</Button>
							</div>
						</ToggleRow>
					</SettingsSection>
				</>
			) : (
				<SettingsSection
					title={ __(
						'Taxes and contributions',
						'radius-hotel-booking-pro'
					) }
				>
					<p className="m-0 text-sm text-muted-foreground">
						{ sprintf(
							/* translators: %s: the calculator's name, e.g. "Côte d'Ivoire (CNPS, IS, CN, IGR)". */
							__(
								'Worked out by: %s. Its rates come with the add-on that provides it.',
								'radius-hotel-booking-pro'
							),
							calculator.label
						) }
					</p>
				</SettingsSection>
			) }

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

/**
 * Settings → Payroll → Income tax (built-in calculator): whether tax is
 * withheld, its name on payslips and who it is paid to, how the brackets
 * apply (each slice at its rate, or one rate on the whole pay), whether the
 * employee's contributions come off first, and the monthly brackets as a
 * table — each from the limit before it up to its own, the last "and above".
 */
import { __ } from '@wordpress/i18n';
import { Check } from 'lucide-react';
import { Field, Input, ToggleRow } from '@rtbp/ui';

import BracketsTable from './BracketsTable';

/**
 * One way the brackets apply, as a card to pick.
 *
 * @param {Object}   props             Props.
 * @param {boolean}  props.selected    Chosen.
 * @param {string}   props.title       Title.
 * @param {string}   props.description What it means.
 * @param {Function} props.onSelect    Choose it.
 * @return {JSX.Element} Card.
 */
function ModeCard( { selected, title, description, onSelect } ) {
	return (
		<button
			type="button"
			role="radio"
			aria-checked={ selected }
			onClick={ onSelect }
			className={ `flex w-full items-start gap-3 rounded-lg border bg-card p-3 text-left ${
				selected
					? 'border-primary bg-primary-softer ring-1 ring-primary'
					: 'border-border hover:bg-muted'
			}` }
		>
			<span
				className={ `inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
					selected
						? 'border-primary bg-primary text-white'
						: 'border-border bg-card'
				}` }
				style={ { marginTop: 2 } }
				aria-hidden="true"
			>
				{ selected ? (
					<Check style={ { width: 10, height: 10 } } />
				) : null }
			</span>
			<span className="min-w-0">
				<span className="block text-sm font-semibold text-heading">
					{ title }
				</span>
				<span className="block text-xs leading-snug text-muted-foreground">
					{ description }
				</span>
			</span>
		</button>
	);
}

/**
 * A sub-heading inside the section.
 *
 * @param {Object}      props          Props.
 * @param {string}      props.title    Title.
 * @param {string}      props.help     Help text.
 * @param {JSX.Element} props.children Content.
 * @return {JSX.Element} Block.
 */
function Block( { title, help, children } ) {
	return (
		<div className="space-y-3">
			<div>
				<p className="m-0 text-sm font-semibold text-heading">
					{ title }
				</p>
				{ help ? (
					<p className="m-0 text-xs text-muted-foreground">
						{ help }
					</p>
				) : null }
			</div>
			{ children }
		</div>
	);
}

/**
 * @param {Object}   props          Props from the Payroll settings section.
 * @param {Object}   props.value    Section values.
 * @param {Function} props.setField `setField( key )( value )`.
 * @param {Object}   props.errors   Key => server message.
 * @return {JSX.Element} Editor.
 */
export default function IncomeTaxEditor( { value, setField, errors } ) {
	const brackets = Array.isArray( value.taxBrackets )
		? value.taxBrackets
		: [];
	const mode = value.taxMode || 'progressive';

	return (
		<ToggleRow
			label={ __( 'Withhold income tax', 'radius-hotel-booking-pro' ) }
			description={ __(
				'Off: no income tax is taken from pay.',
				'radius-hotel-booking-pro'
			) }
			checked={ Boolean( value.incomeTax ) }
			onChange={ setField( 'incomeTax' ) }
			error={ errors.incomeTax }
		>
			<div className="space-y-5 rounded-lg border border-border bg-card p-4">
				<Block
					title={ __( 'On payslips', 'radius-hotel-booking-pro' ) }
					help={ __(
						'How the tax is named and who it is paid to (the tax report groups it by them).',
						'radius-hotel-booking-pro'
					) }
				>
					<div className="grid gap-4 sm:grid-cols-2">
						<Field
							label={ __( 'Name', 'radius-hotel-booking-pro' ) }
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
									setField( 'taxAuthority' )( e.target.value )
								}
							/>
						</Field>
					</div>
				</Block>

				<Block
					title={ __(
						'How the brackets apply',
						'radius-hotel-booking-pro'
					) }
				>
					<div
						className="grid gap-3 sm:grid-cols-2"
						role="radiogroup"
						aria-label={ __(
							'How the brackets apply',
							'radius-hotel-booking-pro'
						) }
					>
						<ModeCard
							selected={ 'flat' !== mode }
							onSelect={ () =>
								setField( 'taxMode' )( 'progressive' )
							}
							title={ __(
								'Each slice at its rate',
								'radius-hotel-booking-pro'
							) }
							description={ __(
								'Progressive: the pay inside each bracket is taxed at that bracket’s rate, and the slices are added up. Most countries work this way.',
								'radius-hotel-booking-pro'
							) }
						/>
						<ModeCard
							selected={ 'flat' === mode }
							onSelect={ () => setField( 'taxMode' )( 'flat' ) }
							title={ __(
								'One rate on the whole pay',
								'radius-hotel-booking-pro'
							) }
							description={ __(
								'The bracket the pay falls in sets one rate, applied to all of it.',
								'radius-hotel-booking-pro'
							) }
						/>
					</div>
				</Block>

				<div className="rounded-lg border border-border px-3 py-1">
					<ToggleRow
						label={ __(
							'Take employee contributions off first',
							'radius-hotel-booking-pro'
						) }
						description={ __(
							'The tax is worked out on pay less the employee’s social contributions.',
							'radius-hotel-booking-pro'
						) }
						checked={ false !== value.taxAfterContributions }
						onChange={ setField( 'taxAfterContributions' ) }
					/>
				</div>

				<Block
					title={ __(
						'Monthly brackets',
						'radius-hotel-booking-pro'
					) }
					help={ __(
						'From the lowest pay up; the last bracket covers all higher pay. Weekly and other pay periods are converted to a month. To add a limit, add a bracket after it.',
						'radius-hotel-booking-pro'
					) }
				>
					<BracketsTable
						value={ brackets }
						onChange={ setField( 'taxBrackets' ) }
						error={ errors.taxBrackets }
					/>
				</Block>
			</div>
		</ToggleRow>
	);
}

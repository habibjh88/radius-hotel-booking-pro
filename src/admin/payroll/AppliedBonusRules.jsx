/**
 * The bonus rules an employee gets, on their *Pay* tab (R2-T7): the rules
 * live in Settings → Payroll → Bonuses and apply to many employees, so the
 * Pay tab shows which ones reach this person and what they come to today
 * (seniority: its percentage for their years of service). Read-only.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Panel } from '@rtbp/ui';
import { format } from '@rtbp/lib';

/**
 * @param {Object} props       Props.
 * @param {Array}  props.rules `[ { key, label, kind: fixed|percent, value, taxable } ]`.
 * @return {JSX.Element} Panel.
 */
export default function AppliedBonusRules( { rules } ) {
	return (
		<Panel>
			<div className="space-y-3">
				<div>
					<h3 className="m-0 text-base font-semibold text-heading">
						{ __( 'Bonus rules', 'radius-hotel-booking-pro' ) }
					</h3>
					<p className="m-0 text-sm text-muted-foreground">
						{ __(
							'Bonuses every pay run adds on its own, such as seniority. Set them up in Settings → Payroll → Bonuses.',
							'radius-hotel-booking-pro'
						) }{ ' ' }
						<a
							href="#/settings?section=payroll"
							className="font-medium text-primary hover:underline"
						>
							{ __(
								'Open the bonus rules',
								'radius-hotel-booking-pro'
							) }
						</a>
					</p>
				</div>
				{ rules.length ? (
					<ul className="m-0 list-none divide-y divide-border rounded-lg border border-border p-0">
						{ rules.map( ( rule ) => (
							<li
								key={ rule.key }
								className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
							>
								<span className="min-w-0">
									<span className="block font-medium text-heading">
										{ rule.label }
									</span>
									<span className="block text-xs text-muted-foreground">
										{ rule.taxable
											? __(
													'Taxable: part of gross pay',
													'radius-hotel-booking-pro'
											  )
											: __(
													'Not taxable: added to net pay',
													'radius-hotel-booking-pro'
											  ) }
									</span>
								</span>
								<span className="shrink-0 font-semibold tabular-nums text-heading">
									{ 'percent' === rule.kind
										? sprintf(
												/* translators: %s: percentage. */
												__(
													'%s %% of base pay',
													'radius-hotel-booking-pro'
												),
												String( Number( rule.value ) )
										  )
										: sprintf(
												/* translators: %s: amount. */
												__(
													'%s each pay period',
													'radius-hotel-booking-pro'
												),
												format.formatMoney( rule.value )
										  ) }
								</span>
							</li>
						) ) }
					</ul>
				) : (
					<p className="m-0 text-sm text-muted-foreground">
						{ __(
							'No bonus rule applies to this employee today.',
							'radius-hotel-booking-pro'
						) }
					</p>
				) }
			</div>
		</Panel>
	);
}

/**
 * Booking-window rules (features 7.14, 7.15): an early-bird discount for
 * bookings made well ahead, a last-minute discount close to arrival. Only
 * one applies to a stay: the most specific, and early bird on a tie.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ChevronRight, Hourglass, Plus } from 'lucide-react';
import { Badge, Button, EmptyState, Panel, Skeleton } from '@rtbp/ui';

import AdjustedRuleEditor from './AdjustedRuleEditor';
import { describeScope } from './scope';
import { useRules, useScopeOptions } from './api';

/**
 * @param {Object}  props         Props.
 * @param {boolean} props.canEdit May change rules.
 * @return {JSX.Element} Tab.
 */
export default function BookingWindowTab( { canEdit } ) {
	const early = useRules( 'early_bird' );
	const late = useRules( 'last_minute' );
	const scope = useScopeOptions();
	const [ editing, setEditing ] = useState( null );
	const rules = [
		...[ ...( early.data || [] ) ].sort(
			( a, b ) => b.threshold - a.threshold
		),
		...[ ...( late.data || [] ) ].sort(
			( a, b ) => a.threshold - b.threshold
		),
	];
	const error = early.error || late.error;

	let body;
	if ( early.isPending || late.isPending ) {
		body = <Skeleton className="h-48 w-full rounded-xl" />;
	} else if ( error ) {
		body = (
			<p role="alert" className="m-0 text-sm text-destructive">
				{ error.message }
			</p>
		);
	} else if ( ! rules.length ) {
		body = (
			<EmptyState
				icon={ Hourglass }
				title={ __(
					'No booking-window discounts yet',
					'radius-hotel-booking-pro'
				) }
				description={ __(
					'For example, −10 % when booked 30 days or more before arrival.',
					'radius-hotel-booking-pro'
				) }
				className="border-0"
			/>
		);
	} else {
		body = (
			<ul className="m-0 list-none divide-y divide-border rounded-lg border border-border p-0">
				{ rules.map( ( rule ) => (
					<li key={ rule.id } className="m-0">
						<button
							type="button"
							onClick={ () => setEditing( rule ) }
							className="flex w-full items-center gap-3 bg-transparent px-4 py-3 text-left hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
						>
							<span className="min-w-0 flex-1 space-y-0.5">
								<span className="flex flex-wrap items-center gap-2">
									<span className="text-sm font-semibold text-heading">
										{ rule.name }
									</span>
									<Badge variant="secondary">
										{ rule.summary }
									</Badge>
									{ ! rule.is_active ? (
										<Badge variant="outline">
											{ __(
												'Off',
												'radius-hotel-booking-pro'
											) }
										</Badge>
									) : null }
								</span>
								<span className="block text-xs text-muted-foreground">
									{ rule.kind === 'early_bird'
										? sprintf(
												/* translators: %d: number of days. */
												__(
													'Early bird · booked %d days or more before arrival',
													'radius-hotel-booking-pro'
												),
												rule.threshold
										  )
										: sprintf(
												/* translators: %d: number of days. */
												__(
													'Last minute · booked %d days or less before arrival',
													'radius-hotel-booking-pro'
												),
												rule.threshold
										  ) }
								</span>
								<span className="block text-xs text-muted-foreground">
									{ describeScope( rule, scope ) }
								</span>
							</span>
							<ChevronRight
								className="h-4 w-4 shrink-0 text-muted-foreground"
								aria-hidden="true"
							/>
						</button>
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<>
			<Panel
				title={ __( 'Booking window', 'radius-hotel-booking-pro' ) }
				description={ __(
					'One discount per stay: the most specific rule, then early bird over last minute.',
					'radius-hotel-booking-pro'
				) }
				actions={
					canEdit ? (
						<Button size="sm" onClick={ () => setEditing( {} ) }>
							<Plus className="h-4 w-4" aria-hidden="true" />
							{ __( 'Add discount', 'radius-hotel-booking-pro' ) }
						</Button>
					) : null
				}
			>
				{ body }
			</Panel>
			{ editing ? (
				<AdjustedRuleEditor
					kind={ editing.kind ?? 'early_bird' }
					kinds
					rule={ editing.id ? editing : null }
					scope={ scope }
					readOnly={ ! canEdit }
					onClose={ () => setEditing( null ) }
				/>
			) : null }
		</>
	);
}

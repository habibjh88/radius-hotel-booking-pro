/**
 * Occupancy rules (feature 7.13): raise (or lower) prices once a room type
 * is at least N % full on a night.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ChevronRight, Gauge, Plus } from 'lucide-react';
import { Badge, Button, EmptyState, Panel, Skeleton } from '@rtbp/ui';

import AdjustedRuleEditor from './AdjustedRuleEditor';
import { describeScope } from './scope';
import { useRules, useScopeOptions } from './api';

/**
 * @param {Object}  props         Props.
 * @param {boolean} props.canEdit May change rules.
 * @return {JSX.Element} Tab.
 */
export default function OccupancyTab( { canEdit } ) {
	const rules = useRules( 'occupancy' );
	const scope = useScopeOptions();
	const [ editing, setEditing ] = useState( null );
	// Highest threshold first: the order the rules are tried in.
	const sorted = [ ...( rules.data || [] ) ].sort(
		( a, b ) => b.threshold - a.threshold
	);

	let body;
	if ( rules.isPending ) {
		body = <Skeleton className="h-48 w-full rounded-xl" />;
	} else if ( rules.error ) {
		body = (
			<p role="alert" className="m-0 text-sm text-destructive">
				{ rules.error.message }
			</p>
		);
	} else if ( ! sorted.length ) {
		body = (
			<EmptyState
				icon={ Gauge }
				title={ __(
					'No occupancy rules yet',
					'radius-hotel-booking-pro'
				) }
				description={ __(
					'For example, +15 % once a room type is 80 % full on a night.',
					'radius-hotel-booking-pro'
				) }
				className="border-0"
			/>
		);
	} else {
		body = (
			<ul className="m-0 list-none divide-y divide-border rounded-lg border border-border p-0">
				{ sorted.map( ( rule ) => (
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
									{ sprintf(
										/* translators: %s: occupancy percentage. */
										__(
											'From %s %% full',
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
				title={ __( 'Occupancy', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Occupancy is the room type’s booked rooms on the night, out of the rooms it can sell.',
					'radius-hotel-booking-pro'
				) }
				actions={
					canEdit ? (
						<Button size="sm" onClick={ () => setEditing( {} ) }>
							<Plus className="h-4 w-4" aria-hidden="true" />
							{ __( 'Add rule', 'radius-hotel-booking-pro' ) }
						</Button>
					) : null
				}
			>
				{ body }
			</Panel>
			{ editing ? (
				<AdjustedRuleEditor
					kind="occupancy"
					rule={ editing.id ? editing : null }
					scope={ scope }
					readOnly={ ! canEdit }
					onClose={ () => setEditing( null ) }
				/>
			) : null }
		</>
	);
}

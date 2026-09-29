/**
 * Pricing rules (`#/pricing`, M07 T5b–T6b): Seasonal, Occupancy, Booking
 * window and Templates tabs, in the order they apply. Everything here needs
 * `pricing.manage`, the key the server checks.
 */
import { Suspense } from 'react';
import { __ } from '@wordpress/i18n';
import { useSearchParams } from 'react-router-dom';
import { Skeleton, Tabs, TabsContent, TabsList, TabsTrigger } from '@rtbp/ui';
import { access } from '@rtbp/lib';

import BookingWindowTab from './BookingWindowTab';
import OccupancyTab from './OccupancyTab';
import SeasonalTab from './SeasonalTab';
import TemplatesTab from './TemplatesTab';

/**
 * @return {JSX.Element} Screen.
 */
export default function PricingScreen() {
	const [ params, setParams ] = useSearchParams();
	const tab = params.get( 'tab' ) || 'seasonal';
	// Opening the screen needs pricing.manage; "passcode" still edits (the PIN is asked on save).
	const canEdit = access?.useAccess
		? access.useAccess( 'pricing.manage' ) !== 'locked'
		: true;

	return (
		<div className="mx-auto max-w-4xl space-y-4">
			<Suspense fallback={ <Skeleton className="h-10 w-72" /> }>
				<Tabs
					value={ tab }
					onValueChange={ ( value ) =>
						setParams( { tab: value }, { replace: true } )
					}
				>
					{ /* Four tabs wrap to two lines on a phone. */ }
					<TabsList className="h-auto flex-wrap justify-start">
						<TabsTrigger value="seasonal">
							{ __( 'Seasonal', 'radius-hotel-booking-pro' ) }
						</TabsTrigger>
						<TabsTrigger value="occupancy">
							{ __( 'Occupancy', 'radius-hotel-booking-pro' ) }
						</TabsTrigger>
						<TabsTrigger value="window">
							{ __(
								'Booking window',
								'radius-hotel-booking-pro'
							) }
						</TabsTrigger>
						<TabsTrigger value="templates">
							{ __( 'Templates', 'radius-hotel-booking-pro' ) }
						</TabsTrigger>
					</TabsList>
					<TabsContent value="seasonal" className="mt-4">
						<SeasonalTab canEdit={ canEdit } />
					</TabsContent>
					<TabsContent value="occupancy" className="mt-4">
						<OccupancyTab canEdit={ canEdit } />
					</TabsContent>
					<TabsContent value="window" className="mt-4">
						<BookingWindowTab canEdit={ canEdit } />
					</TabsContent>
					<TabsContent value="templates" className="mt-4">
						<TemplatesTab canEdit={ canEdit } />
					</TabsContent>
				</Tabs>
			</Suspense>
		</div>
	);
}

/**
 * Developer check of the extension runtime (#/pro/runtime, WP_DEBUG only).
 *
 * Proves, from a real add-on bundle, that the seams Pro builds on work:
 * a screen added through `rtbp.admin.routes`, eager and lazy `@rtbp/ui`
 * components, the free API client, the shared React Query cache and the
 * `rtbp.api.error` filter. Not translated: developers only, like the free
 * UI kit.
 */
import { Suspense, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { addFilter, applyFilters, removeFilter } from '@wordpress/hooks';
import { ListChecks, Plug } from 'lucide-react';
import {
	Button,
	DateTime,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogHeader,
	DialogTitle,
	Money,
	Panel,
	StatCard,
	StatusBadge,
} from '@rtbp/ui';
import { api, toast, toastError } from '@rtbp/lib';

/**
 * Run a request whose error the `rtbp.api.error` filter recovers from, and
 * one it leaves alone.
 *
 * @return {Promise<string>} Result summary.
 */
async function checkErrorFilter() {
	let calls = 0;
	const handler = ( handled, error, { path, retry } ) => {
		if ( handled || ! path.startsWith( 'files/' ) ) {
			return handled;
		}
		calls++;
		// Recover the first path; retry the second once (it fails again).
		return path.endsWith( 'recover' )
			? Promise.resolve( { data: 'recovered', meta: {}, message: '' } )
			: retry( { headers: { 'X-Rtbp-Demo': 'retried' } } );
	};
	addFilter( 'rtbp.api.error', 'rtbp-pro/runtime-check', handler );
	try {
		const recovered = await api.get(
			'files/0000000000000000000000000000recover'
		);
		let thrown = '';
		try {
			await api.get( 'files/00000000000000000000000000000retry' );
		} catch ( error ) {
			thrown = error.status ? String( error.status ) : error.message;
		}
		return `recovered=${ recovered.data } · retried request threw ${ thrown } · filter calls=${ calls }`;
	} finally {
		removeFilter( 'rtbp.api.error', 'rtbp-pro/runtime-check' );
	}
}

/**
 * The booking panels, applied the way the booking screen (M03) will.
 *
 * @return {JSX.Element} Panels.
 */
function BookingPanelsPreview() {
	const [ id, setId ] = useState( '41' );
	const panels = ( applyFilters( 'rtbp.booking.panels', [], { booking: { id } } ) || [] )
		.slice()
		.sort( ( a, b ) => ( a.order ?? 50 ) - ( b.order ?? 50 ) );

	return (
		<Panel
			title="rtbp.booking.panels"
			description={ `Panels registered for booking #${ id } (${ panels.map( ( p ) => p.key ).join( ', ' ) || 'none' })` }
			actions={
				<input
					aria-label="Booking id"
					value={ id }
					onChange={ ( event ) => setId( event.target.value ) }
					className="w-20 rounded-md border border-border px-2 py-1 text-sm"
				/>
			}
		>
			{ panels.map( ( p ) => (
				<div key={ p.key } className="space-y-2">
					<div className="text-sm font-semibold text-heading">{ p.label }</div>
					{ p.render() }
				</div>
			) ) }
		</Panel>
	);
}

export default function RuntimeCheck() {
	const [ open, setOpen ] = useState( false );
	const [ result, setResult ] = useState( '' );

	// Same key as the free dashboard: one shared cache, so this is instant
	// after visiting the dashboard.
	const summary = useQuery( {
		queryKey: [ 'dashboard', 'summary' ],
		queryFn: () => api.get( 'dashboard/summary' ).then( ( r ) => r.data ),
	} );
	const setup = summary.data?.setup || [];

	return (
		<div className="grid gap-4 md:grid-cols-2">
			<Panel
				title="Shared query cache"
				description="The free dashboard summary, read through window.rtbp.ReactQuery"
			>
				<StatCard
					icon={ ListChecks }
					label="Setup steps done"
					value={ `${ setup.filter( ( s ) => s.done ).length } / ${
						setup.length
					}` }
					hint={ summary.isFetched ? 'Loaded' : 'Loading…' }
					loading={ summary.isPending }
				/>
			</Panel>
			<Panel
				title="Components"
				description="Eager and lazy parts from @rtbp/ui"
				actions={
					<Button size="sm" onClick={ () => setOpen( true ) }>
						Open dialog
					</Button>
				}
			>
				<div className="flex flex-wrap items-center gap-3">
					<StatusBadge domain="stay" value="confirmed" />
					<Money value={ 15000 } />
					<DateTime
						value="2026-10-01 20:00:00"
						end="2026-10-02 08:00:00"
					/>
				</div>
			</Panel>
			<Panel
				title="rtbp.api.error"
				description="A handler recovers one request and retries another once"
				actions={
					<Button
						size="sm"
						variant="outline"
						onClick={ () =>
							checkErrorFilter()
								.then( ( text ) => {
									setResult( text );
									toast.success( 'Error filter checked' );
								} )
								.catch( toastError )
						}
					>
						<Plug aria-hidden="true" />
						Run check
					</Button>
				}
			>
				<p
					className="m-0 text-sm text-muted-foreground"
					data-testid="api-error-result"
				>
					{ result || 'Not run yet.' }
				</p>
			</Panel>
			{ open ? (
				<Suspense fallback={ null }>
					<Dialog open={ open } onOpenChange={ setOpen }>
						<DialogContent className="rtbp-root">
							<DialogHeader>
								<DialogTitle>Lazy dialog</DialogTitle>
								<DialogDescription>
									Loaded on first use from the free
									plugin&apos;s chunk.
								</DialogDescription>
							</DialogHeader>
						</DialogContent>
					</Dialog>
				</Suspense>
			) : null }
			<BookingPanelsPreview />
		</div>
	);
}

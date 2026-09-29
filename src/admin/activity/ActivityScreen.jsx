/**
 * The activity log (M14 T3a, features 14.1, 14.5–14.10).
 */
import { Suspense, useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Archive, History } from 'lucide-react';
import {
	Badge,
	Button,
	DateRangePicker,
	EmptyState,
	Input,
	Panel,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
} from '@rtbp/ui';
import { access, format } from '@rtbp/lib';

import ArchivesDialog from './ArchivesDialog';
import EntryDialog from './EntryDialog';
import { useActivity, useActivityFilters } from './api';

const ALL = 'all';

/**
 * A filter Select with an "all" option.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.label    Accessible label and placeholder.
 * @param {string}   props.value    Value ('' = all).
 * @param {Function} props.onChange Called with the value ('' = all).
 * @param {Array}    props.options  `[ { value, label } ]`.
 * @return {JSX.Element} Select.
 */
function FilterSelect( { label, value, onChange, options } ) {
	return (
		<Suspense fallback={ <Skeleton className="h-9 w-40" /> }>
			<Select
				value={ value || ALL }
				onValueChange={ ( next ) => onChange( next === ALL ? '' : next ) }
			>
				<SelectTrigger className="w-full md:w-40" aria-label={ label }>
					<SelectValue placeholder={ label } />
				</SelectTrigger>
				<SelectContent className="rtbp-root">
					<SelectItem value={ ALL }>{ label }</SelectItem>
					{ options.map( ( option ) => (
						<SelectItem key={ option.value } value={ String( option.value ) }>
							{ option.label }
						</SelectItem>
					) ) }
				</SelectContent>
			</Select>
		</Suspense>
	);
}

/**
 * @return {JSX.Element} Screen.
 */
export default function ActivityScreen() {
	const [ filters, setFilters ] = useState( {
		user_id: '',
		kind: '',
		action: '',
		from: '',
		to: '',
		q: '',
		page: 1,
	} );
	const [ search, setSearch ] = useState( '' );
	const [ open, setOpen ] = useState( null );
	const [ archivesOpen, setArchivesOpen ] = useState( false );
	const canArchive = access?.canAccess
		? access.canAccess( 'activity.archive' )
		: true;
	const options = useActivityFilters();
	const list = useActivity( filters );

	const set = ( key ) => ( value ) =>
		setFilters( ( current ) => ( { ...current, [ key ]: value, page: 1 } ) );

	// Search after a short pause in typing.
	useEffect( () => {
		const timer = setTimeout( () => {
			setFilters( ( current ) =>
				current.q === search ? current : { ...current, q: search, page: 1 }
			);
		}, 350 );
		return () => clearTimeout( timer );
	}, [ search ] );

	const data = options.data;
	const kindLabel = ( key ) =>
		data?.kinds?.find( ( kind ) => kind.key === key )?.label ?? key;
	const actions = ( data?.actions ?? [] ).filter(
		( action ) => ! filters.kind || action.kind === filters.kind
	);
	const meta = list.data?.meta ?? {};

	return (
		<Panel
			title={ __( 'Activity log', 'radius-hotel-booking-pro' ) }
			description={ __(
				'Who did what, when, and from which device. Entries cannot be changed.',
				'radius-hotel-booking-pro'
			) }
			actions={
				canArchive ? (
					<Button size="sm" variant="outline" onClick={ () => setArchivesOpen( true ) }>
						<Archive aria-hidden="true" />
						{ __( 'Archives', 'radius-hotel-booking-pro' ) }
					</Button>
				) : null
			}
		>
			<div className="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
				<Input
					type="search"
					value={ search }
					onChange={ ( event ) => setSearch( event.target.value ) }
					placeholder={ __( 'Search the log', 'radius-hotel-booking-pro' ) }
					aria-label={ __( 'Search the log', 'radius-hotel-booking-pro' ) }
					className="w-full sm:w-64"
				/>
				<FilterSelect
					label={ __( 'All staff', 'radius-hotel-booking-pro' ) }
					value={ String( filters.user_id ) }
					onChange={ set( 'user_id' ) }
					options={ ( data?.people ?? [] ).map( ( person ) => ( {
						value: person.id,
						label: person.name,
					} ) ) }
				/>
				<FilterSelect
					label={ __( 'All kinds', 'radius-hotel-booking-pro' ) }
					value={ filters.kind }
					onChange={ ( value ) =>
						setFilters( ( current ) => ( {
							...current,
							kind: value,
							action: '',
							page: 1,
						} ) )
					}
					options={ ( data?.kinds ?? [] ).map( ( kind ) => ( {
						value: kind.key,
						label: kind.label,
					} ) ) }
				/>
				<FilterSelect
					label={ __( 'All actions', 'radius-hotel-booking-pro' ) }
					value={ filters.action }
					onChange={ set( 'action' ) }
					options={ actions.map( ( action ) => ( {
						value: action.key,
						label: action.label,
					} ) ) }
				/>
				<Suspense fallback={ <Skeleton className="h-9 w-40" /> }>
					<DateRangePicker
						value={ { from: filters.from, to: filters.to } }
						onChange={ ( range ) =>
							setFilters( ( current ) => ( {
								...current,
								from: range?.from ?? '',
								to: range?.to ?? '',
								page: 1,
							} ) )
						}
					/>
				</Suspense>
			</div>

			{ list.error ? (
				<div
					role="alert"
					className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-destructive-soft p-4 text-sm text-destructive"
				>
					<span>{ list.error.message }</span>
					<Button variant="outline" size="sm" onClick={ () => list.refetch() }>
						{ __( 'Try again', 'radius-hotel-booking-pro' ) }
					</Button>
				</div>
			) : null }

			{ list.isPending ? (
				<div className="space-y-2" role="status" aria-live="polite">
					{ [ 0, 1, 2, 3, 4 ].map( ( i ) => (
						<Skeleton key={ i } className="h-14 w-full rounded-md" />
					) ) }
				</div>
			) : null }

			{ list.data && ! list.data.rows.length ? (
				<EmptyState
					icon={ History }
					title={ __( 'Nothing recorded here', 'radius-hotel-booking-pro' ) }
					description={ __(
						'Change the filters, or come back once staff have used the dashboard.',
						'radius-hotel-booking-pro'
					) }
				/>
			) : null }

			{ list.data?.rows.length ? (
				<ul className="m-0 list-none divide-y divide-border p-0">
					{ list.data.rows.map( ( entry ) => (
						<li key={ entry.id }>
							<button
								type="button"
								onClick={ () => setOpen( entry ) }
								className="flex w-full flex-col gap-1 border-0 bg-transparent px-1 py-3 text-left hover:bg-muted md:flex-row md:items-center md:gap-4"
							>
								<span className="w-36 shrink-0 text-xs text-muted-foreground">
									{ format.formatDateTime( entry.time ) }
								</span>
								<span className="min-w-0 flex-1">
									<span className="block text-sm text-heading">
										{ entry.description }
									</span>
									<span className="block truncate text-xs text-muted-foreground">
										{ [ entry.user.name, entry.subject?.label, entry.device ]
											.filter( Boolean )
											.join( ' · ' ) }
									</span>
								</span>
								<span className="flex shrink-0 flex-wrap gap-1.5">
									<Badge variant="outline">{ kindLabel( entry.kind ) }</Badge>
									{ entry.repeat_count > 1 && entry.action === 'page.view' ? (
										<Badge variant="outline">
											{ sprintf(
												/* translators: %d: how many times it happened. */
												__( '×%d', 'radius-hotel-booking-pro' ),
												entry.repeat_count
											) }
										</Badge>
									) : null }
									{ entry.changes ? (
										<Badge variant="outline">
											{ __( 'Changes', 'radius-hotel-booking-pro' ) }
										</Badge>
									) : null }
								</span>
							</button>
						</li>
					) ) }
				</ul>
			) : null }

			{ meta.pages > 1 ? (
				<div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
					<span>
						{ sprintf(
							/* translators: 1: page, 2: pages, 3: entries. */
							__( 'Page %1$d of %2$d · %3$d entries', 'radius-hotel-booking-pro' ),
							meta.page,
							meta.pages,
							meta.total
						) }
					</span>
					<span className="flex gap-2">
						<Button
							size="sm"
							variant="outline"
							disabled={ meta.page <= 1 || list.isFetching }
							onClick={ () => setFilters( ( current ) => ( { ...current, page: meta.page - 1 } ) ) }
						>
							{ __( 'Newer', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button
							size="sm"
							variant="outline"
							disabled={ meta.page >= meta.pages || list.isFetching }
							onClick={ () =>
								setFilters( ( current ) => ( { ...current, page: meta.page + 1 } ) )
							}
						>
							{ __( 'Older', 'radius-hotel-booking-pro' ) }
						</Button>
					</span>
				</div>
			) : null }

			{ open ? <EntryDialog entry={ open } onClose={ () => setOpen( null ) } /> : null }
			{ archivesOpen ? <ArchivesDialog onClose={ () => setArchivesOpen( false ) } /> : null }
		</Panel>
	);
}

/**
 * Pick employees from a searchable list (a "select2"-style field): the chosen
 * ones as chips you can remove, a search box that filters by name or employee
 * number, and a dropdown with a tick on each chosen one. Keyboard: ↑ ↓ to
 * move, Enter to tick, Esc to close, Backspace on an empty search to remove
 * the last chip. Self-contained (no portal), so it works inside any panel.
 */
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Check, ChevronDown, Search, X } from 'lucide-react';

/**
 * Text to compare: lower case, no accents.
 *
 * @param {string} text Text.
 * @return {string} Folded text.
 */
const fold = ( text ) =>
	String( text || '' )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase();

/**
 * "Name (E0012)".
 *
 * @param {Object} employee Employee `{ name, employee_no }`.
 * @return {string} Label.
 */
const label = ( employee ) =>
	employee.employee_no
		? sprintf(
				/* translators: 1: employee name, 2: employee number. */
				__( '%1$s (%2$s)', 'radius-hotel-booking-pro' ),
				employee.name,
				employee.employee_no
		  )
		: employee.name;

/**
 * @param {Object}   props             Props.
 * @param {Object[]} props.options     Employees `{ id, name, employee_no }`.
 * @param {number[]} props.value       Chosen ids.
 * @param {Function} props.onChange    Called with the new ids.
 * @param {boolean}  props.loading     The list is loading.
 * @param {string}   props.placeholder Search placeholder.
 * @return {JSX.Element} Field.
 */
export default function EmployeeMultiSelect( {
	options = [],
	value = [],
	onChange,
	loading = false,
	placeholder,
} ) {
	const listId = useId();
	const root = useRef( null );
	const input = useRef( null );
	const [ open, setOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ active, setActive ] = useState( 0 );
	const chosen = Array.isArray( value ) ? value : [];

	const byId = useMemo(
		() =>
			new Map( options.map( ( employee ) => [ employee.id, employee ] ) ),
		[ options ]
	);
	const shown = useMemo( () => {
		const q = fold( query.trim() );
		return q
			? options.filter(
					( employee ) =>
						fold( employee.name ).includes( q ) ||
						fold( employee.employee_no ).includes( q )
			  )
			: options;
	}, [ options, query ] );

	// Close on a click outside.
	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const away = ( event ) => {
			if ( root.current && ! root.current.contains( event.target ) ) {
				setOpen( false );
			}
		};
		document.addEventListener( 'mousedown', away );
		return () => document.removeEventListener( 'mousedown', away );
	}, [ open ] );

	useEffect( () => setActive( 0 ), [ query ] );

	const toggle = ( id ) =>
		onChange(
			chosen.includes( id )
				? chosen.filter( ( v ) => v !== id )
				: [ ...chosen, id ]
		);

	const onKeyDown = ( event ) => {
		if ( 'ArrowDown' === event.key ) {
			event.preventDefault();
			setOpen( true );
			setActive( ( i ) => Math.min( i + 1, shown.length - 1 ) );
		} else if ( 'ArrowUp' === event.key ) {
			event.preventDefault();
			setActive( ( i ) => Math.max( i - 1, 0 ) );
		} else if ( 'Enter' === event.key ) {
			event.preventDefault();
			if ( open && shown[ active ] ) {
				toggle( shown[ active ].id );
			} else {
				setOpen( true );
			}
		} else if ( 'Escape' === event.key ) {
			setOpen( false );
		} else if ( 'Backspace' === event.key && ! query && chosen.length ) {
			onChange( chosen.slice( 0, -1 ) );
		}
	};

	const allShownChosen =
		shown.length > 0 && shown.every( ( e ) => chosen.includes( e.id ) );

	return (
		<div ref={ root } className="relative">
			{ /* The box: chips and the search. */ }
			<div
				className={ `flex min-h-10 w-full cursor-text flex-wrap items-center gap-1 rounded-md border bg-white px-2 py-1 ${
					open ? 'border-primary ring-2 ring-ring' : 'border-border'
				}` }
				onMouseDown={ ( event ) => {
					if ( event.target !== input.current ) {
						event.preventDefault();
					}
					setOpen( true );
					input.current?.focus();
				} }
			>
				{ chosen.map( ( id ) => {
					const employee = byId.get( id );
					return (
						<span
							key={ id }
							className="inline-flex items-center gap-1 rounded-full bg-primary-soft px-2 py-1 text-xs font-medium text-heading"
						>
							{ employee ? label( employee ) : `#${ id }` }
							<button
								type="button"
								className="inline-flex border-0 bg-transparent p-0 text-muted-foreground hover:text-destructive"
								onMouseDown={ ( event ) =>
									event.stopPropagation()
								}
								onClick={ () => toggle( id ) }
								aria-label={ sprintf(
									/* translators: %s: employee. */
									__(
										'Remove %s',
										'radius-hotel-booking-pro'
									),
									employee ? label( employee ) : `#${ id }`
								) }
							>
								<X className="h-3 w-3" aria-hidden="true" />
							</button>
						</span>
					);
				} ) }
				<span className="flex min-w-0 flex-1 items-center gap-1">
					{ ! chosen.length ? (
						<Search
							className="h-4 w-4 shrink-0 text-muted-foreground"
							aria-hidden="true"
						/>
					) : null }
					<input
						ref={ input }
						type="text"
						role="combobox"
						aria-expanded={ open }
						aria-controls={ listId }
						aria-autocomplete="list"
						value={ query }
						placeholder={
							chosen.length
								? ''
								: placeholder ||
								  __(
										'Search by name or employee number',
										'radius-hotel-booking-pro'
								  )
						}
						onChange={ ( e ) => {
							setQuery( e.target.value );
							setOpen( true );
						} }
						onFocus={ () => setOpen( true ) }
						onKeyDown={ onKeyDown }
						className="min-w-0 flex-1 border-0 bg-transparent p-1 text-sm outline-none"
						style={ { minWidth: '8rem', boxShadow: 'none' } }
					/>
				</span>
				<ChevronDown
					className="h-4 w-4 shrink-0 text-muted-foreground"
					aria-hidden="true"
				/>
			</div>

			{ open ? (
				<div
					className="absolute left-0 right-0 z-20 mt-1 overflow-hidden rounded-md border border-border bg-card shadow-lg"
					style={ { top: '100%' } }
				>
					<ul
						id={ listId }
						role="listbox"
						aria-multiselectable="true"
						className="m-0 max-h-64 list-none overflow-y-auto p-1"
					>
						{ shown.map( ( employee, index ) => {
							const on = chosen.includes( employee.id );
							return (
								<li
									key={ employee.id }
									role="option"
									aria-selected={ on }
									onMouseDown={ ( event ) =>
										event.preventDefault()
									}
									onMouseEnter={ () => setActive( index ) }
									onClick={ () => toggle( employee.id ) }
									className={ `flex cursor-pointer items-center gap-2 rounded px-2 py-2 text-sm ${
										index === active ? 'bg-muted' : ''
									}` }
								>
									<span
										className={ `inline-flex h-4 w-4 shrink-0 items-center justify-center rounded border ${
											on
												? 'border-primary bg-primary text-white'
												: 'border-border bg-card'
										}` }
										aria-hidden="true"
									>
										{ on ? (
											<Check
												style={ {
													width: 12,
													height: 12,
												} }
											/>
										) : null }
									</span>
									<span className="min-w-0 flex-1 truncate text-heading">
										{ employee.name }
									</span>
									<span className="shrink-0 text-xs text-muted-foreground">
										{ employee.employee_no }
									</span>
								</li>
							);
						} ) }
						{ ! shown.length ? (
							<li className="px-2 py-3 text-center text-sm text-muted-foreground">
								{ loading
									? __(
											'Loading…',
											'radius-hotel-booking-pro'
									  )
									: __(
											'No employee matches.',
											'radius-hotel-booking-pro'
									  ) }
							</li>
						) : null }
					</ul>
					<div className="flex items-center justify-between gap-2 border-t border-border bg-muted px-3 py-2 text-xs">
						<span className="text-muted-foreground">
							{ sprintf(
								/* translators: %d: number of employees chosen. */
								_n(
									'%d chosen',
									'%d chosen',
									chosen.length,
									'radius-hotel-booking-pro'
								),
								chosen.length
							) }
						</span>
						<span className="flex gap-3">
							{ shown.length ? (
								<button
									type="button"
									className="border-0 bg-transparent p-0 font-semibold text-primary hover:underline"
									onMouseDown={ ( event ) =>
										event.preventDefault()
									}
									onClick={ () => {
										const ids = shown.map( ( e ) => e.id );
										onChange(
											allShownChosen
												? chosen.filter(
														( id ) =>
															! ids.includes( id )
												  )
												: [
														...chosen,
														...ids.filter(
															( id ) =>
																! chosen.includes(
																	id
																)
														),
												  ]
										);
									} }
								>
									{ allShownChosen
										? __(
												'Untick these',
												'radius-hotel-booking-pro'
										  )
										: __(
												'Tick all shown',
												'radius-hotel-booking-pro'
										  ) }
								</button>
							) : null }
							{ chosen.length ? (
								<button
									type="button"
									className="border-0 bg-transparent p-0 font-semibold text-muted-foreground hover:text-destructive"
									onMouseDown={ ( event ) =>
										event.preventDefault()
									}
									onClick={ () => onChange( [] ) }
								>
									{ __(
										'Clear',
										'radius-hotel-booking-pro'
									) }
								</button>
							) : null }
						</span>
					</div>
				</div>
			) : null }
		</div>
	);
}

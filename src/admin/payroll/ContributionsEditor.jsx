/**
 * Settings → Payroll → Social contributions (built-in calculator): one
 * collapsible row per contribution — closed, a summary (name, who it is paid
 * to, who pays, rate, ceiling); open, its fields — and the employee and hotel
 * totals under the list.
 * *Add common contributions* starts a new hotel with the usual set (pension,
 * health, unemployment, work accident); rates differ by country, so they
 * start at 0 % to be filled in.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ChevronDown, Landmark, ListPlus, Plus, Trash2 } from 'lucide-react';
import { Button, Field, Input, SegmentedControl } from '@rtbp/ui';
import { format } from '@rtbp/lib';

import useConfirmRemove from './useConfirmRemove';

/**
 * Most contributions (the server's limit).
 */
const MAX = 20;

const NEW_CONTRIBUTION = {
	code: '',
	label: '',
	authority: '',
	side: 'employee',
	rate: 0,
	cap: 0,
};

/**
 * The usual contributions, employee and hotel shares apart; both shares of
 * one contribution share its code (remittances group them by it).
 *
 * @return {Object[]} Contributions.
 */
const commonContributions = () => {
	const pair = ( code, label, authority ) => [
		{
			...NEW_CONTRIBUTION,
			code,
			label: sprintf(
				/* translators: %s: contribution, e.g. Pension. */
				__( '%s (employee)', 'radius-hotel-booking-pro' ),
				label
			),
			authority,
			side: 'employee',
		},
		{
			...NEW_CONTRIBUTION,
			code,
			label: sprintf(
				/* translators: %s: contribution, e.g. Pension. */
				__( '%s (hotel)', 'radius-hotel-booking-pro' ),
				label
			),
			authority,
			side: 'employer',
		},
	];
	return [
		...pair(
			'PENSION',
			__( 'Pension', 'radius-hotel-booking-pro' ),
			__( 'Pension fund', 'radius-hotel-booking-pro' )
		),
		...pair(
			'HEALTH',
			__( 'Health insurance', 'radius-hotel-booking-pro' ),
			__( 'Health insurance fund', 'radius-hotel-booking-pro' )
		),
		...pair(
			'UNEMPLOYMENT',
			__( 'Unemployment insurance', 'radius-hotel-booking-pro' ),
			__( 'Employment office', 'radius-hotel-booking-pro' )
		),
		{
			...NEW_CONTRIBUTION,
			code: 'WORK_ACCIDENT',
			label: __( 'Work accident insurance', 'radius-hotel-booking-pro' ),
			authority: __(
				'Social security office',
				'radius-hotel-booking-pro'
			),
			side: 'employer',
		},
	];
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
 * Who pays, in words.
 *
 * @param {string} side employee|employer.
 * @return {string} Label.
 */
const sideLabel = ( side ) =>
	'employer' === side
		? __( 'Hotel', 'radius-hotel-booking-pro' )
		: __( 'Employee', 'radius-hotel-booking-pro' );

/**
 * "12.5 %".
 *
 * @param {number} n Rate.
 * @return {string} Text.
 */
const percent = ( n ) =>
	sprintf(
		/* translators: %s: a percentage number. */
		__( '%s %%', 'radius-hotel-booking-pro' ),
		String( Math.round( ( Number( n ) || 0 ) * 1000 ) / 1000 )
	);

/**
 * One contribution: the summary line, and its fields when open.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.row      Contribution.
 * @param {boolean}  props.open     Open.
 * @param {Function} props.onToggle Open or close it.
 * @param {Function} props.onChange Called with the changed fields.
 * @param {Function} props.onRemove Remove it.
 * @return {JSX.Element} Item.
 */
function ContributionItem( { row, open, onToggle, onChange, onRemove } ) {
	const noRate = ! Number( row.rate );
	const name =
		row.label || __( 'New contribution', 'radius-hotel-booking-pro' );
	return (
		<li className="bg-card">
			<div className="flex items-center gap-2 px-3 py-2.5">
				<button
					type="button"
					onClick={ onToggle }
					aria-expanded={ open }
					className="flex min-w-0 flex-1 items-center gap-3 rounded-md border-0 bg-transparent p-1 text-left hover:bg-muted"
				>
					<ChevronDown
						className={ `h-4 w-4 shrink-0 text-muted-foreground transition-transform ${
							open ? 'rotate-180' : ''
						}` }
						aria-hidden="true"
					/>
					<span className="min-w-0 flex-1">
						<span className="block truncate text-sm font-semibold text-heading">
							{ name }
						</span>
						{ row.authority ? (
							<span className="block truncate text-xs text-muted-foreground">
								{ row.authority }
							</span>
						) : null }
					</span>
					<span className="w-24 shrink-0 text-center">
						<span
							className={ `rounded-full px-2 py-0.5 text-xs font-semibold ${
								'employer' === row.side
									? 'bg-primary-soft text-primary'
									: 'bg-muted text-heading'
							}` }
						>
							{ sideLabel( row.side ) }
						</span>
					</span>
					<span className="w-20 shrink-0 text-right text-sm tabular-nums">
						{ noRate ? (
							<span className="rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning">
								{ __( 'Set rate', 'radius-hotel-booking-pro' ) }
							</span>
						) : (
							<span className="font-semibold text-heading">
								{ percent( row.rate ) }
							</span>
						) }
					</span>
					<span className="hidden w-32 shrink-0 text-right text-xs text-muted-foreground sm:inline">
						{ Number( row.cap ) > 0
							? sprintf(
									/* translators: %s: amount. */
									__(
										'Up to %s',
										'radius-hotel-booking-pro'
									),
									format.formatMoney( row.cap )
							  )
							: __( 'No ceiling', 'radius-hotel-booking-pro' ) }
					</span>
				</button>
				<Button
					type="button"
					variant="ghost"
					size="icon"
					className="shrink-0 text-muted-foreground hover:text-destructive"
					onClick={ onRemove }
					aria-label={ sprintf(
						/* translators: %s: contribution name. */
						__( 'Remove %s', 'radius-hotel-booking-pro' ),
						name
					) }
				>
					<Trash2 aria-hidden="true" />
				</Button>
			</div>
			{ open ? (
				<div className="grid gap-4 border-t border-border bg-muted px-4 py-4 sm:grid-cols-2 lg:grid-cols-3">
					<Field label={ __( 'Name', 'radius-hotel-booking-pro' ) }>
						<Input
							value={ row.label }
							maxLength={ 60 }
							placeholder={ __(
								'e.g. Pension',
								'radius-hotel-booking-pro'
							) }
							onChange={ ( e ) =>
								onChange( { label: e.target.value } )
							}
						/>
					</Field>
					<Field
						label={ __( 'Paid to', 'radius-hotel-booking-pro' ) }
					>
						<Input
							value={ row.authority }
							maxLength={ 40 }
							placeholder={ __(
								'e.g. Pension fund',
								'radius-hotel-booking-pro'
							) }
							onChange={ ( e ) =>
								onChange( { authority: e.target.value } )
							}
						/>
					</Field>
					<Field
						label={ __( 'Paid by', 'radius-hotel-booking-pro' ) }
					>
						<div>
							<SegmentedControl
								label={ __(
									'Paid by',
									'radius-hotel-booking-pro'
								) }
								value={ row.side }
								onChange={ ( side ) => onChange( { side } ) }
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
						label={ __( 'Rate (%)', 'radius-hotel-booking-pro' ) }
						description={ __(
							'Of the taxable pay.',
							'radius-hotel-booking-pro'
						) }
					>
						<Num
							max={ 100 }
							value={ row.rate }
							style={
								noRate
									? { borderColor: 'var(--warning)' }
									: undefined
							}
							onChange={ ( rate ) => onChange( { rate } ) }
						/>
					</Field>
					<Field
						label={ __(
							'Monthly ceiling',
							'radius-hotel-booking-pro'
						) }
						description={ __(
							'The most monthly pay the rate applies to. 0 = no ceiling.',
							'radius-hotel-booking-pro'
						) }
					>
						<Num
							value={ row.cap }
							onChange={ ( cap ) => onChange( { cap } ) }
						/>
					</Field>
					<Field
						label={ __( 'Code', 'radius-hotel-booking-pro' ) }
						description={ __(
							'Short name on reports; the employee and hotel shares of one contribution share it. Made from the name when empty.',
							'radius-hotel-booking-pro'
						) }
					>
						<Input
							value={ row.code }
							maxLength={ 30 }
							className="font-mono uppercase"
							onChange={ ( e ) =>
								onChange( { code: e.target.value } )
							}
						/>
					</Field>
				</div>
			) : null }
		</li>
	);
}

/**
 * @param {Object}   props          Props.
 * @param {Object[]} props.value    Contributions.
 * @param {Function} props.onChange Called with the new list.
 * @param {string}   props.error    Server message.
 * @param {boolean}  props.presets  Offer *Add common contributions* (the built-in calculator; a country add-on brings its own rows).
 * @return {JSX.Element} Editor.
 */
export default function ContributionsEditor( {
	value,
	onChange,
	error,
	presets = true,
} ) {
	const rows = Array.isArray( value ) ? value : [];
	// The rows open for editing, by position (a new one opens; the rest stay closed).
	const [ open, setOpen ] = useState( [] );
	const [ confirmRemove, removeDialog ] = useConfirmRemove();
	const toggle = ( index ) =>
		setOpen( ( prev ) =>
			prev.includes( index )
				? prev.filter( ( i ) => i !== index )
				: [ ...prev, index ]
		);
	const change = ( index, fields ) =>
		onChange(
			rows.map( ( row, i ) =>
				i === index ? { ...row, ...fields } : row
			)
		);
	const remove = ( index ) => {
		onChange( rows.filter( ( r, i ) => i !== index ) );
		setOpen( ( prev ) =>
			prev
				.filter( ( i ) => i !== index )
				.map( ( i ) => ( i > index ? i - 1 : i ) )
		);
	};
	const total = ( side ) =>
		rows
			.filter( ( row ) => row.side === side )
			.reduce( ( sum, row ) => sum + ( Number( row.rate ) || 0 ), 0 );
	// The common ones not there yet (matched on code and side), within the limit.
	const there = rows.map(
		( row ) => `${ String( row.code || '' ).toUpperCase() }|${ row.side }`
	);
	const missing = ( presets ? commonContributions() : [] ).filter(
		( row ) => ! there.includes( `${ row.code }|${ row.side }` )
	);
	const allOpen = rows.length > 0 && open.length === rows.length;

	const actions = (
		<div className="flex flex-wrap gap-2">
			{ missing.length ? (
				<Button
					type="button"
					variant={ rows.length ? 'outline' : 'default' }
					disabled={ rows.length >= MAX }
					onClick={ () =>
						onChange( [ ...rows, ...missing ].slice( 0, MAX ) )
					}
				>
					<ListPlus aria-hidden="true" />
					{ __(
						'Add common contributions',
						'radius-hotel-booking-pro'
					) }
				</Button>
			) : null }
			<Button
				type="button"
				variant="outline"
				disabled={ rows.length >= MAX }
				onClick={ () => {
					onChange( [ ...rows, { ...NEW_CONTRIBUTION } ] );
					setOpen( ( prev ) => [ ...prev, rows.length ] );
				} }
			>
				<Plus aria-hidden="true" />
				{ __( 'Add a contribution', 'radius-hotel-booking-pro' ) }
			</Button>
		</div>
	);

	return (
		<div className="space-y-4">
			{ error ? (
				<p className="m-0 text-sm text-destructive" role="alert">
					{ error }
				</p>
			) : null }

			{ rows.length ? (
				<div className="overflow-hidden rounded-lg border border-border">
					<div className="flex items-center gap-2 border-b border-border bg-muted px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
						<div className="flex min-w-0 flex-1 items-center gap-3 p-1">
							<span className="w-4 shrink-0" aria-hidden="true" />
							<span className="flex min-w-0 flex-1 items-center gap-3">
								<span>
									{ sprintf(
										/* translators: %d: number of contributions. */
										__(
											'Contribution (%d)',
											'radius-hotel-booking-pro'
										),
										rows.length
									) }
								</span>
								<button
									type="button"
									className="border-0 bg-transparent p-0 text-xs font-semibold normal-case tracking-normal text-primary hover:underline"
									onClick={ () =>
										setOpen(
											allOpen
												? []
												: rows.map( ( r, i ) => i )
										)
									}
								>
									{ allOpen
										? __(
												'Collapse all',
												'radius-hotel-booking-pro'
										  )
										: __(
												'Expand all',
												'radius-hotel-booking-pro'
										  ) }
								</button>
							</span>
							<span className="w-24 shrink-0 text-center">
								{ __( 'Paid by', 'radius-hotel-booking-pro' ) }
							</span>
							<span className="w-20 shrink-0 text-right">
								{ __( 'Rate', 'radius-hotel-booking-pro' ) }
							</span>
							<span className="hidden w-32 shrink-0 text-right sm:inline">
								{ __(
									'Monthly ceiling',
									'radius-hotel-booking-pro'
								) }
							</span>
						</div>
						<span className="w-10 shrink-0" aria-hidden="true" />
					</div>
					<ul className="m-0 list-none divide-y divide-border p-0">
						{ rows.map( ( row, index ) => (
							<ContributionItem
								key={ index }
								row={ row }
								open={ open.includes( index ) }
								onToggle={ () => toggle( index ) }
								onChange={ ( fields ) =>
									change( index, fields )
								}
								onRemove={ () =>
									confirmRemove(
										row.label ||
											__(
												'New contribution',
												'radius-hotel-booking-pro'
											),
										() => remove( index )
									)
								}
							/>
						) ) }
					</ul>
					<div className="flex flex-wrap items-center gap-x-6 gap-y-1 border-t border-border bg-muted px-4 py-2.5 text-sm">
						<span className="text-muted-foreground">
							{ __( 'Total rate', 'radius-hotel-booking-pro' ) }
						</span>
						<span>
							{ __( 'Employee', 'radius-hotel-booking-pro' ) }{ ' ' }
							<strong className="tabular-nums text-heading">
								{ percent( total( 'employee' ) ) }
							</strong>
						</span>
						<span>
							{ __( 'Hotel', 'radius-hotel-booking-pro' ) }{ ' ' }
							<strong className="tabular-nums text-heading">
								{ percent( total( 'employer' ) ) }
							</strong>
						</span>
					</div>
				</div>
			) : (
				<div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border px-6 py-8 text-center">
					<span className="inline-flex h-11 w-11 items-center justify-center rounded-full bg-primary-soft text-primary">
						<Landmark className="h-5 w-5" aria-hidden="true" />
					</span>
					<div className="max-w-md space-y-1">
						<p className="m-0 text-sm font-semibold text-heading">
							{ __(
								'No social contributions yet',
								'radius-hotel-booking-pro'
							) }
						</p>
						<p className="m-0 text-sm text-muted-foreground">
							{ presets
								? __(
										'Start with the common ones — pension, health and unemployment insurance (employee and hotel shares) and work accident insurance — then enter your country’s rates. Each can be renamed or removed.',
										'radius-hotel-booking-pro'
								  )
								: __(
										'Add each contribution your country’s rules use, with its rate and monthly ceiling.',
										'radius-hotel-booking-pro'
								  ) }
						</p>
					</div>
					{ actions }
				</div>
			) }

			{ rows.length ? actions : null }
			{ removeDialog }
		</div>
	);
}

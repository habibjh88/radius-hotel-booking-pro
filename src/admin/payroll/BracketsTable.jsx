/**
 * A table of monthly brackets — from (the limit before), up to, rate — as
 * Settings → Payroll → Income tax uses it; the last bracket is always "and
 * above" (higher pay must be taxed too). Exposed to add-ons as
 * `window.rtbpPro.ui.BracketsTable` (the Côte d'Ivoire CN and IGR scales).
 * Rows are `{ upTo (monthly, '' = and above), rate (%) }`.
 */
import { useEffect } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Plus, Receipt, Trash2 } from 'lucide-react';
import { Button, Input } from '@rtbp/ui';
import { format } from '@rtbp/lib';

import useConfirmRemove from './useConfirmRemove';

/**
 * Most brackets (the server's limit).
 */
const MAX = 20;

// From | Up to | Rate | remove.
const COLUMNS = {
	gridTemplateColumns: 'minmax(6rem,10rem) minmax(0,1fr) 7rem 2.5rem',
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
 * @param {Object}   props          Props.
 * @param {Object[]} props.value    Brackets.
 * @param {Function} props.onChange Called with the new brackets.
 * @param {string}   props.error    Server message.
 * @return {JSX.Element} Table.
 */
export default function BracketsTable( { value, onChange, error } ) {
	const rows = Array.isArray( value ) ? value : [];
	// The last bracket is always open-ended ("and above"): the server refuses a limit there.
	const save = ( list ) =>
		onChange(
			list.map( ( row, i ) =>
				i === list.length - 1 ? { ...row, upTo: '' } : row
			)
		);
	const last = rows[ rows.length - 1 ];
	const lastLimited =
		last && null !== last.upTo && '' !== String( last.upTo ?? '' );
	useEffect( () => {
		if ( lastLimited ) {
			save( rows );
		}
	}, [ lastLimited ] );
	const change = ( index, fields ) =>
		save(
			rows.map( ( row, i ) =>
				i === index ? { ...row, ...fields } : row
			)
		);
	const money = ( n ) => format.formatMoney( Number( n ) || 0 );
	const [ confirmRemove, removeDialog ] = useConfirmRemove();

	return (
		<div className="space-y-3">
			{ error ? (
				<p className="m-0 text-sm text-destructive" role="alert">
					{ error }
				</p>
			) : null }
			{ rows.length ? (
				<div className="overflow-hidden rounded-lg border border-border">
					<div
						className="grid items-center gap-3 border-b border-border bg-muted px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
						style={ COLUMNS }
					>
						<span>
							{ __( 'From', 'radius-hotel-booking-pro' ) }
						</span>
						<span>
							{ __( 'Up to', 'radius-hotel-booking-pro' ) }
						</span>
						<span>
							{ __( 'Rate', 'radius-hotel-booking-pro' ) }
						</span>
						<span />
					</div>
					<ul className="m-0 list-none divide-y divide-border p-0">
						{ rows.map( ( row, index ) => {
							const from =
								index > 0
									? Number( rows[ index - 1 ].upTo ) || 0
									: 0;
							return (
								<li
									key={ index }
									className="grid items-center gap-3 bg-card px-3 py-2"
									style={ COLUMNS }
								>
									<span className="text-sm tabular-nums text-muted-foreground">
										{ money( from ) }
									</span>
									{ index === rows.length - 1 ? (
										<span className="text-sm font-medium text-heading">
											{ __(
												'and above',
												'radius-hotel-booking-pro'
											) }
										</span>
									) : (
										<Num
											value={ row.upTo }
											placeholder={ __(
												'Monthly pay',
												'radius-hotel-booking-pro'
											) }
											aria-label={ sprintf(
												/* translators: %d: bracket number. */
												__(
													'Bracket %d: monthly pay up to',
													'radius-hotel-booking-pro'
												),
												index + 1
											) }
											onChange={ ( upTo ) =>
												change( index, {
													upTo,
												} )
											}
										/>
									) }
									<div className="relative">
										<Num
											className="pr-8"
											max={ 100 }
											value={ row.rate }
											aria-label={ sprintf(
												/* translators: %d: bracket number. */
												__(
													'Bracket %d: rate (%%)',
													'radius-hotel-booking-pro'
												),
												index + 1
											) }
											onChange={ ( rate ) =>
												change( index, {
													rate,
												} )
											}
										/>
										<span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
											%
										</span>
									</div>
									<Button
										type="button"
										variant="ghost"
										size="icon"
										className="text-muted-foreground hover:text-destructive"
										onClick={ () =>
											confirmRemove(
												sprintf(
													/* translators: %d: bracket number. */
													__(
														'Bracket %d',
														'radius-hotel-booking-pro'
													),
													index + 1
												),
												() =>
													save(
														rows.filter(
															( r, i ) =>
																i !== index
														)
													)
											)
										}
										aria-label={ sprintf(
											/* translators: %d: bracket number. */
											__(
												'Remove bracket %d',
												'radius-hotel-booking-pro'
											),
											index + 1
										) }
									>
										<Trash2 aria-hidden="true" />
									</Button>
								</li>
							);
						} ) }
					</ul>
				</div>
			) : (
				<div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-border px-6 py-6 text-center">
					<Receipt
						className="h-5 w-5 text-muted-foreground"
						aria-hidden="true"
					/>
					<p className="m-0 text-sm text-muted-foreground">
						{ __(
							'No rows yet: add the first one, from 0.',
							'radius-hotel-booking-pro'
						) }
					</p>
				</div>
			) }
			<Button
				type="button"
				variant="outline"
				disabled={ rows.length >= MAX }
				onClick={ () => save( [ ...rows, { upTo: '', rate: 0 } ] ) }
			>
				<Plus aria-hidden="true" />
				{ __( 'Add a bracket', 'radius-hotel-booking-pro' ) }
			</Button>
			{ removeDialog }
		</div>
	);
}

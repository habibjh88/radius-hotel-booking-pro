/**
 * Front desk → Cash register (R3-T5 – R3-T8). *My register*: open it at the
 * start of the shift with the cash already in the drawer, watch what came in
 * per payment method, close it with the cash counted, print the slip.
 * *All registers* (managers): every shift, with its difference.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useSearchParams } from 'react-router-dom';
import { Lock, LockOpen, Printer } from 'lucide-react';
import {
	Button,
	DataTable,
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
	EmptyState,
	Field,
	FilterTabs,
	Input,
	Money,
	Panel,
	Skeleton,
	Textarea,
} from '@rtbp/ui';
import { access, format, toast, toastError } from '@rtbp/lib';

import { useAllRegisters, useMyRegister, useRegisterAction } from './api';

const money = ( value ) => format.formatMoney( Number( value ) || 0 );

/**
 * Print a closed register's slip in a window of its own.
 *
 * @param {Object} session Session.
 * @return {void}
 */
function printSlip( session ) {
	const win = window.open( '', '_blank', 'width=420,height=640' );
	if ( ! win ) {
		return;
	}
	const doc = win.document;
	doc.title = __( 'Cash register', 'radius-hotel-booking-pro' );
	const style = doc.createElement( 'style' );
	style.textContent =
		'body{font:14px/1.4 system-ui,sans-serif;margin:24px;color:#111}h1{font-size:18px;margin:0 0 4px}p{margin:0 0 12px;color:#555}table{width:100%;border-collapse:collapse}td,th{padding:6px 0;border-bottom:1px solid #eee;text-align:left}td:last-child,th:last-child{text-align:right}.total td{font-weight:700}';
	doc.head.appendChild( style );
	const add = ( tag, text, parent = doc.body ) => {
		const el = doc.createElement( tag );
		if ( undefined !== text ) {
			el.textContent = text;
		}
		parent.appendChild( el );
		return el;
	};
	add( 'h1', __( 'Cash register', 'radius-hotel-booking-pro' ) );
	add(
		'p',
		sprintf(
			/* translators: 1: staff name, 2: opened at, 3: closed at. */
			__( '%1$s · %2$s → %3$s', 'radius-hotel-booking-pro' ),
			session.user_name,
			format.formatDateTime( session.opened_at ),
			format.formatDateTime( session.closed_at )
		)
	);
	const table = add( 'table' );
	const head = add( 'tr', undefined, table );
	add( 'th', __( 'Method', 'radius-hotel-booking-pro' ), head );
	add( 'th', __( 'Expected', 'radius-hotel-booking-pro' ), head );
	add( 'th', __( 'Counted', 'radius-hotel-booking-pro' ), head );
	( session.closed || [] ).forEach( ( row ) => {
		const tr = add( 'tr', undefined, table );
		add( 'td', row.label, tr );
		add( 'td', money( row.expected ), tr );
		add( 'td', null === row.counted ? '—' : money( row.counted ), tr );
	} );
	const total = add( 'tr', undefined, table );
	total.className = 'total';
	add( 'td', __( 'Cash difference', 'radius-hotel-booking-pro' ), total );
	add( 'td', '', total );
	add( 'td', money( session.difference ), total );
	add(
		'p',
		sprintf(
			/* translators: %s: amount. */
			__( 'Opening cash: %s', 'radius-hotel-booking-pro' ),
			money( session.opening_float )
		)
	).style.marginTop = '12px';
	if ( session.note ) {
		add( 'p', session.note );
	}
	win.focus();
	win.print();
}

/**
 * The difference, coloured: short (red), over (amber), exact (green).
 *
 * @param {Object} props       Props.
 * @param {number} props.value Counted − expected.
 * @return {JSX.Element} Amount.
 */
function Difference( { value } ) {
	if ( null === value || undefined === value ) {
		return <span className="text-muted-foreground">—</span>;
	}
	const n = Number( value );
	let tone = 'text-success';
	if ( n < 0 ) {
		tone = 'text-destructive';
	} else if ( n > 0 ) {
		tone = 'text-warning';
	}
	return (
		<span className={ `font-semibold tabular-nums ${ tone }` }>
			<Money value={ n } signed />
		</span>
	);
}

/**
 * A closed register: expected and counted per method, the difference.
 *
 * @param {Object} props         Props.
 * @param {Object} props.session Session.
 * @return {JSX.Element} Summary.
 */
function ClosedSummary( { session } ) {
	return (
		<div className="space-y-3">
			<table className="w-full text-sm">
				<thead>
					<tr className="text-left text-xs uppercase tracking-wide text-muted-foreground">
						<th className="py-1 font-semibold">
							{ __( 'Method', 'radius-hotel-booking-pro' ) }
						</th>
						<th className="py-1 text-right font-semibold">
							{ __( 'Expected', 'radius-hotel-booking-pro' ) }
						</th>
						<th className="py-1 text-right font-semibold">
							{ __( 'Counted', 'radius-hotel-booking-pro' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ ( session.closed || [] ).map( ( row ) => (
						<tr key={ row.key } className="border-t border-border">
							<td className="py-2">{ row.label }</td>
							<td className="py-2 text-right tabular-nums">
								<Money value={ row.expected } />
							</td>
							<td className="py-2 text-right tabular-nums">
								{ null === row.counted ? (
									'—'
								) : (
									<Money value={ row.counted } />
								) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<div className="flex items-center justify-between rounded-lg bg-muted px-3 py-2 text-sm">
				<span className="font-semibold text-heading">
					{ __( 'Cash difference', 'radius-hotel-booking-pro' ) }
				</span>
				<Difference value={ session.difference } />
			</div>
			{ session.note ? (
				<p className="m-0 text-sm text-muted-foreground">
					{ session.note }
				</p>
			) : null }
		</div>
	);
}

/**
 * The live box of an open register: one total per method, as on the desk's
 * paper sheet, and the cash that should be in the drawer.
 *
 * @param {Object} props         Props.
 * @param {Object} props.session Open session.
 * @return {JSX.Element} Box.
 */
function LiveBox( { session } ) {
	const live = session.live || { methods: [], expected_cash: 0 };
	return (
		<div className="space-y-3">
			<ul className="m-0 list-none divide-y divide-border rounded-lg border border-border p-0">
				{ live.methods.map( ( method ) => (
					<li
						key={ method.key }
						className="flex items-center justify-between gap-3 px-4 py-3"
					>
						<span>
							<span className="block text-base font-medium text-heading">
								{ method.label }
							</span>
							<span className="block text-xs text-muted-foreground">
								{ sprintf(
									/* translators: %d: number of payments. */
									_n(
										'%d payment',
										'%d payments',
										method.count,
										'radius-hotel-booking-pro'
									),
									method.count
								) }
							</span>
						</span>
						<span className="text-lg font-semibold tabular-nums text-heading">
							<Money value={ method.amount } />
						</span>
					</li>
				) ) }
			</ul>
			<div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted px-4 py-3 text-sm">
				<span className="text-muted-foreground">
					{ sprintf(
						/* translators: %s: opening cash amount. */
						__(
							'Cash expected in the drawer (opening cash %s included)',
							'radius-hotel-booking-pro'
						),
						money( session.opening_float )
					) }
				</span>
				<span className="text-base font-semibold tabular-nums text-heading">
					<Money value={ live.expected_cash } />
				</span>
			</div>
		</div>
	);
}

/**
 * Close the register: count the cash, add a note.
 *
 * @param {Object}   props          Props.
 * @param {boolean}  props.open     Open.
 * @param {Function} props.onClose  Close the dialog.
 * @param {Function} props.onClosed Called with the closed session.
 * @return {JSX.Element} Dialog.
 */
function CloseDialog( { open, onClose, onClosed } ) {
	const action = useRegisterAction();
	const [ counted, setCounted ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ errors, setErrors ] = useState( {} );

	const submit = async ( event ) => {
		event.preventDefault();
		setErrors( {} );
		try {
			const response = await action.mutateAsync( {
				action: 'close',
				counted_cash: counted,
				note,
			} );
			toast.success( response.message );
			setCounted( '' );
			setNote( '' );
			onClosed( response.data.session );
		} catch ( err ) {
			const mapped = {};
			Object.entries( err?.errors || {} ).forEach( ( [ key, value ] ) => {
				mapped[ key ] = value?.first_message || String( value );
			} );
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	return (
		<Dialog open={ open } onOpenChange={ ( next ) => ! next && onClose() }>
			<DialogContent className="rtbp-root max-w-md">
				<form onSubmit={ submit } className="space-y-4">
					<DialogHeader>
						<DialogTitle>
							{ __(
								'Close the cash register',
								'radius-hotel-booking-pro'
							) }
						</DialogTitle>
						<DialogDescription>
							{ __(
								'Count the cash in the drawer and enter it. The difference with what is expected is kept with the shift.',
								'radius-hotel-booking-pro'
							) }
						</DialogDescription>
					</DialogHeader>
					<Field
						label={ __(
							'Cash counted',
							'radius-hotel-booking-pro'
						) }
						error={ errors.counted_cash }
						required
					>
						<Input
							type="number"
							inputMode="decimal"
							min={ 0 }
							step="any"
							value={ counted }
							onChange={ ( e ) => setCounted( e.target.value ) }
							autoFocus
						/>
					</Field>
					<Field
						label={ __( 'Note', 'radius-hotel-booking-pro' ) }
						description={ __(
							'Optional: why the cash does not match.',
							'radius-hotel-booking-pro'
						) }
						error={ errors.note }
					>
						<Textarea
							rows={ 2 }
							className="!min-h-[64px]"
							maxLength={ 1000 }
							value={ note }
							onChange={ ( e ) => setNote( e.target.value ) }
						/>
					</Field>
					<DialogFooter>
						<Button
							type="button"
							variant="ghost"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
						</Button>
						<Button type="submit" disabled={ action.isPending }>
							<Lock className="h-4 w-4" aria-hidden="true" />
							{ __(
								'Close the register',
								'radius-hotel-booking-pro'
							) }
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}

/**
 * My register.
 *
 * @return {JSX.Element} Tab.
 */
function MyRegister() {
	const query = useMyRegister();
	const action = useRegisterAction();
	const [ float, setFloat ] = useState( '' );
	const [ floatError, setFloatError ] = useState( '' );
	const [ closing, setClosing ] = useState( false );
	// The session just closed: its summary and slip.
	const [ justClosed, setJustClosed ] = useState( null );

	if ( query.isPending ) {
		return <Skeleton className="h-64 w-full rounded-xl" />;
	}
	if ( query.error ) {
		return (
			<Panel>
				<p className="m-0 text-sm text-destructive">
					{ query.error.message }
				</p>
			</Panel>
		);
	}
	const session = query.data?.session;
	const recent = query.data?.recent || [];

	const openRegister = async ( event ) => {
		event.preventDefault();
		setFloatError( '' );
		try {
			const response = await action.mutateAsync( {
				action: 'open',
				opening_float: float,
			} );
			toast.success( response.message );
			setFloat( '' );
			setJustClosed( null );
		} catch ( err ) {
			const message = err?.errors?.opening_float?.first_message;
			if ( message ) {
				setFloatError( message );
			} else {
				toastError( err );
			}
		}
	};

	return (
		<div className="space-y-4">
			{ justClosed ? (
				<Panel
					title={ __(
						'Register closed',
						'radius-hotel-booking-pro'
					) }
					description={ sprintf(
						/* translators: 1: opened at, 2: closed at. */
						__( '%1$s → %2$s', 'radius-hotel-booking-pro' ),
						format.formatDateTime( justClosed.opened_at ),
						format.formatDateTime( justClosed.closed_at )
					) }
					actions={
						<Button
							variant="outline"
							onClick={ () => printSlip( justClosed ) }
						>
							<Printer className="h-4 w-4" aria-hidden="true" />
							{ __( 'Print', 'radius-hotel-booking-pro' ) }
						</Button>
					}
				>
					<ClosedSummary session={ justClosed } />
				</Panel>
			) : null }

			{ session ? (
				<Panel
					title={ __(
						'Your cash register is open',
						'radius-hotel-booking-pro'
					) }
					description={ sprintf(
						/* translators: %s: date and time. */
						__( 'Since %s', 'radius-hotel-booking-pro' ),
						format.formatDateTime( session.opened_at )
					) }
					actions={
						<Button onClick={ () => setClosing( true ) }>
							<Lock className="h-4 w-4" aria-hidden="true" />
							{ __( 'Close', 'radius-hotel-booking-pro' ) }
						</Button>
					}
				>
					<LiveBox session={ session } />
				</Panel>
			) : (
				<Panel
					title={ __(
						'Your cash register is closed',
						'radius-hotel-booking-pro'
					) }
					description={ __(
						'Open it at the start of your shift. Every payment and refund you record is then added up here, per payment method.',
						'radius-hotel-booking-pro'
					) }
				>
					<form
						onSubmit={ openRegister }
						className="flex flex-wrap items-end gap-3"
					>
						<Field
							label={ __(
								'Cash in the drawer now',
								'radius-hotel-booking-pro'
							) }
							error={ floatError }
						>
							<Input
								type="number"
								inputMode="decimal"
								min={ 0 }
								step="any"
								placeholder="0"
								className="w-48"
								value={ float }
								onChange={ ( e ) => setFloat( e.target.value ) }
							/>
						</Field>
						<Button type="submit" disabled={ action.isPending }>
							<LockOpen className="h-4 w-4" aria-hidden="true" />
							{ __( 'Open', 'radius-hotel-booking-pro' ) }
						</Button>
					</form>
				</Panel>
			) }

			{ recent.length ? (
				<Panel
					title={ __(
						'Your last shifts',
						'radius-hotel-booking-pro'
					) }
				>
					<ul className="m-0 list-none divide-y divide-border p-0">
						{ recent.map( ( item ) => (
							<li
								key={ item.id }
								className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm"
							>
								<span>
									{ format.formatDateTime( item.opened_at ) }{ ' ' }
									→{ ' ' }
									{ format.formatDateTime( item.closed_at ) }
								</span>
								<span className="flex items-center gap-3">
									<Difference value={ item.difference } />
									<Button
										size="sm"
										variant="ghost"
										onClick={ () => printSlip( item ) }
										aria-label={ __(
											'Print',
											'radius-hotel-booking-pro'
										) }
									>
										<Printer
											className="h-4 w-4"
											aria-hidden="true"
										/>
									</Button>
								</span>
							</li>
						) ) }
					</ul>
				</Panel>
			) : null }

			<CloseDialog
				open={ closing }
				onClose={ () => setClosing( false ) }
				onClosed={ ( closed ) => {
					setClosing( false );
					setJustClosed( closed );
				} }
			/>
		</div>
	);
}

/**
 * Every register (managers).
 *
 * @return {JSX.Element} Tab.
 */
function AllRegisters() {
	const [ status, setStatus ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ viewing, setViewing ] = useState( null );
	const query = useAllRegisters( { status, page }, true );
	const rows = query.data?.sessions || [];

	const columns = [
		{
			id: 'staff',
			header: __( 'Staff', 'radius-hotel-booking-pro' ),
			mobile: 'title',
			cell: ( s ) => s.user_name || '—',
		},
		{
			id: 'opened',
			header: __( 'Opened', 'radius-hotel-booking-pro' ),
			mobile: 'subtitle',
			cell: ( s ) => format.formatDateTime( s.opened_at ),
		},
		{
			id: 'closed',
			header: __( 'Closed', 'radius-hotel-booking-pro' ),
			cell: ( s ) =>
				s.closed_at ? (
					format.formatDateTime( s.closed_at )
				) : (
					<span className="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-2 py-0.5 text-xs font-semibold text-success">
						<span
							className="h-1.5 w-1.5 rounded-full bg-current"
							aria-hidden="true"
						/>
						{ __( 'Open', 'radius-hotel-booking-pro' ) }
					</span>
				),
		},
		{
			id: 'cash',
			header: __( 'Cash expected', 'radius-hotel-booking-pro' ),
			align: 'right',
			cell: ( s ) => {
				const cash = s.closed?.find( ( row ) => 'cash' === row.key );
				const value = cash ? cash.expected : s.live?.expected_cash;
				return <Money value={ value || 0 } />;
			},
		},
		{
			id: 'difference',
			header: __( 'Difference', 'radius-hotel-booking-pro' ),
			align: 'right',
			cell: ( s ) => <Difference value={ s.difference } />,
		},
	];

	return (
		<Panel>
			<div className="space-y-4">
				<FilterTabs
					label={ __( 'Registers', 'radius-hotel-booking-pro' ) }
					value={ status }
					onChange={ ( next ) => {
						setStatus( next );
						setPage( 1 );
					} }
					tabs={ [
						{
							value: '',
							label: __( 'All', 'radius-hotel-booking-pro' ),
						},
						{
							value: 'open',
							label: __( 'Open', 'radius-hotel-booking-pro' ),
						},
						{
							value: 'closed',
							label: __( 'Closed', 'radius-hotel-booking-pro' ),
						},
					] }
				/>
				<DataTable
					columns={ columns }
					rows={ rows }
					total={ query.data?.total || 0 }
					page={ page }
					perPage={ 20 }
					onPageChange={ setPage }
					loading={ query.isPending }
					error={ query.error }
					onRetry={ () => query.refetch() }
					onRowClick={ ( s ) => setViewing( s ) }
					empty={
						<EmptyState
							title={ __(
								'No cash register shifts yet',
								'radius-hotel-booking-pro'
							) }
							className="border-0"
						/>
					}
				/>
			</div>
			<Dialog
				open={ Boolean( viewing ) }
				onOpenChange={ ( next ) => ! next && setViewing( null ) }
			>
				<DialogContent className="rtbp-root max-w-md">
					<DialogHeader>
						<DialogTitle>{ viewing?.user_name }</DialogTitle>
						<DialogDescription>
							{ viewing
								? sprintf(
										/* translators: 1: opened at, 2: closed at or "open". */
										__(
											'%1$s → %2$s',
											'radius-hotel-booking-pro'
										),
										format.formatDateTime(
											viewing.opened_at
										),
										viewing.closed_at
											? format.formatDateTime(
													viewing.closed_at
											  )
											: __(
													'still open',
													'radius-hotel-booking-pro'
											  )
								  )
								: '' }
						</DialogDescription>
					</DialogHeader>
					{ viewing && 'open' === viewing.status ? (
						<LiveBox session={ viewing } />
					) : null }
					{ viewing && 'closed' === viewing.status ? (
						<ClosedSummary session={ viewing } />
					) : null }
					{ viewing && 'closed' === viewing.status ? (
						<DialogFooter>
							<Button
								variant="outline"
								onClick={ () => printSlip( viewing ) }
							>
								<Printer
									className="h-4 w-4"
									aria-hidden="true"
								/>
								{ __( 'Print', 'radius-hotel-booking-pro' ) }
							</Button>
						</DialogFooter>
					) : null }
				</DialogContent>
			</Dialog>
		</Panel>
	);
}

/**
 * @return {JSX.Element} Screen.
 */
export default function CashRegisterScreen() {
	const [ params, setParams ] = useSearchParams();
	const canManage = access?.useAccess
		? 'locked' !== access.useAccess( 'register.manage' )
		: false;
	const tab = canManage && 'all' === params.get( 'tab' ) ? 'all' : 'mine';

	return (
		<div className="space-y-4">
			{ canManage ? (
				<FilterTabs
					label={ __( 'Cash register', 'radius-hotel-booking-pro' ) }
					value={ tab }
					onChange={ ( next ) =>
						setParams( 'all' === next ? { tab: 'all' } : {} )
					}
					tabs={ [
						{
							value: 'mine',
							label: __(
								'My register',
								'radius-hotel-booking-pro'
							),
						},
						{
							value: 'all',
							label: __(
								'All registers',
								'radius-hotel-booking-pro'
							),
						},
					] }
				/>
			) : null }
			{ 'all' === tab ? <AllRegisters /> : <MyRegister /> }
		</div>
	);
}

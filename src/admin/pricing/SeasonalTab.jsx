/**
 * Seasonal rules (feature 7.12): a template applied to a date range, for
 * some or all room types and rate plans, with a priority. A mini timeline
 * shows the year's seasons; when rules overlap on a date, the highest
 * priority wins, then the most specific, then the newest.
 */
import { useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	CalendarRange,
	ChevronLeft,
	ChevronRight,
	Plus,
	Trash2,
} from 'lucide-react';
import * as ui from '@rtbp/ui';
import {
	Badge,
	Button,
	ConfirmDialog,
	EmptyState,
	Field,
	Input,
	Panel,
	Skeleton,
	Switch,
} from '@rtbp/ui';
import { cn, format, toast, toastError } from '@rtbp/lib';

import Drawer from './Drawer';
import { ScopePicker, scopeText } from './scope';
import {
	useDeleteRule,
	useRules,
	useSaveRule,
	useScopeOptions,
	useTemplates,
} from './api';

const DAY = 24 * 60 * 60 * 1000;

/**
 * Days since 1 Jan of a `Y-m-d` date (UTC arithmetic: dates, not moments).
 *
 * @param {string} ymd  Date.
 * @param {number} year Year.
 * @return {number} Day index.
 */
const dayOf = ( ymd, year ) =>
	( Date.UTC(
		...ymd.split( '-' ).map( ( n, i ) => Number( n ) - ( i === 1 ? 1 : 0 ) )
	) -
		Date.UTC( year, 0, 1 ) ) /
	DAY;

/**
 * @param {Object}  props         Props.
 * @param {boolean} props.canEdit May change rules.
 * @return {JSX.Element} Tab.
 */
export default function SeasonalTab( { canEdit } ) {
	const rules = useRules( 'seasonal' );
	const templates = useTemplates();
	const scope = useScopeOptions();
	const [ editing, setEditing ] = useState( null );
	const [ year, setYear ] = useState( () =>
		Number( format.siteToday().slice( 0, 4 ) )
	);

	const describe = ( rule ) =>
		[
			scopeText(
				rule.room_type_ids,
				scope.roomTypes,
				__( 'All room types', 'radius-hotel-booking-pro' )
			),
			scopeText(
				rule.rate_plan_ids,
				scope.ratePlans,
				__( 'all rate plans', 'radius-hotel-booking-pro' )
			),
		].join( ' · ' );

	let body;
	if ( rules.isPending ) {
		body = <Skeleton className="h-48 w-full rounded-xl" />;
	} else if ( rules.error ) {
		body = (
			<p role="alert" className="m-0 text-sm text-destructive">
				{ rules.error.message }
			</p>
		);
	} else if ( ! rules.data.length ) {
		body = (
			<EmptyState
				icon={ CalendarRange }
				title={ __(
					'No seasonal rules yet',
					'radius-hotel-booking-pro'
				) }
				description={ __(
					'Apply a template to dates, e.g. +20 % from 20 to 31 December.',
					'radius-hotel-booking-pro'
				) }
				className="border-0"
			/>
		);
	} else {
		body = (
			<div className="space-y-4">
				<Timeline
					rules={ rules.data }
					year={ year }
					setYear={ setYear }
					onOpen={ setEditing }
				/>
				<ul className="m-0 list-none divide-y divide-border rounded-lg border border-border p-0">
					{ rules.data.map( ( rule ) => (
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
										{ rule.template ? (
											<Badge variant="secondary">
												{ rule.template.summary }
											</Badge>
										) : null }
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
											/* translators: 1: first date, 2: last date, 3: priority number. */
											__(
												'%1$s – %2$s · priority %3$d',
												'radius-hotel-booking-pro'
											),
											format.formatDate( rule.date_from ),
											format.formatDate( rule.date_to ),
											rule.priority
										) }
									</span>
									<span className="block text-xs text-muted-foreground">
										{ describe( rule ) }
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
			</div>
		);
	}

	return (
		<>
			<Panel
				title={ __( 'Seasonal rules', 'radius-hotel-booking-pro' ) }
				description={ __(
					'When rules overlap on a date, the highest priority wins, then the most specific, then the newest.',
					'radius-hotel-booking-pro'
				) }
				actions={
					canEdit ? (
						<Button size="sm" onClick={ () => setEditing( {} ) }>
							<Plus className="h-4 w-4" aria-hidden="true" />
							{ __(
								'Add seasonal rule',
								'radius-hotel-booking-pro'
							) }
						</Button>
					) : null
				}
			>
				{ body }
			</Panel>
			{ editing ? (
				<RuleEditor
					rule={ editing.id ? editing : null }
					templates={ templates.data || [] }
					scope={ scope }
					readOnly={ ! canEdit }
					onClose={ () => setEditing( null ) }
				/>
			) : null }
		</>
	);
}

/**
 * A year strip with one bar per rule that touches the year.
 *
 * @param {Object}   props         Props.
 * @param {Object[]} props.rules   Rules.
 * @param {number}   props.year    Year shown.
 * @param {Function} props.setYear Change the year.
 * @param {Function} props.onOpen  Open a rule.
 * @return {JSX.Element} Timeline.
 */
function Timeline( { rules, year, setYear, onOpen } ) {
	const days = ( Date.UTC( year + 1, 0, 1 ) - Date.UTC( year, 0, 1 ) ) / DAY;
	const months = useMemo( () => {
		const name = new Intl.DateTimeFormat( undefined, {
			month: 'narrow',
			timeZone: 'UTC',
		} );
		return Array.from( { length: 12 }, ( _, m ) => ( {
			left:
				( ( Date.UTC( year, m, 1 ) - Date.UTC( year, 0, 1 ) ) /
					DAY /
					days ) *
				100,
			label: name.format( new Date( Date.UTC( year, m, 1 ) ) ),
		} ) );
	}, [ year, days ] );
	const shown = rules.filter(
		( r ) =>
			r.date_to >= `${ year }-01-01` && r.date_from <= `${ year }-12-31`
	);

	return (
		<div className="rounded-lg border border-border p-3">
			<div className="mb-2 flex items-center justify-between">
				<Button
					type="button"
					variant="ghost"
					size="icon"
					className="h-7 w-7"
					onClick={ () => setYear( year - 1 ) }
					aria-label={ __(
						'Previous year',
						'radius-hotel-booking-pro'
					) }
				>
					<ChevronLeft className="h-4 w-4" aria-hidden="true" />
				</Button>
				<span className="text-sm font-semibold text-heading">
					{ year }
				</span>
				<Button
					type="button"
					variant="ghost"
					size="icon"
					className="h-7 w-7"
					onClick={ () => setYear( year + 1 ) }
					aria-label={ __( 'Next year', 'radius-hotel-booking-pro' ) }
				>
					<ChevronRight className="h-4 w-4" aria-hidden="true" />
				</Button>
			</div>
			<div
				className="relative h-4 text-[10px] text-muted-foreground"
				aria-hidden="true"
			>
				{ months.map( ( m ) => (
					<span
						key={ m.left }
						className="absolute top-0"
						style={ { left: `${ m.left }%` } }
					>
						{ m.label }
					</span>
				) ) }
			</div>
			<div className="space-y-1">
				{ shown.length ? (
					shown.map( ( rule ) => {
						const from = Math.max(
							0,
							dayOf( rule.date_from, year )
						);
						const to = Math.min(
							days - 1,
							dayOf( rule.date_to, year )
						);
						return (
							<div
								key={ rule.id }
								className="relative h-5 rounded bg-muted"
							>
								<button
									type="button"
									onClick={ () => onOpen( rule ) }
									title={ `${
										rule.name
									}: ${ format.formatDate(
										rule.date_from
									) } – ${ format.formatDate(
										rule.date_to
									) }` }
									aria-label={ rule.name }
									className={ cn(
										'absolute inset-y-0 rounded border-0 p-0',
										rule.is_active
											? 'bg-primary'
											: 'bg-border'
									) }
									style={ {
										left: `${ ( from / days ) * 100 }%`,
										width: `${
											( ( to - from + 1 ) / days ) * 100
										}%`,
										// A one-day season stays visible.
										minWidth: 6,
									} }
								/>
							</div>
						);
					} )
				) : (
					<p className="m-0 text-xs text-muted-foreground">
						{ sprintf(
							/* translators: %d: year. */
							__(
								'No seasonal rule in %d.',
								'radius-hotel-booking-pro'
							),
							year
						) }
					</p>
				) }
			</div>
		</div>
	);
}

/**
 * The seasonal rule editor.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.rule      Rule, or null to add one.
 * @param {Object[]} props.templates Templates.
 * @param {Object}   props.scope     `{ roomTypes, ratePlans }`.
 * @param {boolean}  props.readOnly  Show without saving.
 * @param {Function} props.onClose   Close.
 * @return {JSX.Element} Drawer.
 */
function RuleEditor( { rule, templates, scope, readOnly, onClose } ) {
	const save = useSaveRule();
	const remove = useDeleteRule();
	const today = format.siteToday();
	const [ values, setValues ] = useState( {
		name: rule?.name ?? '',
		template_id: String( rule?.template_id ?? templates[ 0 ]?.id ?? '' ),
		date_from: rule?.date_from ?? today,
		date_to: rule?.date_to ?? today,
		room_type_ids: rule?.room_type_ids ?? [],
		rate_plan_ids: rule?.rate_plan_ids ?? [],
		priority: String( rule?.priority ?? 10 ),
		is_active: rule?.is_active ?? true,
	} );
	const [ errors, setErrors ] = useState( {} );
	const [ confirm, setConfirm ] = useState( false );
	const set = ( key ) => ( value ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setErrors( ( prev ) => ( { ...prev, [ key ]: undefined } ) );
	};
	const template = templates.find(
		( t ) => String( t.id ) === values.template_id
	);

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			await save.mutateAsync( {
				id: rule?.id,
				values: {
					...values,
					kind: 'seasonal',
					template_id: Number( values.template_id ) || 0,
					priority: values.priority.trim(),
				},
			} );
			toast.success(
				rule
					? __( 'Rule saved.', 'radius-hotel-booking-pro' )
					: __( 'Rule added.', 'radius-hotel-booking-pro' )
			);
			onClose();
		} catch ( error ) {
			const fields = Object.fromEntries(
				Object.entries( error?.errors || {} ).map( ( [ k, d ] ) => [
					k,
					d?.first_message,
				] )
			);
			setErrors( fields );
			if ( ! Object.keys( fields ).length ) {
				toastError( error );
			}
		}
	};

	return (
		<Drawer
			title={
				rule
					? rule.name
					: __( 'New seasonal rule', 'radius-hotel-booking-pro' )
			}
			description={ __(
				'Applies from the first to the last date, both included.',
				'radius-hotel-booking-pro'
			) }
			onClose={ onClose }
			footer={
				readOnly ? null : (
					<>
						{ rule ? (
							<Button
								type="button"
								variant="ghost"
								className="text-destructive"
								onClick={ () => setConfirm( true ) }
							>
								<Trash2
									className="h-4 w-4"
									aria-hidden="true"
								/>
								{ __( 'Delete', 'radius-hotel-booking-pro' ) }
							</Button>
						) : (
							<span />
						) }
						<div className="flex gap-2">
							<Button
								type="button"
								variant="ghost"
								onClick={ onClose }
							>
								{ __( 'Cancel', 'radius-hotel-booking-pro' ) }
							</Button>
							<Button
								type="submit"
								form="rtbp-rule-form"
								disabled={
									save.isPending || ! templates.length
								}
							>
								{ __( 'Save', 'radius-hotel-booking-pro' ) }
							</Button>
						</div>
					</>
				)
			}
		>
			{ ! templates.length ? (
				<p className="m-0 rounded-lg border border-warning bg-warning-soft px-3 py-2.5 text-sm text-heading">
					{ __(
						'Create a template first, on the Templates tab.',
						'radius-hotel-booking-pro'
					) }
				</p>
			) : null }
			<form
				id="rtbp-rule-form"
				onSubmit={ submit }
				noValidate
				className="space-y-5"
			>
				<fieldset
					disabled={ readOnly }
					className="m-0 space-y-5 border-0 p-0"
				>
					<Field
						label={ __( 'Template', 'radius-hotel-booking-pro' ) }
						error={ errors.template_id }
						required
					>
						<ui.Select
							value={ values.template_id }
							onValueChange={ set( 'template_id' ) }
						>
							<ui.SelectTrigger>
								<ui.SelectValue
									placeholder={ __(
										'Choose a template',
										'radius-hotel-booking-pro'
									) }
								/>
							</ui.SelectTrigger>
							<ui.SelectContent>
								{ templates.map( ( t ) => (
									<ui.SelectItem
										key={ t.id }
										value={ String( t.id ) }
									>
										{ `${ t.name } (${ t.summary })` }
									</ui.SelectItem>
								) ) }
							</ui.SelectContent>
						</ui.Select>
					</Field>
					<Field
						label={ __( 'Name', 'radius-hotel-booking-pro' ) }
						error={ errors.name }
					>
						<Input
							value={ values.name }
							onChange={ ( e ) =>
								set( 'name' )( e.target.value )
							}
							maxLength={ 120 }
							placeholder={ template?.name ?? '' }
						/>
					</Field>
					<div className="grid grid-cols-2 gap-3">
						<Field
							label={ __( 'From', 'radius-hotel-booking-pro' ) }
							error={ errors.date_from }
							required
						>
							<Input
								type="date"
								value={ values.date_from }
								onChange={ ( e ) =>
									set( 'date_from' )( e.target.value )
								}
							/>
						</Field>
						<Field
							label={ __( 'To', 'radius-hotel-booking-pro' ) }
							error={ errors.date_to }
							required
						>
							<Input
								type="date"
								value={ values.date_to }
								onChange={ ( e ) =>
									set( 'date_to' )( e.target.value )
								}
							/>
						</Field>
					</div>
					<ScopePicker
						ready={ ! scope.loading }
						legend={ __(
							'Room types',
							'radius-hotel-booking-pro'
						) }
						allText={ __(
							'None ticked: every room type.',
							'radius-hotel-booking-pro'
						) }
						options={ scope.roomTypes }
						value={ values.room_type_ids }
						onChange={ set( 'room_type_ids' ) }
						error={ errors.room_type_ids }
					/>
					<ScopePicker
						ready={ ! scope.loading }
						legend={ __(
							'Rate plans',
							'radius-hotel-booking-pro'
						) }
						allText={ __(
							'None ticked: every rate plan.',
							'radius-hotel-booking-pro'
						) }
						options={ scope.ratePlans }
						value={ values.rate_plan_ids }
						onChange={ set( 'rate_plan_ids' ) }
						error={ errors.rate_plan_ids }
					/>
					<Field
						label={ __( 'Priority', 'radius-hotel-booking-pro' ) }
						error={ errors.priority }
						description={ __(
							'Higher wins when rules overlap on a date.',
							'radius-hotel-booking-pro'
						) }
					>
						<Input
							type="number"
							inputMode="numeric"
							step={ 1 }
							value={ values.priority }
							onChange={ ( e ) =>
								set( 'priority' )( e.target.value )
							}
							className="w-28"
						/>
					</Field>
					<label className="flex items-center justify-between gap-4">
						<span className="text-sm font-semibold text-heading">
							{ __( 'Active', 'radius-hotel-booking-pro' ) }
						</span>
						<Switch
							checked={ values.is_active }
							onCheckedChange={ set( 'is_active' ) }
							disabled={ readOnly }
						/>
					</label>
				</fieldset>
			</form>
			{ rule ? (
				<ConfirmDialog
					open={ confirm }
					onOpenChange={ setConfirm }
					title={ __(
						'Delete this rule?',
						'radius-hotel-booking-pro'
					) }
					description={ __(
						'Prices go back to what they would be without it. Bookings keep their price.',
						'radius-hotel-booking-pro'
					) }
					confirmLabel={ __(
						'Delete rule',
						'radius-hotel-booking-pro'
					) }
					destructive
					onConfirm={ () =>
						remove.mutateAsync( rule.id ).then( () => {
							toast.success(
								__(
									'Rule deleted.',
									'radius-hotel-booking-pro'
								)
							);
							onClose();
						} )
					}
				/>
			) : null }
		</Drawer>
	);
}

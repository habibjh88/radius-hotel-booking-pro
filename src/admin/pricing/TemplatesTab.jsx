/**
 * Pricing templates (feature 7.11): reusable adjustments such as "increase
 * 20 %", "decrease 5 000" or "set 25 000". Seasonal rules point to one.
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ChevronRight, Percent, Plus, Trash2 } from 'lucide-react';
import {
	Badge,
	Button,
	ConfirmDialog,
	EmptyState,
	Field,
	Input,
	Panel,
	SegmentedControl,
	Skeleton,
} from '@rtbp/ui';
import { format, toast, toastError } from '@rtbp/lib';

import Drawer from './Drawer';
import { useDeleteTemplate, useSaveTemplate, useTemplates } from './api';

/**
 * The adjustment applied to a price (same rules as the server; preview only).
 *
 * @param {number} price  Price.
 * @param {Object} values `{ mode, amount_type, amount }`.
 * @return {number} New price, never below 0.
 */
export function applyAdjustment( price, values ) {
	const amount = Number( values.amount ) || 0;
	if ( values.mode === 'set' ) {
		return Math.max( 0, amount );
	}
	const delta =
		values.amount_type === 'percent' ? ( price * amount ) / 100 : amount;
	return Math.max(
		0,
		values.mode === 'decrease' ? price - delta : price + delta
	);
}

/**
 * @param {Object}   props          Props.
 * @param {boolean}  props.canEdit  May change templates.
 * @return {JSX.Element} Tab.
 */
export default function TemplatesTab( { canEdit } ) {
	const templates = useTemplates();
	// null = closed, {} = new, a template = edit.
	const [ editing, setEditing ] = useState( null );

	let body;
	if ( templates.isPending ) {
		body = <Skeleton className="h-48 w-full rounded-xl" />;
	} else if ( templates.error ) {
		body = (
			<p role="alert" className="m-0 text-sm text-destructive">
				{ templates.error.message }
			</p>
		);
	} else if ( ! templates.data.length ) {
		body = (
			<EmptyState
				icon={ Percent }
				title={ __( 'No templates yet', 'radius-hotel-booking-pro' ) }
				description={ __(
					'A template is an adjustment you reuse, such as “increase 20 %” for the festive season.',
					'radius-hotel-booking-pro'
				) }
				className="border-0"
			/>
		);
	} else {
		body = (
			<ul className="m-0 list-none divide-y divide-border rounded-lg border border-border p-0">
				{ templates.data.map( ( template ) => (
					<li key={ template.id } className="m-0">
						<button
							type="button"
							onClick={ () => setEditing( template ) }
							className="flex w-full items-center gap-3 bg-transparent px-4 py-3 text-left hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
						>
							<span className="min-w-0 flex-1">
								<span className="flex flex-wrap items-center gap-2">
									<span className="text-sm font-semibold text-heading">
										{ template.name }
									</span>
									<Badge variant="secondary">
										{ template.summary }
									</Badge>
								</span>
								<span className="mt-0.5 block text-xs text-muted-foreground">
									{ template.rules
										? sprintf(
												/* translators: %d: number of rules. */
												_n(
													'Used by %d seasonal rule',
													'Used by %d seasonal rules',
													template.rules,
													'radius-hotel-booking-pro'
												),
												template.rules
										  )
										: __(
												'Not used yet',
												'radius-hotel-booking-pro'
										  ) }
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
				title={ __( 'Templates', 'radius-hotel-booking-pro' ) }
				description={ __(
					'Changing a template changes every rule that uses it, for new bookings.',
					'radius-hotel-booking-pro'
				) }
				actions={
					canEdit ? (
						<Button size="sm" onClick={ () => setEditing( {} ) }>
							<Plus className="h-4 w-4" aria-hidden="true" />
							{ __( 'Add template', 'radius-hotel-booking-pro' ) }
						</Button>
					) : null
				}
			>
				{ body }
			</Panel>
			{ editing ? (
				<TemplateEditor
					template={ editing.id ? editing : null }
					readOnly={ ! canEdit }
					onClose={ () => setEditing( null ) }
				/>
			) : null }
		</>
	);
}

/**
 * The template editor.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.template Template, or null to add one.
 * @param {boolean}  props.readOnly Show without saving.
 * @param {Function} props.onClose  Close.
 * @return {JSX.Element} Drawer.
 */
function TemplateEditor( { template, readOnly, onClose } ) {
	const save = useSaveTemplate();
	const remove = useDeleteTemplate();
	const [ values, setValues ] = useState( {
		name: template?.name ?? '',
		mode: template?.mode ?? 'increase',
		amount_type: template?.amount_type ?? 'percent',
		amount: template ? String( template.amount ) : '',
	} );
	const [ errors, setErrors ] = useState( {} );
	const [ confirm, setConfirm ] = useState( false );
	const set = ( key ) => ( value ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setErrors( ( prev ) => ( { ...prev, [ key ]: undefined } ) );
	};

	const percent = values.mode !== 'set' && values.amount_type === 'percent';
	const symbol = format.currencyInfo?.().symbol ?? '';
	const example = 10000;

	const submit = async ( event ) => {
		event.preventDefault();
		try {
			await save.mutateAsync( { id: template?.id, values } );
			toast.success(
				template
					? __( 'Template saved.', 'radius-hotel-booking-pro' )
					: __( 'Template added.', 'radius-hotel-booking-pro' )
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
				template
					? template.name
					: __( 'New template', 'radius-hotel-booking-pro' )
			}
			description={ __(
				'An adjustment you can use in seasonal rules.',
				'radius-hotel-booking-pro'
			) }
			onClose={ onClose }
			footer={
				readOnly ? null : (
					<>
						{ template ? (
							<Button
								type="button"
								variant="ghost"
								className="text-destructive"
								disabled={ template.rules > 0 }
								title={
									template.rules > 0
										? __(
												'A template used by rules cannot be deleted.',
												'radius-hotel-booking-pro'
										  )
										: undefined
								}
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
								form="rtbp-template-form"
								disabled={ save.isPending }
							>
								{ __( 'Save', 'radius-hotel-booking-pro' ) }
							</Button>
						</div>
					</>
				)
			}
		>
			<form
				id="rtbp-template-form"
				onSubmit={ submit }
				noValidate
				className="space-y-5"
			>
				<fieldset
					disabled={ readOnly }
					className="m-0 space-y-5 border-0 p-0"
				>
					<Field
						label={ __( 'Name', 'radius-hotel-booking-pro' ) }
						error={ errors.name }
						required
					>
						<Input
							value={ values.name }
							onChange={ ( e ) =>
								set( 'name' )( e.target.value )
							}
							maxLength={ 120 }
							placeholder={ __(
								'e.g. Festive season +20 %',
								'radius-hotel-booking-pro'
							) }
						/>
					</Field>
					<div className="space-y-1.5">
						<p className="m-0 text-sm font-semibold text-heading">
							{ __( 'Adjustment', 'radius-hotel-booking-pro' ) }
						</p>
						<SegmentedControl
							label={ __(
								'Adjustment',
								'radius-hotel-booking-pro'
							) }
							value={ values.mode }
							onChange={ readOnly ? () => {} : set( 'mode' ) }
							options={ [
								{
									value: 'increase',
									label: __(
										'Increase',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'decrease',
									label: __(
										'Decrease',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'set',
									label: __(
										'Set a price',
										'radius-hotel-booking-pro'
									),
								},
							] }
						/>
					</div>
					{ values.mode !== 'set' ? (
						<SegmentedControl
							label={ __(
								'Amount type',
								'radius-hotel-booking-pro'
							) }
							value={ values.amount_type }
							onChange={
								readOnly ? () => {} : set( 'amount_type' )
							}
							options={ [
								{
									value: 'percent',
									label: __(
										'Percentage',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'fixed',
									label: __(
										'Fixed amount',
										'radius-hotel-booking-pro'
									),
								},
							] }
						/>
					) : null }
					<Field
						label={
							values.mode === 'set'
								? __( 'Price', 'radius-hotel-booking-pro' )
								: __( 'Amount', 'radius-hotel-booking-pro' )
						}
						error={ errors.amount }
					>
						<div className="relative w-40">
							<Input
								type="number"
								inputMode="decimal"
								min={ 0 }
								step="any"
								value={ values.amount }
								onChange={ ( e ) =>
									set( 'amount' )( e.target.value )
								}
								className="pr-12 tabular-nums"
							/>
							<span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-muted-foreground">
								{ percent ? '%' : symbol }
							</span>
						</div>
					</Field>
					{ values.amount !== '' ? (
						<p
							className="m-0 rounded-lg border border-border bg-muted px-3 py-2.5 text-sm text-heading"
							aria-live="polite"
						>
							{ sprintf(
								/* translators: 1: example price, 2: adjusted price. */
								__(
									'Example: %1$s becomes %2$s',
									'radius-hotel-booking-pro'
								),
								format.formatMoney( example ),
								format.formatMoney(
									applyAdjustment( example, values )
								)
							) }
						</p>
					) : null }
				</fieldset>
			</form>
			{ template ? (
				<ConfirmDialog
					open={ confirm }
					onOpenChange={ setConfirm }
					title={ __(
						'Delete this template?',
						'radius-hotel-booking-pro'
					) }
					confirmLabel={ __(
						'Delete template',
						'radius-hotel-booking-pro'
					) }
					destructive
					onConfirm={ () =>
						remove.mutateAsync( template.id ).then( () => {
							toast.success(
								__(
									'Template deleted.',
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

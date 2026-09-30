/**
 * Documents (12.16): contracts, ID copies, diplomas… kept in protected
 * storage (ADR-009: random names, denied to the web on Apache/IIS; on nginx
 * the host must add a deny rule or set RTBP_PROTECTED_DIR). Open and
 * download go through the plugin's file endpoint, which checks `staff.edit`
 * and logs each opening. The links are fetched with the nonce and the PIN
 * grant (a passcode-level `staff.edit` would refuse a plain link).
 * Upload a PDF, an image or a Word / OpenDocument file; delete asks why.
 */
import { useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Download, Eye, FileText, Image, Upload } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	EmptyState,
	Field,
	Input,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
} from '@rtbp/ui';
import { format, toast, toastError } from '@rtbp/lib';

import { downloadProtected, openProtected } from '../access/protectedFile';
import { useDocumentMutation, useDocuments } from './api';

// Downloads need the same key as the tab; with a PIN-level key the grant is sent.
const FILE_KEYS = [ 'staff.edit' ];

const ACCEPT =
	'.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.odt,application/pdf,image/jpeg,image/png,image/webp';

/**
 * `2.4 MB`, `830 KB`.
 *
 * @param {number} bytes Size.
 * @return {string} Size.
 */
const formatSize = ( bytes ) => {
	if ( bytes >= 1024 * 1024 ) {
		return sprintf(
			/* translators: %s: size in megabytes. */
			__( '%s MB', 'radius-hotel-booking-pro' ),
			String( Number( ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) ) )
		);
	}
	return sprintf(
		/* translators: %s: size in kilobytes. */
		__( '%s KB', 'radius-hotel-booking-pro' ),
		Math.max( 1, Math.round( bytes / 1024 ) )
	);
};

/**
 * @param {Object} props          Props.
 * @param {Object} props.employee Employee (detail shape).
 * @return {JSX.Element} Tab.
 */
export default function DocumentsTab( { employee } ) {
	const documents = useDocuments( employee.id );
	const mutation = useDocumentMutation( employee.id );
	const picker = useRef( null );
	const [ file, setFile ] = useState( null );
	const [ title, setTitle ] = useState( '' );
	const [ category, setCategory ] = useState( 'contract' );
	const [ errors, setErrors ] = useState( {} );
	const [ deleting, setDeleting ] = useState( null );

	const list = documents.data?.documents || [];
	const maxBytes = documents.data?.max_bytes || 0;

	const choose = ( event ) => {
		const next = event.target.files?.[ 0 ] || null;
		setErrors( {} );
		if ( next && maxBytes && next.size > maxBytes ) {
			setFile( null );
			event.target.value = '';
			setErrors( {
				file: sprintf(
					/* translators: %s: size such as "10 MB". */
					__(
						'This file is too large. The limit is %s.',
						'radius-hotel-booking-pro'
					),
					formatSize( maxBytes )
				),
			} );
			return;
		}
		setFile( next );
	};

	const upload = async ( event ) => {
		event.preventDefault();
		if ( ! file ) {
			setErrors( {
				file: __(
					'Choose a file to upload.',
					'radius-hotel-booking-pro'
				),
			} );
			return;
		}
		try {
			await mutation.mutateAsync( {
				action: 'upload',
				file,
				title,
				category,
			} );
			toast.success(
				__( 'Document added.', 'radius-hotel-booking-pro' )
			);
			setFile( null );
			setTitle( '' );
			if ( picker.current ) {
				picker.current.value = '';
			}
		} catch ( err ) {
			const mapped = Object.fromEntries(
				Object.entries( err?.errors || {} ).map( ( [ key, value ] ) => [
					key,
					value?.first_message,
				] )
			);
			if ( Object.keys( mapped ).length ) {
				setErrors( mapped );
			} else {
				toastError( err );
			}
		}
	};

	return (
		<div className="space-y-4">
			<form
				className="space-y-3 rounded-lg border border-border p-4"
				onSubmit={ upload }
			>
				<h3 className="m-0 flex items-center gap-2 text-sm font-semibold text-heading">
					<Upload
						className="h-4 w-4 text-muted-foreground"
						aria-hidden="true"
					/>
					{ __( 'Add a document', 'radius-hotel-booking-pro' ) }
				</h3>
				<div className="grid gap-4 sm:grid-cols-2">
					<Field
						label={ __( 'File', 'radius-hotel-booking-pro' ) }
						error={ errors.file }
						description={
							maxBytes
								? sprintf(
										/* translators: %s: size such as "10 MB". */
										__(
											'PDF, image, Word or OpenDocument, up to %s.',
											'radius-hotel-booking-pro'
										),
										formatSize( maxBytes )
								  )
								: ''
						}
						required
					>
						<Input
							ref={ picker }
							type="file"
							accept={ ACCEPT }
							onChange={ choose }
						/>
					</Field>
					<Field
						label={ __( 'Category', 'radius-hotel-booking-pro' ) }
						error={ errors.category }
					>
						<Select
							value={ category }
							onValueChange={ setCategory }
						>
							<SelectTrigger>
								<SelectValue />
							</SelectTrigger>
							<SelectContent className="rtbp-root">
								{ Object.entries(
									documents.data?.categories || {}
								).map( ( [ key, label ] ) => (
									<SelectItem key={ key } value={ key }>
										{ label }
									</SelectItem>
								) ) }
							</SelectContent>
						</Select>
					</Field>
					<Field
						label={ __( 'Title', 'radius-hotel-booking-pro' ) }
						error={ errors.title }
						description={ __(
							'Leave empty to use the file name.',
							'radius-hotel-booking-pro'
						) }
					>
						<Input
							value={ title }
							maxLength={ 191 }
							onChange={ ( e ) => setTitle( e.target.value ) }
							autoComplete="off"
						/>
					</Field>
				</div>
				<div className="flex flex-wrap justify-end gap-2">
					<Button type="submit" disabled={ mutation.isPending }>
						<Upload className="h-4 w-4" aria-hidden="true" />
						{ mutation.isPending
							? __( 'Uploading…', 'radius-hotel-booking-pro' )
							: __( 'Upload', 'radius-hotel-booking-pro' ) }
					</Button>
				</div>
			</form>

			<section className="space-y-3 rounded-lg border border-border p-4">
				<h3 className="m-0 flex items-center gap-2 text-sm font-semibold text-heading">
					<FileText
						className="h-4 w-4 text-muted-foreground"
						aria-hidden="true"
					/>
					{ __( 'Documents', 'radius-hotel-booking-pro' ) }
				</h3>
				{ documents.isPending ? (
					<Skeleton className="h-16 w-full" />
				) : null }
				{ documents.error ? (
					<div className="flex flex-wrap items-center justify-between gap-2 text-sm text-destructive">
						{ documents.error.message }
						<Button
							type="button"
							size="sm"
							variant="outline"
							onClick={ () => documents.refetch() }
						>
							{ __( 'Try again', 'radius-hotel-booking-pro' ) }
						</Button>
					</div>
				) : null }
				{ documents.data && ! list.length ? (
					<EmptyState
						icon={ FileText }
						title={ __(
							'No documents yet',
							'radius-hotel-booking-pro'
						) }
						description={ __(
							'Keep the contract, ID copies and diplomas here. Only staff who may edit employee files can open them.',
							'radius-hotel-booking-pro'
						) }
						className="border-0 py-6"
					/>
				) : null }
				{ list.length ? (
					<ul className="m-0 list-none space-y-2 p-0">
						{ list.map( ( doc ) => {
							const Icon = doc.mime.startsWith( 'image/' )
								? Image
								: FileText;
							return (
								<li
									key={ doc.id }
									className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
								>
									<div className="flex min-w-0 items-center gap-3">
										<Icon
											className="h-5 w-5 shrink-0 text-muted-foreground"
											aria-hidden="true"
										/>
										<div className="min-w-0">
											<p className="m-0 truncate text-sm font-semibold text-heading">
												{ doc.title }
											</p>
											<p className="m-0 text-xs text-muted-foreground">
												{ [
													doc.category_label,
													formatSize( doc.size ),
													format.formatDate(
														doc.created_at
													),
													doc.uploaded_by,
												]
													.filter( Boolean )
													.join( ' · ' ) }
											</p>
										</div>
									</div>
									<div className="flex flex-wrap gap-2">
										<Button
											type="button"
											size="sm"
											variant="outline"
											onClick={ () =>
												openProtected(
													doc.view_url,
													FILE_KEYS
												).catch( toastError )
											}
										>
											<Eye
												className="h-4 w-4"
												aria-hidden="true"
											/>
											{ __(
												'Open',
												'radius-hotel-booking-pro'
											) }
										</Button>
										<Button
											type="button"
											size="sm"
											variant="outline"
											onClick={ () =>
												downloadProtected(
													doc.url,
													doc.file_name || doc.title,
													FILE_KEYS
												).catch( toastError )
											}
										>
											<Download
												className="h-4 w-4"
												aria-hidden="true"
											/>
											{ __(
												'Download',
												'radius-hotel-booking-pro'
											) }
										</Button>
										<Button
											type="button"
											size="sm"
											variant="ghost"
											className="text-destructive"
											onClick={ () => setDeleting( doc ) }
										>
											{ __(
												'Delete',
												'radius-hotel-booking-pro'
											) }
										</Button>
									</div>
								</li>
							);
						} ) }
					</ul>
				) : null }
			</section>

			<ConfirmDialog
				open={ Boolean( deleting ) }
				onOpenChange={ ( next ) => ! next && setDeleting( null ) }
				title={ sprintf(
					/* translators: %s: document title. */
					__( 'Delete "%s"?', 'radius-hotel-booking-pro' ),
					deleting?.title || ''
				) }
				description={ __(
					'The file is removed for good. The history keeps its title and who deleted it.',
					'radius-hotel-booking-pro'
				) }
				confirmLabel={ __( 'Delete', 'radius-hotel-booking-pro' ) }
				destructive
				requireReason
				onConfirm={ ( reason ) =>
					mutation
						.mutateAsync( {
							action: 'delete',
							document: deleting.id,
							reason,
						} )
						.then( () => {
							setDeleting( null );
							toast.success(
								__(
									'Document deleted.',
									'radius-hotel-booking-pro'
								)
							);
						} )
				}
			/>
		</div>
	);
}

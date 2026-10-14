/**
 * Staff records (M12): every REST call of the staff screens and their React
 * Query hooks. The cache is the free app's.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

export const EMPLOYEES_KEY = [ 'employees' ];

/**
 * A page of employees.
 *
 * @param {Object} filters `{ q, active, page, per_page }`.
 * @return {Object} Query; data `{ employees, total }`.
 */
export const useEmployees = ( filters ) =>
	useQuery( {
		queryKey: [ ...EMPLOYEES_KEY, 'list', filters ],
		queryFn: () =>
			api.get( 'employees', filters ).then( ( r ) => ( {
				employees: r.data.employees,
				total: r.meta?.total ?? r.data.employees.length,
			} ) ),
		placeholderData: ( previous ) => previous,
	} );

/**
 * Deactivate or reactivate an employee; refreshes the lists and the file.
 *
 * @return {Object} Mutation; `mutateAsync( { id, action: 'deactivate'|'activate', reason } )`.
 */
export function useSetActive() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, action, reason } ) =>
			api
				.post( `employees/${ id }/${ action }`, { reason } )
				.then( ( r ) => r.data.employee ),
		onSuccess: () =>
			client.invalidateQueries( { queryKey: EMPLOYEES_KEY } ),
	} );
}

/**
 * One employee (detail shape).
 *
 * @param {number} id Employee id.
 * @return {Object} Query; data is the employee.
 */
export const useEmployee = ( id ) =>
	useQuery( {
		queryKey: [ ...EMPLOYEES_KEY, 'one', Number( id ) ],
		queryFn: () =>
			api.get( `employees/${ id }` ).then( ( r ) => r.data.employee ),
		enabled: Boolean( id ),
	} );

/**
 * Access roles and the lists the staff forms offer.
 *
 * @return {Object} Query; data `{ access_roles, advanced_access, marital, relationships }`.
 */
export const useStaffOptions = () =>
	useQuery( {
		queryKey: [ ...EMPLOYEES_KEY, 'options' ],
		queryFn: () => api.get( 'employees/options' ).then( ( r ) => r.data ),
		staleTime: 5 * 60 * 1000,
	} );

/**
 * A mutation that stores the returned employee and refreshes the lists.
 *
 * @param {Function} send Called with the variables; resolves with the employee.
 * @return {Object} Mutation.
 */
function useEmployeeMutation( send ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: send,
		onSuccess: ( employee ) => {
			if ( employee?.id ) {
				client.setQueryData(
					[ ...EMPLOYEES_KEY, 'one', Number( employee.id ) ],
					employee
				);
			}
			client.invalidateQueries( {
				queryKey: [ ...EMPLOYEES_KEY, 'list' ],
			} );
		},
	} );
}

/** Add an employee; resolves with the employee. */
export const useCreateEmployee = () =>
	useEmployeeMutation( ( fields ) =>
		api.post( 'employees', fields ).then( ( r ) => r.data.employee )
	);

/** Change the file fields of an employee; resolves with the employee. */
export const useUpdateEmployee = ( id ) =>
	useEmployeeMutation( ( fields ) =>
		api.put( `employees/${ id }`, fields ).then( ( r ) => r.data.employee )
	);

/** Change the account (password, PIN, access role); resolves with the employee. */
export const useUpdateAccount = ( id ) =>
	useEmployeeMutation( ( fields ) =>
		api
			.put( `employees/${ id }/account`, fields )
			.then( ( r ) => r.data.employee )
	);

/**
 * E-mail the employee a link to set their password.
 *
 * @param {number} id Employee id.
 * @return {Promise} Request.
 */
export const sendPasswordLink = ( id ) =>
	api.post( `employees/${ id }/password-link` );

/**
 * The full social security and tax numbers of an employee (`staff.view_ids`; each
 * reveal is logged).
 *
 * @param {number} id Employee id.
 * @return {Promise<{cnps_no: string, tax_no: string}>} Numbers.
 */
export const revealIdentifiers = ( id ) =>
	api.get( `employees/${ id }/identifiers` ).then( ( r ) => r.data );

/**
 * An employee's contracts, newest first, and the job types.
 *
 * @param {number} id Employee id.
 * @return {Object} Query; data `{ contracts, job_types }`.
 */
export const useContracts = ( id ) =>
	useQuery( {
		queryKey: [ ...EMPLOYEES_KEY, 'contracts', Number( id ) ],
		queryFn: () =>
			api.get( `employees/${ id }/contracts` ).then( ( r ) => r.data ),
		enabled: Boolean( id ),
	} );

/**
 * Add, change, end or delete a contract. Refreshes the contracts and the
 * employee (the active contract sets the job title and department).
 *
 * @param {number} id Employee id.
 * @return {Object} Mutation; `mutateAsync( { action: 'create'|'update'|'terminate'|'delete', contract, fields, reason } )`.
 */
export function useContractMutation( id ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { action, contract, fields, reason } ) => {
			const base = `employees/${ id }/contracts`;
			switch ( action ) {
				case 'create':
					return api.post( base, fields );
				case 'update':
					return api.put( `${ base }/${ contract }`, fields );
				case 'terminate':
					return api.post( `${ base }/${ contract }/terminate`, {
						...fields,
						reason,
					} );
				default:
					return api.del(
						`${ base }/${ contract }?reason=${ encodeURIComponent(
							reason || ''
						) }`
					);
			}
		},
		onSuccess: () =>
			client.invalidateQueries( { queryKey: EMPLOYEES_KEY } ),
	} );
}

/**
 * An employee's documents, the categories and the upload limit.
 *
 * @param {number} id Employee id.
 * @return {Object} Query; data `{ documents, categories, max_bytes }`.
 */
export const useDocuments = ( id ) =>
	useQuery( {
		queryKey: [ ...EMPLOYEES_KEY, 'documents', Number( id ) ],
		queryFn: () =>
			api.get( `employees/${ id }/documents` ).then( ( r ) => r.data ),
		enabled: Boolean( id ),
	} );

/**
 * Upload or delete a document; refreshes the list.
 *
 * @param {number} id Employee id.
 * @return {Object} Mutation; `mutateAsync( { action: 'upload', file, title, category } | { action: 'delete', document, reason } )`.
 */
export function useDocumentMutation( id ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { action, file, title, category, document, reason } ) => {
			const base = `employees/${ id }/documents`;
			if ( 'upload' === action ) {
				const body = new window.FormData();
				body.append( 'file', file );
				body.append( 'title', title || '' );
				body.append( 'category', category || 'other' );
				return api.request( base, { method: 'POST', body } );
			}
			return api.del(
				`${ base }/${ document }?reason=${ encodeURIComponent(
					reason || ''
				) }`
			);
		},
		onSuccess: () =>
			client.invalidateQueries( {
				queryKey: [ ...EMPLOYEES_KEY, 'documents', Number( id ) ],
			} ),
	} );
}

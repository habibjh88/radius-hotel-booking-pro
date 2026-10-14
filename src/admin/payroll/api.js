/**
 * Payroll REST calls (M15). Every read needs `page.payroll`; writes need
 * their own key (the server enforces both).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

export const SCHEDULES_KEY = [ 'payroll', 'schedules' ];

/**
 * Pay schedules with their next periods, the interval list and today.
 *
 * @return {Object} Query.
 */
export const usePaySchedules = () =>
	useQuery( {
		queryKey: SCHEDULES_KEY,
		queryFn: () => api.get( 'payroll/schedules' ).then( ( r ) => r.data ),
	} );

/**
 * Add (no `id`) or change (with `id`) a pay schedule.
 *
 * @return {Object} Mutation; `mutateAsync( { id?, name, pay_interval, period_end, next_pay_day } )`.
 */
export function useSaveSchedule() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, ...input } ) =>
			id
				? api.put( `payroll/schedules/${ id }`, input )
				: api.post( 'payroll/schedules', input ),
		onSuccess: () =>
			client.invalidateQueries( { queryKey: SCHEDULES_KEY } ),
	} );
}

/**
 * Delete a pay schedule nothing uses.
 *
 * @return {Object} Mutation; `mutateAsync( id )`.
 */
export function useDeleteSchedule() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( id ) => api.del( `payroll/schedules/${ id }` ),
		onSuccess: () =>
			client.invalidateQueries( { queryKey: SCHEDULES_KEY } ),
	} );
}

export const SHIFTS_KEY = [ 'payroll', 'shifts' ];

/**
 * Work shifts with their days, hours a week and employees.
 *
 * @return {Object} Query.
 */
export const useWorkShifts = () =>
	useQuery( {
		queryKey: SHIFTS_KEY,
		queryFn: () => api.get( 'payroll/shifts' ).then( ( r ) => r.data ),
	} );

/**
 * Add (no `id`) or change (with `id`) a work shift.
 *
 * @return {Object} Mutation; `mutateAsync( { id?, name, description, days } )`.
 */
export function useSaveShift() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, ...input } ) =>
			id
				? api.put( `payroll/shifts/${ id }`, input )
				: api.post( 'payroll/shifts', input ),
		onSuccess: () => client.invalidateQueries( { queryKey: SHIFTS_KEY } ),
	} );
}

/**
 * Delete a work shift nobody works.
 *
 * @return {Object} Mutation; `mutateAsync( id )`.
 */
export function useDeleteShift() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( id ) => api.del( `payroll/shifts/${ id }` ),
		onSuccess: () => client.invalidateQueries( { queryKey: SHIFTS_KEY } ),
	} );
}

/**
 * An employee's pay profile, with the schedules and shifts to choose from.
 *
 * @param {number} employeeId Pro employee id.
 * @return {Object} Query.
 */
export const useEmployeePay = ( employeeId ) =>
	useQuery( {
		queryKey: [ 'payroll', 'pay', employeeId ],
		queryFn: () =>
			api
				.get( `payroll/employees/${ employeeId }/pay` )
				.then( ( r ) => r.data ),
		enabled: employeeId > 0,
	} );

/**
 * Save an employee's pay profile.
 *
 * @param {number} employeeId Pro employee id.
 * @return {Object} Mutation; `mutateAsync( { pay_schedule_id, work_shift_id, pay_type, amount, amount_period } )`.
 */
export function useSaveEmployeePay( employeeId ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( input ) =>
			api.put( `payroll/employees/${ employeeId }/pay`, input ),
		onSuccess: ( { data } ) => {
			client.setQueryData( [ 'payroll', 'pay', employeeId ], data );
			// Employee counts on the schedules and shifts tabs.
			client.invalidateQueries( { queryKey: SCHEDULES_KEY } );
			client.invalidateQueries( { queryKey: SHIFTS_KEY } );
		},
	} );
}

/**
 * Save an employee's additional pay types.
 *
 * @param {number} employeeId Pro employee id.
 * @return {Object} Mutation; `mutateAsync( { type: settings } )`.
 */
export function useSaveEmployeePayItems( employeeId ) {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( items ) =>
			api.put( `payroll/employees/${ employeeId }/pay-items`, {
				items,
			} ),
		onSuccess: ( { data } ) =>
			client.setQueryData( [ 'payroll', 'pay', employeeId ], data ),
	} );
}

/**
 * An employee's deductions and garnishments.
 *
 * @param {number} employeeId Pro employee id.
 * @return {Object} Query.
 */
export const useDeductions = ( employeeId ) =>
	useQuery( {
		queryKey: [ 'payroll', 'deductions', employeeId ],
		queryFn: () =>
			api
				.get( `payroll/employees/${ employeeId }/deductions` )
				.then( ( r ) => r.data.deductions ),
		enabled: employeeId > 0,
	} );

/**
 * Add (no `id`), change (with `id`) or delete (`remove: true`) a deduction.
 *
 * @param {number} employeeId Pro employee id.
 * @return {Object} Mutation.
 */
export function useDeductionMutation( employeeId ) {
	const client = useQueryClient();
	const base = `payroll/employees/${ employeeId }/deductions`;
	return useMutation( {
		mutationFn: ( { id, remove, ...input } ) => {
			if ( remove ) {
				return api.del( `${ base }/${ id }` );
			}
			return id
				? api.put( `${ base }/${ id }`, input )
				: api.post( base, input );
		},
		onSuccess: () =>
			client.invalidateQueries( {
				queryKey: [ 'payroll', 'deductions', employeeId ],
			} ),
	} );
}

export const RUNS_KEY = [ 'payroll', 'runs' ];

/**
 * Pay runs, newest pay date first.
 *
 * @param {Object} filters `{ status, page }`.
 * @return {Object} Query.
 */
export const usePayrollRuns = ( filters ) =>
	useQuery( {
		queryKey: [ ...RUNS_KEY, filters ],
		queryFn: () =>
			api
				.get( 'payroll/runs', { per_page: 20, ...filters } )
				.then( ( r ) => r.data ),
	} );

/**
 * One pay run with its lines.
 *
 * @param {number} id Run id.
 * @return {Object} Query.
 */
export const usePayrollRun = ( id ) =>
	useQuery( {
		queryKey: [ ...RUNS_KEY, 'one', id ],
		queryFn: () =>
			api.get( `payroll/runs/${ id }` ).then( ( r ) => r.data.run ),
		enabled: id > 0,
	} );

/**
 * A pay run action: `create` (`{ schedule_id }`), `line` (`{ line, entries, custom, hours }`),
 * `recalculate`, `finalize`, `settle` (`{ payments }`), `void` (`{ reason }`), `revert`, `remove`.
 *
 * @return {Object} Mutation; `mutateAsync( { action, id?, ...input } )`.
 */
export function usePayrollRunAction() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { action, id, line, ...input } ) => {
			const base = `payroll/runs/${ id }`;
			if ( 'create' === action ) {
				return api.post( 'payroll/runs', input );
			}
			if ( 'line' === action ) {
				return api.put( `${ base }/lines/${ line }`, input );
			}
			if ( 'remove' === action ) {
				return api.del( base );
			}
			return api.post( `${ base }/${ action }`, input );
		},
		onSuccess: ( response, { action, id } ) => {
			if ( response?.data?.run && 'remove' !== action ) {
				client.setQueryData(
					[ ...RUNS_KEY, 'one', response.data.run.id || id ],
					response.data.run
				);
			}
			client.invalidateQueries( { queryKey: RUNS_KEY } );
			// Garnishments' remaining amounts and schedule use follow the runs.
			client.invalidateQueries( {
				queryKey: [ 'payroll', 'deductions' ],
			} );
			client.invalidateQueries( { queryKey: SCHEDULES_KEY } );
		},
	} );
}

export const STATUTORY_KEY = [ 'payroll', 'statutory' ];

/**
 * The months with contributions.
 *
 * @return {Object} Query.
 */
export const useStatutoryMonths = () =>
	useQuery( {
		queryKey: [ ...STATUTORY_KEY, 'months' ],
		queryFn: () =>
			api.get( 'payroll/statutory' ).then( ( r ) => r.data.months ),
	} );

/**
 * One month's contributions.
 *
 * @param {number} year  Year.
 * @param {number} month Month.
 * @return {Object} Query.
 */
export const useStatutoryMonth = ( year, month ) =>
	useQuery( {
		queryKey: [ ...STATUTORY_KEY, year, month ],
		queryFn: () =>
			api
				.get( `payroll/statutory/${ year }/${ month }` )
				.then( ( r ) => r.data ),
		enabled: year > 0 && month > 0,
	} );

/**
 * Record (`{ year, month, authority, code, amount, paid_on, method, reference }`) or void (`{ void: id, reason }`) a payment.
 *
 * @return {Object} Mutation.
 */
export function useStatutoryPayment() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { void: id, reason, ...input } ) =>
			id
				? api.post( `payroll/statutory/payments/${ id }/void`, {
						reason,
				  } )
				: api.post( 'payroll/statutory/payments', input ),
		onSuccess: () =>
			client.invalidateQueries( { queryKey: STATUTORY_KEY } ),
	} );
}

export const TIME_OFF_KEY = [ 'payroll', 'time-off' ];

/**
 * Time off requests (pending first), with the types, the vacation quota,
 * today and — for those who may add requests — the active employees.
 *
 * @param {Object} filters `{ status?, employee_id?, year? }`.
 * @return {Object} Query.
 */
export const useTimeOff = ( filters ) =>
	useQuery( {
		queryKey: [ ...TIME_OFF_KEY, filters ],
		queryFn: () =>
			api.get( 'payroll/time-off', filters ).then( ( r ) => r.data ),
		placeholderData: ( previous ) => previous,
	} );

/**
 * An employee's balance for a year: the vacation quota and approved days per type.
 *
 * @param {number} employeeId Employee (0 = none: the query waits).
 * @param {number} year       Year.
 * @return {Object} Query.
 */
export const useTimeOffBalance = ( employeeId, year ) =>
	useQuery( {
		queryKey: [ ...TIME_OFF_KEY, 'balance', employeeId, year ],
		queryFn: () =>
			api
				.get( 'payroll/time-off/balance', {
					employee_id: employeeId,
					year,
				} )
				.then( ( r ) => r.data ),
		enabled: employeeId > 0 && year > 0,
	} );

/**
 * Add a request (`{ employee_id, type, start_date, end_date, reason }`), or
 * decide one (`{ id, decision: 'approve' | 'decline', note }`).
 *
 * @return {Object} Mutation.
 */
export function useTimeOffMutation() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { id, decision, ...input } ) =>
			id
				? api.post( `payroll/time-off/${ id }/${ decision }`, input )
				: api.post( 'payroll/time-off', input ),
		onSuccess: () => client.invalidateQueries( { queryKey: TIME_OFF_KEY } ),
	} );
}

/**
 * My payroll REST calls (M15 T9): always the signed-in user's own payslips
 * and time off (`page.my_payroll`).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@rtbp/lib';

export const MY_PAYROLL_KEY = [ 'payroll', 'me' ];

/**
 * My payslips: settled runs, newest first.
 *
 * @return {Object} Query; data = payslips.
 */
export const useMyPayslips = () =>
	useQuery( {
		queryKey: [ ...MY_PAYROLL_KEY, 'payslips' ],
		queryFn: () =>
			api.get( 'me/payslips' ).then( ( r ) => r.data.payslips ),
		retry: false,
	} );

/**
 * My time off for a year: requests, balance, types, today.
 *
 * @param {number} year Year.
 * @return {Object} Query.
 */
export const useMyTimeOff = ( year ) =>
	useQuery( {
		queryKey: [ ...MY_PAYROLL_KEY, 'time-off', year ],
		queryFn: () =>
			api.get( 'me/time-off', { year } ).then( ( r ) => r.data ),
		enabled: year > 0,
		placeholderData: ( previous ) => previous,
		retry: false,
	} );

/**
 * My balance for a year, shaped as the request form expects (`data` = balance).
 *
 * @param {number} _employeeId Unused: always mine.
 * @param {number} year        Year.
 * @return {Object} `{ data }`.
 */
export const useMyBalance = ( _employeeId, year ) => {
	const query = useMyTimeOff( year );
	return { ...query, data: query.data?.balance };
};

/**
 * Ask for time off (`{ type, start_date, end_date, reason }`), or cancel a
 * pending request (`{ cancel: id }`).
 *
 * @return {Object} Mutation.
 */
export function useMyTimeOffMutation() {
	const client = useQueryClient();
	return useMutation( {
		mutationFn: ( { cancel, ...input } ) =>
			cancel
				? api.post( `me/time-off/${ cancel }/cancel` )
				: api.post( 'me/time-off', input ),
		onSuccess: () =>
			client.invalidateQueries( { queryKey: MY_PAYROLL_KEY } ),
	} );
}

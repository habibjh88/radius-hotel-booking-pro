/**
 * The work shift of an employee, picked from Payroll → Work shifts (M15):
 * the same shift the Pay tab sets and payroll counts the hours from. Shown
 * only while Payroll is on and the viewer may open it; changing it also
 * needs `payroll.pay_profile` (the server checks again).
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Field,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
} from '@rtbp/ui';
import { access } from '@rtbp/lib';

import { useWorkShifts } from '../payroll/api';

const NONE = 'none';

/**
 * Whether the work shift can be shown here: Payroll on, and not locked.
 *
 * @return {boolean} Shown.
 */
export function useShiftVisible() {
	const level = access?.useAccess
		? access.useAccess( 'page.payroll' )
		: 'locked';
	const payroll = ( window.rtbpPro?.features || [] ).some(
		( feature ) => 'payroll' === feature.key && 'active' === feature.state
	);
	return payroll && 'locked' !== level;
}

/**
 * @param {Object}   props          Props.
 * @param {string}   props.value    Shift id as a string, '' for none.
 * @param {Function} props.onChange Called with the id as a string ('' for none).
 * @param {string}   props.error    Error.
 * @param {boolean}  props.disabled Read-only (no `staff.edit`).
 * @return {JSX.Element} Field.
 */
export default function WorkShiftSelect( {
	value,
	onChange,
	error,
	disabled,
} ) {
	const shifts = useWorkShifts();
	const canPay = access?.useAccess
		? 'locked' !== access.useAccess( 'payroll.pay_profile' )
		: false;
	const list = shifts.data?.shifts || [];

	return (
		<Field
			label={ __( 'Work shift', 'radius-hotel-booking-pro' ) }
			error={ error }
			description={ __(
				'Their working hours, from Payroll → Work shifts.',
				'radius-hotel-booking-pro'
			) }
		>
			<Select
				value={ value ? String( value ) : NONE }
				onValueChange={ ( next ) =>
					onChange( NONE === next ? '' : next )
				}
				disabled={ disabled || ! canPay || shifts.isPending }
			>
				<SelectTrigger>
					<SelectValue />
				</SelectTrigger>
				<SelectContent className="rtbp-root">
					<SelectItem value={ NONE }>
						{ __( 'No work shift', 'radius-hotel-booking-pro' ) }
					</SelectItem>
					{ list.map( ( shift ) => (
						<SelectItem
							key={ shift.id }
							value={ String( shift.id ) }
						>
							{ sprintf(
								/* translators: 1: shift name, 2: hours a week. */
								__(
									'%1$s (%2$s h a week)',
									'radius-hotel-booking-pro'
								),
								shift.name,
								String(
									Math.round( shift.hours_per_week * 100 ) /
										100
								)
							) }
						</SelectItem>
					) ) }
				</SelectContent>
			</Select>
		</Field>
	);
}

/**
 * Staff (`#/staff`, features 12.1, 12.4): every employee with number, job,
 * shift, phone, access role and active state. Active staff first; search by
 * name, number, phone, e-mail, job or user name. With `staff.deactivate`, a
 * row can be deactivated (sign-in blocked, sessions ended) or reactivated.
 */
import { Suspense, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Plus, Power, PowerOff, UsersRound } from 'lucide-react';
import {
	Button,
	ConfirmDialog,
	DataTable,
	EmptyState,
	FilterTabs,
	Panel,
	Select,
	SelectContent,
	SelectItem,
	SelectTrigger,
	SelectValue,
	Skeleton,
	StatusBadge,
} from '@rtbp/ui';
import { access, toast, usePageActions } from '@rtbp/lib';

import { useEmployees, useSetActive, useStaffOptions } from './api';
import { StaffAvatar } from './StaffPhoto';
import { useShiftVisible } from './WorkShiftSelect';

const PER_PAGE = 20;

/**
 * A department or job title filter: "All …" or one name.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.value    The name ('' = all).
 * @param {string[]} props.names    Names offered.
 * @param {string}   props.all      Label of "all".
 * @param {Function} props.onChange Called with the name ('' = all).
 * @return {JSX.Element} Select.
 */
function NameFilter( { value, names, all, onChange } ) {
	const ALL = '__all__';
	return (
		<Select
			value={ value || ALL }
			onValueChange={ ( next ) => onChange( ALL === next ? '' : next ) }
		>
			<SelectTrigger
				className="h-9 w-auto min-w-[10rem]"
				aria-label={ all }
			>
				<SelectValue />
			</SelectTrigger>
			<SelectContent className="rtbp-root">
				<SelectItem value={ ALL }>{ all }</SelectItem>
				{ names.map( ( name ) => (
					<SelectItem key={ name } value={ name }>
						{ name }
					</SelectItem>
				) ) }
			</SelectContent>
		</Select>
	);
}

/**
 * @return {JSX.Element} Screen.
 */
export default function StaffListScreen() {
	const [ params, setParams ] = useSearchParams();
	const canToggle = access?.useAccess
		? access.useAccess( 'staff.deactivate' ) !== 'locked'
		: false;
	const canCreate = access?.useAccess
		? access.useAccess( 'staff.create' ) !== 'locked'
		: false;
	const [ toggling, setToggling ] = useState( null );
	const setActive = useSetActive();
	const navigate = useNavigate();

	usePageActions(
		canCreate ? (
			<Button onClick={ () => navigate( '/staff/new' ) }>
				<Plus className="h-4 w-4" aria-hidden="true" />
				<span className="sr-only sm:not-sr-only">
					{ __( 'Add employee', 'radius-hotel-booking-pro' ) }
				</span>
			</Button>
		) : null,
		[ canCreate ]
	);

	const q = params.get( 'q' ) || '';
	const tab = [ 'active', 'inactive', 'all' ].includes( params.get( 'show' ) )
		? params.get( 'show' )
		: 'active';
	const page = Math.max( 1, Number( params.get( 'page' ) ) || 1 );
	// Filters and sort (Settings → Staff lists, plus names already on files).
	const department = params.get( 'department' ) || '';
	const jobTitle = params.get( 'job_title' ) || '';
	const sort = {
		by: params.get( 'sort' ) || '',
		dir: 'desc' === params.get( 'dir' ) ? 'desc' : 'asc',
	};
	const list = useEmployees( {
		q,
		active: 'all' === tab ? '' : tab,
		page,
		per_page: PER_PAGE,
		...( department ? { department } : {} ),
		...( jobTitle ? { job_title: jobTitle } : {} ),
		...( sort.by ? { sort: sort.by, dir: sort.dir } : {} ),
	} );
	const options = useStaffOptions();
	const choices = ( listed, used ) =>
		[ ...new Set( [ ...( listed || [] ), ...( used || [] ) ] ) ].sort(
			( a, b ) => a.localeCompare( b )
		);
	const departments = choices(
		options.data?.departments,
		options.data?.in_use?.departments
	);
	const jobTitles = choices(
		options.data?.job_titles,
		options.data?.in_use?.jobTitles
	);

	const setView = ( changes ) =>
		setParams(
			( prev ) => {
				const next = new URLSearchParams( prev );
				Object.entries( changes ).forEach( ( [ key, value ] ) =>
					value
						? next.set( key, String( value ) )
						: next.delete( key )
				);
				return next;
			},
			{ replace: true }
		);

	// The payroll work shift (M15): no column while Payroll is off.
	const showShift = useShiftVisible();
	const columns = [
		{
			id: 'name',
			header: __( 'Employee', 'radius-hotel-booking-pro' ),
			sortable: true,
			mobile: 'title',
			cell: ( employee ) => (
				<span className="inline-flex items-center gap-3">
					<StaffAvatar employee={ employee } />
					<span className="inline-flex flex-wrap items-baseline gap-x-2">
						<span className="font-semibold text-heading">
							{ employee.name || employee.user_login }
						</span>
						<span className="text-xs text-muted-foreground">
							{ employee.employee_no }
						</span>
					</span>
				</span>
			),
		},
		{
			id: 'job_title',
			header: __( 'Job title', 'radius-hotel-booking-pro' ),
			sortable: true,
			mobile: 'subtitle',
			cell: ( employee ) => employee.job_title || '—',
		},
		{
			id: 'department',
			header: __( 'Department', 'radius-hotel-booking-pro' ),
			sortable: true,
			cell: ( employee ) => employee.department || '—',
		},
		showShift && {
			id: 'shift',
			header: __( 'Shift', 'radius-hotel-booking-pro' ),
			sortable: true,
			cell: ( employee ) => employee.shift || '—',
		},
		{
			id: 'phone',
			header: __( 'Phone', 'radius-hotel-booking-pro' ),
			cell: ( employee ) => employee.phone || '—',
		},
		{
			id: 'role',
			header: __( 'Access role', 'radius-hotel-booking-pro' ),
			cell: ( employee ) => employee.access_role?.name || '—',
		},
		{
			id: 'state',
			header: __( 'Status', 'radius-hotel-booking-pro' ),
			cell: ( employee ) => (
				<StatusBadge
					domain="active"
					value={ employee.is_active ? 'active' : 'inactive' }
				/>
			),
		},
	].filter( Boolean );

	const rowActions = ( employee ) =>
		canToggle
			? [
					employee.is_active
						? {
								label: __(
									'Deactivate',
									'radius-hotel-booking-pro'
								),
								icon: PowerOff,
								destructive: true,
								onSelect: () =>
									setToggling( {
										employee,
										action: 'deactivate',
									} ),
						  }
						: {
								label: __(
									'Reactivate',
									'radius-hotel-booking-pro'
								),
								icon: Power,
								onSelect: () =>
									setToggling( {
										employee,
										action: 'activate',
									} ),
						  },
			  ]
			: [];

	const empty = (
		<EmptyState
			icon={ UsersRound }
			title={
				q
					? __( 'No employee matches', 'radius-hotel-booking-pro' )
					: 'inactive' === tab
					? __(
							'No deactivated employees',
							'radius-hotel-booking-pro'
					  )
					: __( 'No employees yet', 'radius-hotel-booking-pro' )
			}
			description={
				q
					? __(
							'Try part of the name, the number, the phone or the job.',
							'radius-hotel-booking-pro'
					  )
					: ''
			}
			className="border-0 py-12"
		/>
	);

	const deactivating = 'deactivate' === toggling?.action;

	return (
		// DataTable, ConfirmDialog and the menus load on demand (window.rtbp.ui).
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<div className="space-y-4">
				<Panel
					title={ __( 'Staff', 'radius-hotel-booking-pro' ) }
					description={
						list.data
							? sprintf(
									/* translators: %d: number of employees. */
									_n(
										'%d employee',
										'%d employees',
										list.data.total,
										'radius-hotel-booking-pro'
									),
									list.data.total
							  )
							: null
					}
				>
					<div className="space-y-3">
						<FilterTabs
							label={ __( 'Show', 'radius-hotel-booking-pro' ) }
							value={ tab }
							onChange={ ( next ) =>
								setView( {
									show: 'active' === next ? '' : next,
									page: '',
								} )
							}
							tabs={ [
								{
									value: 'active',
									label: __(
										'Active',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'inactive',
									label: __(
										'Deactivated',
										'radius-hotel-booking-pro'
									),
								},
								{
									value: 'all',
									label: __(
										'All',
										'radius-hotel-booking-pro'
									),
								},
							] }
						/>
						<DataTable
							columns={ columns }
							rows={ list.data?.employees || [] }
							total={ list.data?.total || 0 }
							page={ page }
							perPage={ PER_PAGE }
							onPageChange={ ( next ) =>
								setView( { page: next } )
							}
							loading={ list.isPending }
							error={ list.error }
							onRetry={ () => list.refetch() }
							search={ {
								value: q,
								onChange: ( value ) =>
									setView( { q: value, page: '' } ),
								placeholder: __(
									'Name, number, phone or job',
									'radius-hotel-booking-pro'
								),
							} }
							sort={ sort }
							onSortChange={ ( next ) =>
								setView( {
									sort: next?.by || '',
									dir: 'desc' === next?.dir ? 'desc' : '',
									page: '',
								} )
							}
							toolbar={
								<div className="flex flex-wrap gap-2">
									<NameFilter
										value={ department }
										names={ departments }
										all={ __(
											'All departments',
											'radius-hotel-booking-pro'
										) }
										onChange={ ( value ) =>
											setView( {
												department: value,
												page: '',
											} )
										}
									/>
									<NameFilter
										value={ jobTitle }
										names={ jobTitles }
										all={ __(
											'All job titles',
											'radius-hotel-booking-pro'
										) }
										onChange={ ( value ) =>
											setView( {
												job_title: value,
												page: '',
											} )
										}
									/>
								</div>
							}
							rowActions={ rowActions }
							onRowClick={ ( employee ) =>
								navigate( `/staff/${ employee.id }` )
							}
							empty={ empty }
							caption={ __(
								'Staff',
								'radius-hotel-booking-pro'
							) }
						/>
					</div>
				</Panel>

				<ConfirmDialog
					open={ Boolean( toggling ) }
					onOpenChange={ ( open ) => ! open && setToggling( null ) }
					title={
						toggling
							? sprintf(
									/* translators: %s: employee name. */
									deactivating
										? __(
												'Deactivate %s?',
												'radius-hotel-booking-pro'
										  )
										: __(
												'Reactivate %s?',
												'radius-hotel-booking-pro'
										  ),
									toggling.employee.name
							  )
							: ''
					}
					description={
						deactivating
							? __(
									'They are signed out now and cannot sign in again. Their file, bookings and history are kept.',
									'radius-hotel-booking-pro'
							  )
							: __(
									'They can sign in again with their user name and password.',
									'radius-hotel-booking-pro'
							  )
					}
					confirmLabel={
						deactivating
							? __( 'Deactivate', 'radius-hotel-booking-pro' )
							: __( 'Reactivate', 'radius-hotel-booking-pro' )
					}
					destructive={ deactivating }
					onConfirm={ () =>
						setActive
							.mutateAsync( {
								id: toggling.employee.id,
								action: toggling.action,
							} )
							.then( () =>
								toast.success(
									deactivating
										? __(
												'Employee deactivated.',
												'radius-hotel-booking-pro'
										  )
										: __(
												'Employee reactivated.',
												'radius-hotel-booking-pro'
										  )
								)
							)
					}
				/>
			</div>
		</Suspense>
	);
}

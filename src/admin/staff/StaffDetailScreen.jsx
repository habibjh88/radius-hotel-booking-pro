/**
 * One employee (`#/staff/:id`, 12.3–12.5): a header with the name, number,
 * job and state (deactivate / reactivate with `staff.deactivate`) and the
 * file's tabs: Personal, Contact, Emergency contact, Employment, Notes,
 * History (while the activity log runs), Documents (with `staff.edit`) and
 * Account — plus the tabs add-ons add on `rtbp.staff.tabs` (the client
 * add-on's *Pay*, M15 T4b).
 */
import { lazy, Suspense, useState } from 'react';
import { applyFilters } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Power, PowerOff, UsersRound } from 'lucide-react';
import {
	Button,
	NotesPanel,
	ConfirmDialog,
	EmptyState,
	Panel,
	Skeleton,
	StatusBadge,
	Tabs,
	TabsContent,
	TabsList,
	TabsTrigger,
} from '@rtbp/ui';
import { access, format, toast } from '@rtbp/lib';

import AccountTab from './AccountTab';
import DocumentsTab from './DocumentsTab';
import EmploymentTab from './EmploymentTab';
import { ContactTab, EmergencyTab, PersonalTab } from './FileTabs';
import { useEmployee, useSetActive } from './api';

// The Pro activity log's per-record history (M14), loaded when opened.
const ActivityTimeline = lazy( () => import( '../activity/ActivityTimeline' ) );

/**
 * Whether the activity log feature runs (its routes exist on the server).
 *
 * @return {boolean} Active.
 */
const activityLogActive = () =>
	( window.rtbpPro?.features || [] ).some(
		( feature ) =>
			'activity_log' === feature.key && 'active' === feature.state
	);

/**
 * The tabs of the file, in order, after `rtbp.staff.tabs`.
 *
 * Add-ons add (or remove) tabs with
 * `addFilter( 'rtbp.staff.tabs', ns, ( tabs, { employee, can } ) => tabs )`:
 * a tab is `{ key, label, render() }`; use `findIndex` on a known key to place
 * it. An entry without a key, a label or a render function is dropped, and a
 * filter that does not return a list leaves Pro's tabs as they are.
 *
 * @param {Object}  employee Employee.
 * @param {Object}  can      `{ edit, contracts }`.
 * @return {Array<{key: string, label: string, render: Function}>} Tabs.
 */
const fileTabs = ( employee, can ) => {
	const own = baseTabs( employee, can );
	const filtered = applyFilters( 'rtbp.staff.tabs', own, { employee, can } );
	const tabs = ( Array.isArray( filtered ) ? filtered : own ).filter(
		( tab ) =>
			tab &&
			'string' === typeof tab.key &&
			tab.label &&
			'function' === typeof tab.render
	);
	// Keys must stay unique: the first wins.
	return tabs.filter(
		( tab, index ) =>
			tabs.findIndex( ( other ) => other.key === tab.key ) === index
	);
};

/**
 * Pro's own tabs.
 *
 * @param {Object}  employee Employee.
 * @param {Object}  can      `{ edit, contracts }`.
 * @return {Array<{key: string, label: string, render: Function}>} Tabs.
 */
const baseTabs = ( employee, can ) =>
	[
		{
			key: 'personal',
			label: __( 'Personal', 'radius-hotel-booking-pro' ),
			render: () => (
				<PersonalTab employee={ employee } canEdit={ can.edit } />
			),
		},
		{
			key: 'contact',
			label: __( 'Contact', 'radius-hotel-booking-pro' ),
			render: () => (
				<ContactTab employee={ employee } canEdit={ can.edit } />
			),
		},
		{
			key: 'emergency',
			label: __( 'Emergency contact', 'radius-hotel-booking-pro' ),
			render: () => (
				<EmergencyTab employee={ employee } canEdit={ can.edit } />
			),
		},
		{
			key: 'employment',
			label: __( 'Employment', 'radius-hotel-booking-pro' ),
			render: () => (
				<EmploymentTab
					employee={ employee }
					canEdit={ can.edit }
					canContracts={ can.contracts }
				/>
			),
		},
		// Notes (12.17): the free NotesPanel (M09); an older free plugin has none.
		NotesPanel && {
			key: 'notes',
			label: __( 'Notes', 'radius-hotel-booking-pro' ),
			render: () => <NotesPanel type="employee" id={ employee.id } />,
		},
		activityLogActive() && {
			key: 'history',
			label: __( 'History', 'radius-hotel-booking-pro' ),
			render: () => (
				<Suspense fallback={ null }>
					<ActivityTimeline type="employee" id={ employee.id } />
				</Suspense>
			),
		},
		can.edit && {
			key: 'documents',
			label: __( 'Documents', 'radius-hotel-booking-pro' ),
			render: () => <DocumentsTab employee={ employee } />,
		},
		{
			key: 'account',
			label: __( 'Account', 'radius-hotel-booking-pro' ),
			render: () => (
				<AccountTab employee={ employee } canEdit={ can.edit } />
			),
		},
	].filter( Boolean );

/**
 * @return {JSX.Element} Screen.
 */
export default function StaffDetailScreen() {
	const { id: param } = useParams();
	const id = Number( param ) || 0;
	const [ params, setParams ] = useSearchParams();
	const { data: employee, isPending, error, refetch } = useEmployee( id );
	const setActive = useSetActive();
	const [ confirming, setConfirming ] = useState( false );
	const level = ( key ) =>
		access?.useAccess ? access.useAccess( key ) : 'locked';
	const can = {
		edit: 'locked' !== level( 'staff.edit' ),
		toggle: 'locked' !== level( 'staff.deactivate' ),
		contracts: 'locked' !== level( 'staff.contracts' ),
	};

	const back = (
		<Link
			to="/staff"
			className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-heading"
		>
			<ArrowLeft className="h-4 w-4" aria-hidden="true" />
			{ __( 'All staff', 'radius-hotel-booking-pro' ) }
		</Link>
	);

	if ( isPending ) {
		return (
			<div className="mx-auto max-w-5xl space-y-4">
				{ back }
				<Skeleton className="h-28 w-full rounded-xl" />
				<Skeleton className="h-64 w-full rounded-xl" />
			</div>
		);
	}
	if ( error || ! employee ) {
		return (
			<div className="mx-auto max-w-5xl space-y-4">
				{ back }
				<Panel>
					<EmptyState
						icon={ UsersRound }
						title={
							404 === error?.status
								? __(
										'This employee does not exist',
										'radius-hotel-booking-pro'
								  )
								: __(
										'The employee could not be loaded',
										'radius-hotel-booking-pro'
								  )
						}
						description={
							404 === error?.status ? '' : error?.message
						}
						action={
							404 === error?.status ? null : (
								<Button
									variant="outline"
									onClick={ () => refetch() }
								>
									{ __(
										'Try again',
										'radius-hotel-booking-pro'
									) }
								</Button>
							)
						}
						className="border-0"
					/>
				</Panel>
			</div>
		);
	}

	const tabs = fileTabs( employee, can );
	const tab = tabs.some( ( t ) => t.key === params.get( 'tab' ) )
		? params.get( 'tab' )
		: tabs[ 0 ].key;
	const deactivating = employee.is_active;

	return (
		// Tabs, Select and ConfirmDialog parts load on demand (window.rtbp.ui).
		<Suspense fallback={ <Skeleton className="h-64 w-full rounded-xl" /> }>
			<div className="mx-auto max-w-5xl space-y-4">
				{ back }
				<Panel>
					<div className="flex flex-wrap items-start justify-between gap-3">
						<div className="min-w-0 space-y-1">
							<div className="flex flex-wrap items-center gap-2">
								<h2 className="m-0 text-xl font-semibold text-heading">
									{ employee.name || employee.user_login }
								</h2>
								<StatusBadge
									domain="active"
									value={
										employee.is_active
											? 'active'
											: 'inactive'
									}
								/>
							</div>
							<p className="m-0 text-sm text-muted-foreground">
								{ [
									employee.employee_no,
									employee.job_title,
									employee.department,
									employee.access_role &&
										sprintf(
											/* translators: %s: access role name */
											__(
												'Access: %s',
												'radius-hotel-booking-pro'
											),
											employee.access_role.name
										),
								]
									.filter( Boolean )
									.join( ' · ' ) }
							</p>
							{ ! employee.is_active &&
							employee.deactivated_at ? (
								<p className="m-0 text-sm text-muted-foreground">
									{ sprintf(
										/* translators: %s: date and time. */
										__(
											'Deactivated %s: cannot sign in.',
											'radius-hotel-booking-pro'
										),
										format.formatDateTime(
											employee.deactivated_at
										)
									) }
								</p>
							) : null }
						</div>
						{ can.toggle ? (
							<Button
								variant="outline"
								className={
									deactivating ? 'text-destructive' : ''
								}
								onClick={ () => setConfirming( true ) }
							>
								{ deactivating ? (
									<PowerOff
										className="h-4 w-4"
										aria-hidden="true"
									/>
								) : (
									<Power
										className="h-4 w-4"
										aria-hidden="true"
									/>
								) }
								{ deactivating
									? __(
											'Deactivate',
											'radius-hotel-booking-pro'
									  )
									: __(
											'Reactivate',
											'radius-hotel-booking-pro'
									  ) }
							</Button>
						) : null }
					</div>
				</Panel>

				<Panel>
					<Tabs
						value={ tab }
						onValueChange={ ( value ) =>
							setParams( { tab: value }, { replace: true } )
						}
					>
						<TabsList className="h-auto flex-wrap justify-start">
							{ tabs.map( ( t ) => (
								<TabsTrigger key={ t.key } value={ t.key }>
									{ t.label }
								</TabsTrigger>
							) ) }
						</TabsList>
						{ tabs.map( ( t ) => (
							<TabsContent
								key={ t.key }
								value={ t.key }
								className="pt-3"
							>
								{ t.render() }
							</TabsContent>
						) ) }
					</Tabs>
				</Panel>

				<ConfirmDialog
					open={ confirming }
					onOpenChange={ setConfirming }
					title={
						deactivating
							? sprintf(
									/* translators: %s: employee name. */
									__(
										'Deactivate %s?',
										'radius-hotel-booking-pro'
									),
									employee.name
							  )
							: sprintf(
									/* translators: %s: employee name. */
									__(
										'Reactivate %s?',
										'radius-hotel-booking-pro'
									),
									employee.name
							  )
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
								id: employee.id,
								action: deactivating
									? 'deactivate'
									: 'activate',
							} )
							.then( () => {
								refetch();
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
								);
							} )
					}
				/>
			</div>
		</Suspense>
	);
}

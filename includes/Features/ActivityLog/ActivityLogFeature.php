<?php
/**
 * Activity log (M14).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\ActivityLog;

use RadiusTheme\RadiusHotelBooking\Access\Access;
use RadiusTheme\RadiusHotelBookingPro\ActivityLog\ActivityArchiver;
use RadiusTheme\RadiusHotelBookingPro\ActivityLog\ActivityController;
use RadiusTheme\RadiusHotelBookingPro\ActivityLog\ActivityLogger;
use RadiusTheme\RadiusHotelBookingPro\ActivityLog\ActivitySettings;
use RadiusTheme\RadiusHotelBookingPro\ActivityLog\VerifyCommand;
use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `activity_log`: stores the free plugin's `rtbp_activity` events
 * (T2a), later the log screen, timeline and archiving (T3, T4). Switched off,
 * events still fire and nothing is stored; the table and its rows stay.
 */
final class ActivityLogFeature extends AbstractFeature {

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_activity',
			'rtbp_table_prefix',
			'RadiusTheme\\RadiusHotelBooking\\ActivityLog\\ActionCatalog',
			'RadiusTheme\\RadiusHotelBooking\\Access\\AccessRegistry',
			'RadiusTheme\\RadiusHotelBooking\\Settings\\SettingsSchema',
			'RadiusTheme\\RadiusHotelBooking\\Storage\\ProtectedFiles',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		ActivitySettings::register();

		// Store every event; late, so filters on the event have run.
		add_action( 'rtbp_activity', array( ActivityLogger::class, 'record' ), 20 );

		add_filter( 'rtbp_access_keys', array( $this, 'keys' ) );
		add_filter( 'rtbp_access_role_defaults', array( $this, 'manager_defaults' ) );
		add_filter( 'rtbp_activity_ip', array( $this, 'ip' ) );

		// The log settings can switch on the purge: guarded by activity.archive,
		// not a plain settings key, and audited here (free skips such sections).
		add_filter( 'rtbp_settings_access_key', array( $this, 'settings_key' ), 10, 2 );
		add_action( 'rtbp_settings_updated', array( $this, 'audit_settings' ), 10, 3 );

		// Archiving (T4): the daily check, the archive files and their downloads.
		add_filter( 'rtbp_scheduled_events', array( $this, 'schedule' ) );
		add_filter( 'rtbp_file_kinds', array( $this, 'file_kinds' ) );
		add_filter( 'rtbp_file_access', array( $this, 'file_access' ), 10, 3 );
		add_action( 'rtbp_file_download', array( $this, 'file_downloaded' ) );
		add_filter( 'rtbp_activity_actions', array( $this, 'actions' ) );

		// The log screen's API (T3a).
		add_action( 'rtbp_register_addon_routes', array( ActivityController::class, 'routes' ) );

		// `wp radius-hotel-booking log:verify` (T2b).
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'radius-hotel-booking log:verify', VerifyCommand::class );
		}
	}

	/**
	 * `rtbp_access_keys`: the log's page and archive keys.
	 *
	 * @param array $keys Key => definition.
	 * @return array
	 */
	public function keys( $keys ): array {
		$keys = (array) $keys;

		$keys['page.activity_log'] = array(
			'default' => 'locked',
			'group'   => 'pages',
			'label'   => __( 'Activity log', 'radius-hotel-booking-pro' ),
			'legacy'  => 'user-activity',
		);
		$keys['activity.archive']  = array(
			'default' => 'passcode',
			'group'   => 'reports',
			'label'   => __( 'Archive the activity log', 'radius-hotel-booking-pro' ),
		);
		return $keys;
	}

	/**
	 * `rtbp_access_role_defaults`: the built-in Manager (all open in the free
	 * plugin) confirms archiving, purging and log settings with a PIN.
	 *
	 * @param array $defaults Role => key => level.
	 * @return array
	 */
	public function manager_defaults( $defaults ): array {
		$defaults                                     = (array) $defaults;
		$defaults['rtbp_manager']['activity.archive'] = 'passcode';
		return $defaults;
	}

	/**
	 * `rtbp_scheduled_events`: check once a day whether an archive is due.
	 *
	 * @param array $events Hook => job.
	 * @return array
	 */
	public function schedule( $events ): array {
		$events                                 = (array) $events;
		$events[ ActivityArchiver::CRON_HOOK ] = array(
			'recurrence' => 'daily',
			'callback'   => array( ActivityArchiver::class, 'run_due' ),
			'lock_ttl'   => HOUR_IN_SECONDS,
		);
		return $events;
	}

	/**
	 * `rtbp_file_kinds`: the archive files.
	 *
	 * @param array $kinds Kind => config.
	 * @return array
	 */
	public function file_kinds( $kinds ): array {
		$kinds                                = (array) $kinds;
		$kinds[ ActivityArchiver::FILE_KIND ] = array( 'capability' => 'rtbp_view_dashboard' );
		return $kinds;
	}

	/**
	 * `rtbp_file_access`: an archive file needs `activity.archive` like the
	 * archive list: open, or passcode with a valid grant in the
	 * `X-RTBP-Passcode` header (the screen downloads with fetch and sends it).
	 *
	 * @param bool   $allowed Decision so far.
	 * @param object $file    StoredFile.
	 * @param int    $user_id User.
	 * @return bool
	 */
	public function file_access( $allowed, $file, $user_id ): bool {
		if ( ActivityArchiver::FILE_KIND !== (string) ( $file->kind ?? '' ) ) {
			return (bool) $allowed;
		}
		// Only the current user's own request: Access::can() reads its PIN grant.
		return (bool) $allowed && get_current_user_id() === (int) $user_id && Access::can( 'activity.archive' );
	}

	/**
	 * `rtbp_file_download`: record archive downloads.
	 *
	 * @param object $file StoredFile.
	 * @return void
	 */
	public function file_downloaded( $file ): void {
		if ( ActivityArchiver::FILE_KIND !== (string) ( $file->kind ?? '' ) ) {
			return;
		}
		rtbp_activity(
			'activity.downloaded',
			array(
				'type'  => 'file',
				'id'    => (string) $file->token,
				'label' => (string) $file->original_name,
			)
		);
	}

	/**
	 * `rtbp_activity_actions`: the archive download action.
	 *
	 * @param array $actions Key => definition.
	 * @return array
	 */
	public function actions( $actions ): array {
		$actions                        = (array) $actions;
		$actions['activity.downloaded'] = array(
			'kind'  => 'system',
			'label' => __( 'Downloaded a log archive', 'radius-hotel-booking-pro' ),
		);
		return $actions;
	}

	/**
	 * `rtbp_settings_access_key`: `pro_activity` needs `activity.archive`.
	 *
	 * @param string $key     Access key.
	 * @param string $section Section.
	 * @return string
	 */
	public function settings_key( $key, $section ): string {
		return ActivitySettings::SECTION === $section ? 'activity.archive' : (string) $key;
	}

	/**
	 * `rtbp_settings_updated`: record changes to the log settings.
	 *
	 * @param string $section Section.
	 * @param mixed  $before  Values before.
	 * @param mixed  $after   Values after.
	 * @return void
	 */
	public function audit_settings( $section, $before, $after ): void {
		if ( ActivitySettings::SECTION !== $section || $before == $after ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same values, any key order.
			return;
		}
		rtbp_activity(
			'settings.updated',
			array(
				'type'  => 'settings',
				'id'    => ActivitySettings::SECTION,
				'label' => __( 'Activity log', 'radius-hotel-booking-pro' ),
			),
			array(
				'before' => (array) $before,
				'after'  => (array) $after,
			)
		);
	}

	/**
	 * `rtbp_activity_ip`: behind a trusted proxy, the client address from
	 * `X-Forwarded-For`. No proxy is trusted unless the site lists it with
	 * the `rtbp_pro_trusted_proxies` filter.
	 *
	 * @param string $ip The TCP peer.
	 * @return string
	 */
	public function ip( $ip ): string {
		$ip = (string) $ip;

		/**
		 * Filters the proxy addresses whose `X-Forwarded-For` is trusted.
		 *
		 * @param string[] $proxies IP addresses.
		 */
		$proxies = (array) apply_filters( 'rtbp_pro_trusted_proxies', array() );
		if ( '' === $ip || ! in_array( $ip, $proxies, true ) || empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return $ip;
		}

		// The right-most address that is not itself a trusted proxy.
		$chain = array_reverse( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
		foreach ( $chain as $hop ) {
			if ( false !== filter_var( $hop, FILTER_VALIDATE_IP ) && ! in_array( $hop, $proxies, true ) ) {
				return $hop;
			}
		}
		return $ip;
	}
}

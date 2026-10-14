<?php
/**
 * Payroll data from the Residence TATA add-on.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

defined( 'ABSPATH' ) || exit;

/**
 * Payroll was first built in the Residence TATA add-on, with `tata_*`
 * tables and the `tata_payroll` settings. Once, when Pro's payroll first
 * boots, the old tables replace Pro's — all of them or none:
 *
 * - under a database lock, so two first requests cannot both move tables;
 * - only when every Pro payroll table is still empty (a half-filled Pro
 *   payroll is never mixed with old rows);
 * - each swap is one atomic `RENAME TABLE pro_x TO pro_x_old, tata_x TO
 *   pro_x`: nothing is dropped until the set-aside Pro table is checked empty;
 * - the done flag is set only when every step worked; otherwise a site check
 *   tells the administrator, and the next request tries again.
 *
 * The vacation quota moves to Settings → Payroll. A site that never ran the
 * add-on has nothing to move. While an older add-on that still runs its own
 * payroll is active, nothing is moved (it still uses those tables).
 */
final class PayrollMigration {

	/**
	 * Done marker.
	 */
	public const OPTION = 'rtbp_pro_payroll_migrated';

	/**
	 * Why the last attempt failed ('' / missing = it did not).
	 */
	public const FAILED = 'rtbp_pro_payroll_migration_failed';

	/**
	 * The tables, without the prefix: old => new.
	 */
	private const TABLES = array(
		'tata_pay_schedules'       => 'pro_pay_schedules',
		'tata_work_shifts'         => 'pro_work_shifts',
		'tata_work_shift_days'     => 'pro_work_shift_days',
		'tata_employee_pay'        => 'pro_employee_pay',
		'tata_employee_pay_items'  => 'pro_employee_pay_items',
		'tata_employee_deductions' => 'pro_employee_deductions',
		'tata_payroll_runs'        => 'pro_payroll_runs',
		'tata_payroll_lines'       => 'pro_payroll_lines',
		'tata_payroll_ledger'      => 'pro_payroll_ledger',
		'tata_payroll_statutory'   => 'pro_payroll_statutory',
		'tata_statutory_payments'  => 'pro_statutory_payments',
		'tata_time_off'            => 'pro_time_off',
	);

	/**
	 * Move the data once.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( get_option( self::OPTION ) || ! function_exists( 'rtbp_table_prefix' ) || get_transient( self::FAILED ) ) {
			return;
		}
		// An older Residence TATA add-on still runs payroll on these tables: leave them.
		if ( class_exists( '\\RadiusTheme\\RadiusHotelBookingTata\\Payroll\\PayrollRunService' ) ) {
			return;
		}
		// Wait until Pro's tables exist (its installer runs on the same request).
		if ( class_exists( '\\RadiusTheme\\RadiusHotelBookingPro\\Databases\\Installer' ) && ! \RadiusTheme\RadiusHotelBookingPro\Databases\Installer::all_tables_exist() ) {
			return;
		}

		global $wpdb;
		$lock = 'rtbp_pro_payroll_migrate';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named lock, not data.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
			return; // Another request is moving them.
		}
		try {
			// Another request may have finished while this one waited.
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( get_option( self::OPTION ) ) {
				return;
			}
			$error = self::move();
			if ( '' === $error ) {
				self::settings();
				update_option( self::OPTION, 1, false );
				delete_option( self::FAILED );
			} else {
				update_option( self::FAILED, $error, false );
				// Not on every request: once an hour until it works.
				set_transient( self::FAILED, 1, HOUR_IN_SECONDS );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
		// phpcs:enable
	}

	/**
	 * `rtbp_site_checks`: a move that could not finish.
	 *
	 * @param array $checks Failing checks so far.
	 * @return array
	 */
	public static function checks( $checks ): array {
		$checks = (array) $checks;
		$error  = (string) get_option( self::FAILED, '' );
		if ( '' !== $error ) {
			$checks[] = array(
				'id'           => 'pro_payroll_migration',
				/* translators: %s: why, e.g. a database error. */
				'message'      => sprintf( __( 'Payroll data from the Residence TATA add-on could not be moved to Pro yet (%s). Nothing was lost; it is tried again every hour.', 'radius-hotel-booking-pro' ), $error ),
				'action_label' => '',
				'action_url'   => '',
			);
		}
		return $checks;
	}

	/**
	 * Swap the tables, all or none.
	 *
	 * @return string '' when done (or nothing to move), else why not.
	 */
	private static function move(): string {
		global $wpdb;
		$prefix = rtbp_table_prefix();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- a one-time table move.
		$exists = static fn( string $table ): bool => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		$rows   = static fn( string $table ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

		$old_rows = 0;
		foreach ( array_keys( self::TABLES ) as $old ) {
			if ( $exists( $prefix . $old ) ) {
				$old_rows += $rows( $prefix . $old );
			}
		}
		if ( 0 === $old_rows ) {
			return ''; // Nothing to move.
		}
		foreach ( self::TABLES as $new ) {
			if ( $exists( $prefix . $new ) && $rows( $prefix . $new ) > 0 ) {
				// Pro already holds payroll data: never mix the two.
				return __( 'Pro\'s payroll already has data, so the old payroll was left where it is', 'radius-hotel-booking-pro' );
			}
		}

		$moved = array();
		foreach ( self::TABLES as $old => $new ) {
			$from = $prefix . $old;
			$to   = $prefix . $new;
			if ( ! $exists( $from ) ) {
				continue; // That old table never existed: Pro's empty one stays.
			}
			$had_new = $exists( $to );
			$ok      = $had_new
				? $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $to, $to . '_old', $from, $to ) )
				: $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $from, $to ) );
			if ( false === $ok ) {
				$error = (string) $wpdb->last_error;
				// Put back what was already swapped: the two plugins never see half a move.
				foreach ( array_reverse( $moved ) as list( $back_from, $back_to, $back_had_new ) ) {
					if ( $back_had_new ) {
						$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $back_to, $back_from, $back_to . '_old', $back_to ) );
					} else {
						$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $back_to, $back_from ) );
					}
				}
				return '' !== $error ? $error : __( 'a table could not be renamed', 'radius-hotel-booking-pro' );
			}
			$moved[] = array( $from, $to, $had_new );
		}

		// The empty Pro tables set aside: dropped only when still empty.
		foreach ( self::TABLES as $new ) {
			$backup = $prefix . $new . '_old';
			if ( $exists( $backup ) && 0 === $rows( $backup ) ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $backup ) );
			}
		}
		// phpcs:enable
		return '';
	}

	/**
	 * The vacation quota, to Settings → Payroll (unless already set there).
	 *
	 * @return void
	 */
	private static function settings(): void {
		$old = get_option( 'rtbp_tata_payroll_settings', null );
		if ( ! is_array( $old ) || ! isset( $old['vacationDaysYear'] ) ) {
			return;
		}
		$name = 'rtbp_' . PayrollSettings::SECTION . '_settings';
		$new  = get_option( $name, array() );
		$new  = is_array( $new ) ? $new : array();
		if ( ! isset( $new['vacationDaysYear'] ) ) {
			$new['vacationDaysYear'] = (int) $old['vacationDaysYear'];
			update_option( $name, $new );
		}
	}
}

<?php
/**
 * Work shift data access (M15 T3b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_work_shifts` and `pro_work_shift_days`.
 */
class WorkShiftRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = WorkShift::class;

	/**
	 * The shifts table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_work_shifts';
	}

	/**
	 * The days table.
	 *
	 * @return string
	 */
	public static function daysTable(): string {
		return rtbp_table_prefix() . 'pro_work_shift_days';
	}

	/**
	 * Every shift, by name.
	 *
	 * @return WorkShift[]
	 */
	public function byName(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a handful of rows, read live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name, id', self::table() ), ARRAY_A );
		return array_map( array( WorkShift::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Whether another shift has this name (the collation ignores case).
	 *
	 * @param string $name   Name.
	 * @param int    $except Shift being edited.
	 * @return bool
	 */
	public function nameTaken( string $name, int $except = 0 ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checked right before the write.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE name = %s AND id <> %d LIMIT 1', self::table(), $name, $except ) );
	}

	/**
	 * The days of some shifts: shift id => weekday => `{ is_day_off, start_time, end_time }` (`H:i`).
	 *
	 * @param int[] $shift_ids Shift ids.
	 * @return array<int, array<int, array>>
	 */
	public function days( array $shift_ids ): array {
		$shift_ids = array_values( array_filter( array_map( 'intval', $shift_ids ) ) );
		if ( ! $shift_ids ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one query for every shift; placeholders built from the count.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE shift_id IN (' . implode( ',', array_fill( 0, count( $shift_ids ), '%d' ) ) . ') ORDER BY weekday', array_merge( array( self::daysTable() ), $shift_ids ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['shift_id'] ][ (int) $row['weekday'] ] = array(
				'is_day_off' => (bool) $row['is_day_off'],
				'start_time' => null !== $row['start_time'] ? substr( (string) $row['start_time'], 0, 5 ) : null,
				'end_time'   => null !== $row['end_time'] ? substr( (string) $row['end_time'], 0, 5 ) : null,
			);
		}
		return $out;
	}

	/**
	 * Replace a shift's seven days.
	 *
	 * @param int     $shift_id Shift id.
	 * @param array[] $days     Weekday => day.
	 * @return void
	 */
	public function replaceDays( int $shift_id, array $days ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the shift's own rows, rewritten in its transaction.
		$wpdb->delete( self::daysTable(), array( 'shift_id' => $shift_id ), array( '%d' ) );
		foreach ( $days as $weekday => $day ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plain insert.
			$wpdb->insert(
				self::daysTable(),
				array(
					'shift_id'   => $shift_id,
					'weekday'    => (int) $weekday,
					'is_day_off' => $day['is_day_off'] ? 1 : 0,
					'start_time' => $day['is_day_off'] ? null : $day['start_time'],
					'end_time'   => $day['is_day_off'] ? null : $day['end_time'],
				),
				array( '%d', '%d', '%d', '%s', '%s' )
			);
		}
	}
}

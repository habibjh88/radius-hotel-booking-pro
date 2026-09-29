<?php
/**
 * Access role model.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * A named access role (table `pro_access_roles`).
 *
 * @property int    $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property array  $levels         Key (or `*`) => open | passcode | locked.
 * @property array  $profile_fields Field => allow | restrict (T6).
 * @property bool   $is_template
 */
class AccessRole extends BaseModel {

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_access_roles';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'slug',
		'name',
		'description',
		'levels',
		'profile_fields',
		'is_template',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id'             => 'int',
		'levels'         => 'json',
		'profile_fields' => 'json',
		'is_template'    => 'bool',
	);
}

<?php
/**
 * Employee document model.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * A document in an employee file (table `pro_employee_documents`, M12
 * 12.16). The file itself is a protected file (`employee_document` kind);
 * this row keeps its token, title and category.
 *
 * @property int      $id
 * @property int      $employee_id
 * @property string   $title
 * @property string   $category
 * @property string   $file_token
 * @property string   $mime
 * @property int      $size
 * @property int|null $uploaded_by
 */
class EmployeeDocument extends BaseModel {

	/**
	 * Protected file kind.
	 */
	const FILE_KIND = 'employee_document';

	/**
	 * Categories.
	 */
	const CATEGORIES = array( 'contract', 'identity', 'diploma', 'medical', 'other' );

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_employee_documents';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'employee_id',
		'title',
		'category',
		'file_token',
		'mime',
		'size',
		'uploaded_by',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id'          => 'int',
		'employee_id' => 'int',
		'size'        => 'int',
		'uploaded_by' => 'int',
	);
}

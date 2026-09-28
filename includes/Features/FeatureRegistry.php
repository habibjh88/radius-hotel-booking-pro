<?php
/**
 * The catalogue of Pro features.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for what Pro offers and how each feature can be
 * switched on or off.
 *
 * Each entry (definitions()):
 * - `class`    Feature class (extends AbstractFeature). If the class does not
 *              exist yet the feature is listed as "in development" and cannot
 *              boot — its module has not been built.
 * - `module`   The docs module that builds it (free repo, docs/modules/).
 * - `requires` Other feature keys that must be enabled for this one to run.
 * - `default`  Enabled on a fresh install.
 */
final class FeatureRegistry {

	/**
	 * Every Pro feature, keyed by its stable key. Keys are stored in the
	 * `rtbp_pro_features_settings` option — never rename one once shipped.
	 *
	 * Structural data only — no translated strings, so it is safe to read during
	 * boot (before `init`), when translations must not load yet.
	 *
	 * @return array<string,array{group:string,module:string,class:string,requires:string[],default:bool}>
	 */
	public static function definitions(): array {
		$features = array(
			'activity_log'       => array(
				'group'       => 'operations',
				'module'      => 'M14',
				'class'       => __NAMESPACE__ . '\\ActivityLog\\ActivityLogFeature',
				'requires'    => array(),
				'default'     => true,
			),
			'advanced_access'    => array(
				'group'       => 'operations',
				'module'      => 'M13',
				'class'       => __NAMESPACE__ . '\\Access\\AdvancedAccessFeature',
				'requires'    => array(),
				'default'     => true,
			),
			'pricing_rules'      => array(
				'group'       => 'pricing',
				'module'      => 'M07',
				'class'       => __NAMESPACE__ . '\\Pricing\\PricingRulesFeature',
				'requires'    => array(),
				'default'     => true,
			),
			'ical_sync'          => array(
				'group'       => 'pricing',
				'module'      => 'M08',
				'class'       => __NAMESPACE__ . '\\Ical\\IcalSyncFeature',
				'requires'    => array(),
				'default'     => true,
			),
			'pdf_documents'      => array(
				'group'       => 'payments',
				'module'      => 'M05',
				'class'       => __NAMESPACE__ . '\\Documents\\PdfDocumentsFeature',
				'requires'    => array(),
				'default'     => true,
			),
			'auto_release'       => array(
				'group'       => 'payments',
				'module'      => 'M05',
				'class'       => __NAMESPACE__ . '\\Payments\\AutoReleaseFeature',
				'requires'    => array(),
				'default'     => false,
			),
			'advanced_reports'   => array(
				'group'       => 'insights',
				'module'      => 'M10',
				'class'       => __NAMESPACE__ . '\\Reports\\AdvancedReportsFeature',
				'requires'    => array(),
				'default'     => true,
			),
			'advanced_exports'   => array(
				'group'       => 'insights',
				'module'      => 'M11',
				'class'       => __NAMESPACE__ . '\\Export\\AdvancedExportsFeature',
				'requires'    => array(),
				'default'     => true,
			),
			'staff_records'      => array(
				'group'       => 'staff',
				'module'      => 'M12',
				'class'       => __NAMESPACE__ . '\\Staff\\StaffRecordsFeature',
				'requires'    => array(),
				'default'     => true,
			),
			'staff_self_service' => array(
				'group'       => 'staff',
				'module'      => 'M16',
				'class'       => __NAMESPACE__ . '\\SelfService\\SelfServiceFeature',
				'requires'    => array( 'staff_records' ),
				'default'     => true,
			),
		);

		/**
		 * Filters the Pro feature catalogue. A client add-on may register
		 * features of its own here, with the same shape.
		 *
		 * @param array $features Feature key => definition.
		 */
		return (array) apply_filters( 'rtbp_pro_features', $features );
	}

	/**
	 * Definitions plus translated label and description, for display. Call
	 * only on or after `init`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$texts = array(
			'activity_log' => array( __( 'Activity log', 'radius-hotel-booking-pro' ), __( 'Record who did what, with before-and-after values, tamper evidence and automatic archiving.', 'radius-hotel-booking-pro' ) ),
			'advanced_access' => array( __( 'Advanced staff access', 'radius-hotel-booking-pro' ), __( 'Passcode-protected actions, personal PINs, custom access roles, per-person overrides and profile-field control.', 'radius-hotel-booking-pro' ) ),
			'pricing_rules' => array( __( 'Advanced pricing rules', 'radius-hotel-booking-pro' ), __( 'Seasonal pricing, occupancy pricing, early-bird and last-minute discounts.', 'radius-hotel-booking-pro' ) ),
			'ical_sync' => array( __( 'iCal calendar sync', 'radius-hotel-booking-pro' ), __( 'Import and export room availability with Booking.com, Airbnb and similar channels.', 'radius-hotel-booking-pro' ) ),
			'pdf_documents' => array( __( 'PDF invoices and receipts', 'radius-hotel-booking-pro' ), __( 'Generate invoices and receipts as PDF files and attach them to guest e-mails.', 'radius-hotel-booking-pro' ) ),
			'auto_release' => array( __( 'Automatic release of unpaid bookings', 'radius-hotel-booking-pro' ), __( 'Free the room automatically when a booking passes its payment deadline.', 'radius-hotel-booking-pro' ) ),
			'advanced_reports' => array( __( 'Advanced reports', 'radius-hotel-booking-pro' ), __( 'Occupancy rate, revenue by room type, rate plan and staff member, and Excel export.', 'radius-hotel-booking-pro' ) ),
			'advanced_exports' => array( __( 'Advanced exports and archiving', 'radius-hotel-booking-pro' ), __( 'Excel exports, archive-and-remove of old bookings, and scheduled exports.', 'radius-hotel-booking-pro' ) ),
			'staff_records' => array( __( 'Staff records', 'radius-hotel-booking-pro' ), __( 'Full employee files: contracts, emergency contacts, documents, notes and history.', 'radius-hotel-booking-pro' ) ),
			'staff_self_service' => array( __( 'Staff self-service', 'radius-hotel-booking-pro' ), __( 'Staff manage their own profile, password and PIN within the fields you allow.', 'radius-hotel-booking-pro' ) ),
		);

		/**
		 * Filters the display text of Pro features (label, description), e.g.
		 * for features a client add-on registers.
		 *
		 * @param array $texts Feature key => array( label, description ).
		 */
		$texts = (array) apply_filters( 'rtbp_pro_feature_texts', $texts );

		$out = array();
		foreach ( self::definitions() as $key => $definition ) {
			$out[ $key ] = $definition + array(
				'label'       => $texts[ $key ][0] ?? $key,
				'description' => $texts[ $key ][1] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Group labels, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function groups(): array {
		return array(
			'operations' => __( 'Operations and security', 'radius-hotel-booking-pro' ),
			'pricing'    => __( 'Pricing and availability', 'radius-hotel-booking-pro' ),
			'payments'   => __( 'Payments and documents', 'radius-hotel-booking-pro' ),
			'insights'   => __( 'Reports and exports', 'radius-hotel-booking-pro' ),
			'staff'      => __( 'Staff', 'radius-hotel-booking-pro' ),
		);
	}

	/**
	 * Default on/off state per feature — the settings section's defaults.
	 *
	 * @return array<string,bool>
	 */
	public static function defaults(): array {
		return array_map(
			static fn( $feature ) => (bool) ( $feature['default'] ?? false ),
			self::definitions()
		);
	}

	/**
	 * Whether the feature's code exists yet.
	 *
	 * @param string $key Feature key.
	 * @return bool
	 */
	public static function is_built( string $key ): bool {
		$class = self::definitions()[ $key ]['class'] ?? '';
		return '' !== $class && class_exists( $class ) && is_subclass_of( $class, AbstractFeature::class );
	}
}

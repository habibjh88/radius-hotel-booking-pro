<?php
/**
 * Pro plugin bootstrap.
 *
 * @package RadiusTheme\RadiusHotelBookingPro
 */

namespace RadiusTheme\RadiusHotelBookingPro;

use RadiusTheme\RadiusHotelBookingPro\Assets\Assets;
use RadiusTheme\RadiusHotelBookingPro\Features\FeatureManager;
use RadiusTheme\RadiusHotelBookingPro\Settings\FeatureSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Boots Pro only once the free plugin has booted.
 *
 * The free plugin fires `rtbp_loaded` at the end of its own bootstrap, so
 * hooking there guarantees every free class, filter and container binding is
 * available. When the free plugin is missing or too old, Pro shows a notice and
 * registers nothing else — it never fatals and never half-loads.
 */
final class Plugin {

	/**
	 * Lowest free plugin version Pro supports.
	 *
	 * The seams Pro uses (rtbp_admin_enqueue_scripts, window.rtbp,
	 * rtbp.settings.sections) ship in free 1.0.0.1, which is still unreleased,
	 * so the gate stays at 1.0.0 and missing seams are detected at the point
	 * of use instead. Raise this when free 1.0.0.1 is released.
	 */
	const MIN_FREE_VERSION = '1.0.0';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Feature manager, available once booted.
	 *
	 * @var FeatureManager|null
	 */
	private ?FeatureManager $features = null;

	/**
	 * Get the instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the dependency check and the boot hook.
	 */
	private function __construct() {
		add_action( 'rtbp_loaded', array( $this, 'boot' ) );
		add_action( 'plugins_loaded', array( $this, 'check_dependency' ), 99 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Show a notice when the free plugin never booted or is too old.
	 *
	 * @return void
	 */
	public function check_dependency(): void {
		if ( self::free_is_compatible() && did_action( 'rtbp_loaded' ) ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %s: minimum free plugin version. */
							__( 'Radius Hotel Booking Pro needs Radius Hotel Booking %s or later to be installed and active.', 'radius-hotel-booking-pro' ),
							self::MIN_FREE_VERSION
						)
					)
				);
			}
		);
	}

	/**
	 * Whether the active free plugin is present and recent enough.
	 *
	 * @return bool
	 */
	public static function free_is_compatible(): bool {
		return defined( 'RADIUS_HOTEL_BOOKING_VERSION' )
			&& version_compare( RADIUS_HOTEL_BOOKING_VERSION, self::MIN_FREE_VERSION, '>=' );
	}

	/**
	 * Boot Pro. Runs on the free plugin's `rtbp_loaded`.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( null !== $this->features || ! self::free_is_compatible() ) {
			return;
		}

		( new FeatureSettings() )->register();
		( new Assets() )->register();

		$this->features = new FeatureManager();
		$this->features->boot();

		/**
		 * Fires once Pro and its enabled features have booted.
		 *
		 * @param Plugin $plugin The Pro plugin.
		 */
		do_action( 'rtbp_pro_loaded', $this );
	}

	/**
	 * The feature manager (null before boot or without the free plugin).
	 *
	 * @return FeatureManager|null
	 */
	public function features(): ?FeatureManager {
		return $this->features;
	}

	/**
	 * Load the Pro text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'radius-hotel-booking-pro', false, dirname( plugin_basename( RADIUS_HOTEL_BOOKING_PRO_FILE ) ) . '/languages' );
	}
}

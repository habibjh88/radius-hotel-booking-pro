<?php
/**
 * PHP-Scoper configuration (ADR-005): the third-party libraries in
 * scoper/vendor are copied to ../vendor-prefixed with their namespaces moved
 * under RadiusTheme\RadiusHotelBookingPro\Vendor, so another plugin's copy of
 * dompdf can never clash with ours. Run `composer scope` from the plugin root.
 *
 * @package RadiusTheme\RadiusHotelBookingPro
 */

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix'             => 'RadiusTheme\\RadiusHotelBookingPro\\Vendor',
	'finders'            => array(
		// Every file of the libraries: dompdf also needs its fonts and resources.
		Finder::create()->files()->ignoreVCS( true )->in( __DIR__ . '/vendor' )->exclude( array( 'bin' ) )->notName( '/\.(md|dist|xml)$/' ),
		Finder::create()->append( array( __DIR__ . '/composer.json', __DIR__ . '/composer.lock' ) ),
	),

	/*
	 * PHP-Scoper guesses which strings are class names. Fix its two kinds of
	 * mistake (found by grepping the output; re-check after a library update):
	 * 1. Class names built at run time ("Dompdf\\FrameDecorator\\{$name}",
	 *    '\Dompdf\Positioner\\' . $type) were missed: prefix them.
	 * 2. Strings that only look like names were prefixed: global constants and
	 *    classes (defined( 'IMAGETYPE_SVG' ), 'Imagick::…', "DEBUGPNG"),
	 *    php-font-lib's relative names ("TrueType\\File", completed with
	 *    "FontLib\\" later) and sabberworm's CSS escape '\r\n'. Un-prefix them.
	 */
	'patchers'           => array(
		static function ( string $file_path, string $prefix, string $contents ): string {
			if ( '.php' !== substr( $file_path, -4 ) ) {
				return $contents;
			}
			$escaped = str_replace( '\\', '\\\\', $prefix );

			// 1. Missed.
			foreach ( array( 'Dompdf', 'FontLib' ) as $namespace ) {
				$contents = str_replace( '"' . $namespace . '\\\\{', '"' . $escaped . '\\\\' . $namespace . '\\\\{', $contents );
				$contents = str_replace( '"' . $namespace . '\\\\Table\\\\', '"' . $escaped . '\\\\' . $namespace . '\\\\Table\\\\', $contents );
				$contents = str_replace( '"' . $namespace . '\\\\FrameDecorator\\\\', '"' . $escaped . '\\\\' . $namespace . '\\\\FrameDecorator\\\\', $contents );
				$contents = str_replace( '"' . $namespace . '\\\\FrameReflower\\\\', '"' . $escaped . '\\\\' . $namespace . '\\\\FrameReflower\\\\', $contents );
				$contents = str_replace( "'\\" . $namespace . '\\', "'\\" . $prefix . '\\' . $namespace . '\\', $contents );
			}

			// 2. Wrongly prefixed.
			foreach ( array( 'IMAGETYPE_SVG', 'Imagick::', 'DEBUGPNG', 'TrueType\\', 'OpenType\\', 'WOFF\\', 'EOT\\' ) as $name ) {
				$contents = str_replace( "'" . $prefix . '\\' . $name, "'" . $name, $contents );
				$contents = str_replace( '"' . $escaped . '\\\\' . str_replace( '\\', '\\\\', $name ), '"' . str_replace( '\\', '\\\\', $name ), $contents );
			}
			$contents = str_replace( "'" . $prefix . '\\r\\n\'', "'\\r\\n'", $contents );

			// 3. php-font-lib reads its font type from the class name's 2nd part
			// (FontLib\TrueType\File); with the prefix it is the 2nd-last.
			return str_replace( 'return $class_parts[1];', 'return $class_parts[\count($class_parts) - 2];', $contents );
		},
	),
	// No WordPress or PHP symbols live in these libraries.
	'exclude-namespaces' => array(),
	'expose-global-functions' => false,
	'expose-global-classes'   => false,
	'expose-global-constants' => false,
);

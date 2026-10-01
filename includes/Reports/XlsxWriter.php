<?php
/**
 * Excel (XLSX) export format (M10 T7, 10.14).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Reports
 */

namespace RadiusTheme\RadiusHotelBookingPro\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * The `xlsx` format of the free `rtbp_export_formats` registry: the same
 * tables as the CSV, **one worksheet per table**, built as Office Open XML
 * with PHP's `ZipArchive` (no spreadsheet library to ship or scope).
 *
 * - The first row of each sheet is the table's columns, in bold, frozen.
 * - Numbers are numeric cells (sums and filters work); text is an inline
 *   string — never a formula, so a guest named `=…` stays text.
 * - Sheet names come from the table titles, cut to Excel's 31 characters,
 *   without `[ ] : * ? / \`, and made unique.
 * - Column widths follow the longest value (8–60 characters).
 *
 * Registered only when `ZipArchive` exists (the PHP `zip` extension).
 */
class XlsxWriter {

	/**
	 * Whether this server can write XLSX.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Write tables as an XLSX file.
	 *
	 * @param array $tables `[ { title, columns, rows } ]`.
	 * @return string File bytes.
	 * @throws \RuntimeException When the archive cannot be written.
	 */
	public static function write( array $tables ): string {
		$tables = array_values( $tables );
		if ( ! $tables ) {
			$tables = array(
				array(
					'title'   => 'Sheet1',
					'columns' => array(),
					'rows'    => array(),
				),
			);
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = wp_tempnam( 'rtbp-xlsx' );
		try {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				throw new \RuntimeException( 'The spreadsheet could not be created.' );
			}

			$names  = array();
			$sheets = array();
			foreach ( $tables as $index => $table ) {
				$names[]  = self::sheetName( (string) ( $table['title'] ?? '' ), $index + 1, $names );
				$sheets[] = self::sheet( (array) ( $table['columns'] ?? array() ), (array) ( $table['rows'] ?? array() ) );
			}

			foreach ( self::packageParts( $names ) as $part => $xml ) {
				$zip->addFromString( $part, $xml );
			}
			foreach ( $sheets as $index => $xml ) {
				$zip->addFromString( 'xl/worksheets/sheet' . ( $index + 1 ) . '.xml', $xml );
			}
			$zip->close();

			return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own temporary file.
		} finally {
			// Never left behind, whatever happened above.
			wp_delete_file( $path );
		}
	}

	/**
	 * One worksheet.
	 *
	 * @param array $columns Header labels.
	 * @param array $rows    Rows of values.
	 * @return string XML.
	 */
	private static function sheet( array $columns, array $rows ): string {
		$widths = array();
		$data   = '';
		$all    = array_merge( $columns ? array( $columns ) : array(), $rows );
		foreach ( array_values( $all ) as $r => $row ) {
			$data .= self::rowXml( $r + 1, (array) $row, $columns && 0 === $r, $widths );
		}
		return self::sheetOpen( $widths, (bool) $columns ) . $data . self::SHEET_CLOSE;
	}

	/**
	 * The end of a worksheet.
	 */
	public const SHEET_CLOSE = '</sheetData></worksheet>';

	/**
	 * One row's XML; widens `$widths` to the longest value per column.
	 *
	 * @param int   $number  Row number (1-based).
	 * @param array $values  Values in column order.
	 * @param bool  $bold    The header row.
	 * @param array $widths  Column index => longest value (updated).
	 * @return string
	 */
	public static function rowXml( int $number, array $values, bool $bold, array &$widths ): string {
		$cells = '';
		$style = $bold ? ' s="1"' : '';
		foreach ( array_values( $values ) as $c => $value ) {
			$ref  = self::column( $c ) . $number;
			$text = (string) $value;
			$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

			$widths[ $c ] = max( $widths[ $c ] ?? 0, $len );
			if ( is_int( $value ) || is_float( $value ) ) {
				$cells .= '<c r="' . $ref . '"' . $style . '><v>' . self::number( $value ) . '</v></c>';
			} elseif ( '' !== $text ) {
				$cells .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::escape( $text ) . '</t></is></c>';
			}
		}
		return '<row r="' . $number . '">' . $cells . '</row>';
	}

	/**
	 * The start of a worksheet, up to `<sheetData>`: the frozen header row and
	 * the column widths (8–60 characters).
	 *
	 * @param array $widths Column index => longest value.
	 * @param bool  $header The first row is a header (frozen).
	 * @return string
	 */
	public static function sheetOpen( array $widths, bool $header ): string {
		$cols = '';
		foreach ( $widths as $c => $width ) {
			$cols .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . max( 8, min( 60, $width + 2 ) ) . '" customWidth="1"/>';
		}
		$freeze = $header ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. $freeze
			. ( '' !== $cols ? '<cols>' . $cols . '</cols>' : '' )
			. '<sheetData>';
	}

	/**
	 * Every part of the package but the worksheets.
	 *
	 * @param string[] $names Sheet names, in order.
	 * @return array<string, string> Part path => XML.
	 */
	public static function packageParts( array $names ): array {
		return array(
			'[Content_Types].xml'        => self::contentTypes( count( $names ) ),
			'_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
			'xl/workbook.xml'            => self::workbook( $names ),
			'xl/_rels/workbook.xml.rels' => self::workbookRels( count( $names ) ),
			'xl/styles.xml'              => self::styles(),
		);
	}

	/**
	 * The `xlsx` entry of `rtbp_export_formats`: tables (reports) and, where
	 * free offers streaming, row-by-row files (M11 exports).
	 *
	 * @return array
	 */
	public static function format(): array {
		$format = array(
			'label'     => __( 'Excel (XLSX)', 'radius-hotel-booking-pro' ),
			'extension' => 'xlsx',
			'mime'      => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'write'     => array( self::class, 'write' ),
		);
		if ( interface_exists( 'RadiusTheme\\RadiusHotelBooking\\Services\\Export\\ExportStream' ) ) {
			$format['stream'] = static fn() => new XlsxStream();
		}
		return $format;
	}

	/**
	 * A column letter: 0 → A, 25 → Z, 26 → AA.
	 *
	 * @param int $index Zero-based column.
	 * @return string
	 */
	public static function column( int $index ): string {
		$name = '';
		for ( $n = $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$name = chr( 65 + ( $n - 1 ) % 26 ) . $name;
		}
		return $name;
	}

	/**
	 * A sheet name Excel accepts, unique in the workbook.
	 *
	 * @param string   $title Table title.
	 * @param int      $index Sheet number (fallback name).
	 * @param string[] $taken Names already used.
	 * @return string
	 */
	public static function sheetName( string $title, int $index, array $taken ): string {
		$name = trim( (string) preg_replace( '/[\[\]:*?\/\\\\]/u', ' ', $title ), " '" );
		$name = '' !== $name ? $name : 'Sheet' . $index;
		$cut  = static fn( $text, $length ) => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
		$base = rtrim( $cut( $name, 31 ) );
		$name = $base;
		for ( $n = 2; in_array( strtolower( $name ), array_map( 'strtolower', $taken ), true ); $n++ ) {
			$suffix = ' (' . $n . ')';
			$name   = rtrim( $cut( $base, 31 - strlen( $suffix ) ) ) . $suffix;
		}
		return $name;
	}

	/**
	 * A number as Excel stores it (no locale, no exponent for ordinary figures).
	 *
	 * @param int|float $value Number.
	 * @return string
	 */
	private static function number( $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		$text = rtrim( rtrim( number_format( (float) $value, 10, '.', '' ), '0' ), '.' );
		return '' === $text || '-0' === $text ? '0' : $text;
	}

	/**
	 * Text for XML, without characters XML 1.0 forbids.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function escape( string $text ): string {
		$text = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * `[Content_Types].xml`.
	 *
	 * @param int $sheets Sheet count.
	 * @return string
	 */
	private static function contentTypes( int $sheets ): string {
		$overrides = '';
		for ( $i = 1; $i <= $sheets; $i++ ) {
			$overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. $overrides
			. '</Types>';
	}

	/**
	 * `xl/workbook.xml`.
	 *
	 * @param string[] $names Sheet names.
	 * @return string
	 */
	private static function workbook( array $names ): string {
		$sheets = '';
		foreach ( $names as $index => $name ) {
			$sheets .= '<sheet name="' . self::escape( $name ) . '" sheetId="' . ( $index + 1 ) . '" r:id="rId' . ( $index + 1 ) . '"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>' . $sheets . '</sheets></workbook>';
	}

	/**
	 * `xl/_rels/workbook.xml.rels`.
	 *
	 * @param int $sheets Sheet count.
	 * @return string
	 */
	private static function workbookRels( int $sheets ): string {
		$rels = '';
		for ( $i = 1; $i <= $sheets; $i++ ) {
			$rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		}
		$rels .= '<Relationship Id="rId' . ( $sheets + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
	}

	/**
	 * `xl/styles.xml`: style 0 normal, style 1 bold (the header row).
	 *
	 * @return string
	 */
	private static function styles(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}
}

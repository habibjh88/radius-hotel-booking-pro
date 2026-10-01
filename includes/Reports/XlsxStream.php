<?php
/**
 * Streamed Excel (M11 T3a, 11.2).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Reports
 */

namespace RadiusTheme\RadiusHotelBookingPro\Reports;

use RadiusTheme\RadiusHotelBooking\Services\Export\ExportStream;

defined( 'ABSPATH' ) || exit;

/**
 * XLSX row by row, for exports of any size (the free `ExportStream`; only
 * loaded where free defines it — `XlsxWriter::format()` checks).
 *
 * Each row's XML is appended to a temporary file as it comes, so memory does
 * not grow with the export. `finish()` writes the worksheet (its head needs
 * the column widths, known only at the end), then zips it from disk with the
 * package parts `XlsxWriter` shares with the report exports — same styles,
 * same cell rules: a bold, frozen header; numbers numeric; text as inline
 * strings (never a formula).
 */
class XlsxStream implements ExportStream {

	/**
	 * The file to produce.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Rows' XML, appended as they come.
	 *
	 * @var string
	 */
	private string $rows_path = '';

	/**
	 * Open rows file.
	 *
	 * @var resource|null
	 */
	private $rows = null;

	/**
	 * Rows written (the header included).
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * The first row is a header (bold, frozen).
	 *
	 * @var bool
	 */
	private bool $header = false;

	/**
	 * Column index => longest value.
	 *
	 * @var array<int, int>
	 */
	private array $widths = array();

	/**
	 * Start the file.
	 *
	 * @param string   $path    File to write.
	 * @param string[] $columns Header labels.
	 * @return void
	 * @throws \RuntimeException When the temporary file cannot be opened.
	 */
	public function begin( string $path, array $columns ): void {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$this->path      = $path;
		$this->rows_path = wp_tempnam( 'rtbp-xlsx-rows' );
		$handle          = fopen( $this->rows_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- our own temporary file.
		if ( false === $handle ) {
			throw new \RuntimeException( 'The spreadsheet could not be started.' );
		}
		$this->rows = $handle;
		if ( $columns ) {
			$this->header = true;
			$this->append( $columns, true );
		}
	}

	/**
	 * Add one row.
	 *
	 * @param array $row Values.
	 * @return void
	 */
	public function write( array $row ): void {
		$this->append( $row, false );
	}

	/**
	 * Build the workbook and clean up.
	 *
	 * @return void
	 * @throws \RuntimeException When the archive cannot be written.
	 */
	public function finish(): void {
		$sheet = '';
		try {
			if ( $this->rows ) {
				fclose( $this->rows ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see begin().
				$this->rows = null;
			}

			// The worksheet: head (with the widths) + the streamed rows + tail.
			$sheet = wp_tempnam( 'rtbp-xlsx-sheet' );
			$out   = fopen( $sheet, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- our own temporary file.
			$in    = fopen( $this->rows_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- see above.
			if ( false === $out || false === $in ) {
				throw new \RuntimeException( 'The spreadsheet could not be assembled.' );
			}
			$written = false !== fwrite( $out, XlsxWriter::sheetOpen( $this->widths, $this->header ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
			$copied  = stream_copy_to_stream( $in, $out );
			$written = $written && false !== $copied && $copied === filesize( $this->rows_path );
			$written = $written && false !== fwrite( $out, XlsxWriter::SHEET_CLOSE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see above.
			if ( ! $written ) {
				throw new \RuntimeException( 'The spreadsheet could not be assembled.' );
			}
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.

			$zip = new \ZipArchive();
			if ( true !== $zip->open( $this->path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				throw new \RuntimeException( 'The spreadsheet could not be created.' );
			}
			foreach ( XlsxWriter::packageParts( array( 'Sheet1' ) ) as $part => $xml ) {
				$zip->addFromString( $part, $xml );
			}
			$zip->addFile( $sheet, 'xl/worksheets/sheet1.xml' );
			// The archive reads the sheet file here, on close.
			if ( ! $zip->close() ) {
				throw new \RuntimeException( 'The spreadsheet could not be written.' );
			}
		} finally {
			foreach ( array( $this->rows_path, $sheet ) as $temp ) {
				if ( '' !== $temp && file_exists( $temp ) ) {
					wp_delete_file( $temp );
				}
			}
		}
	}

	/**
	 * Append a row's XML.
	 *
	 * @param array $values Values.
	 * @param bool  $header The header row.
	 * @return void
	 * @throws \RuntimeException When the row cannot be written.
	 */
	private function append( array $values, bool $header ): void {
		++$this->count;
		if ( false === fwrite( $this->rows, XlsxWriter::rowXml( $this->count, $values, $header, $this->widths ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- see begin().
			// A full disk must never leave a short file unnoticed.
			throw new \RuntimeException( 'The spreadsheet could not be written.' );
		}
	}
}

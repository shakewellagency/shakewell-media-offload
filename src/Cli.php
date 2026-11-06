<?php
/**
 * WP-CLI: capture every media address before a switch, and compare after it.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload;

defined( 'ABSPATH' ) || exit;

/**
 * Both commands only read. They print what the site produces right now, whichever plugin is serving media.
 */
final class Cli {

	/**
	 * Every attachment's address, original and each size, as the site makes it now.
	 *
	 * @return array<int,array{id:int,size:string,url:string}>
	 */
	public static function collect(): array {
		global $wpdb;

		$rows = array();
		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- paged read of attachment IDs for a one-off command.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID LIMIT 500", $last ) );
			foreach ( $ids as $id ) {
				$id     = (int) $id;
				$last   = $id;
				$rows[] = array(
					'id'   => $id,
					'size' => 'full',
					'url'  => (string) wp_get_attachment_url( $id ),
				);
				$meta   = wp_get_attachment_metadata( $id );
				foreach ( array_keys( (array) ( is_array( $meta ) ? ( $meta['sizes'] ?? array() ) : array() ) ) as $size ) {
					$image  = wp_get_attachment_image_src( $id, (string) $size );
					$rows[] = array(
						'id'   => $id,
						'size' => (string) $size,
						'url'  => $image ? (string) $image[0] : '',
					);
				}
			}
			wp_cache_flush_runtime();
		} while ( $ids );

		return $rows;
	}

	/**
	 * Prints every attachment's URLs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Flags.
	 */
	public function urls( $args, $assoc_args ): void {
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', self::collect(), array( 'id', 'size', 'url' ) );
	}

	/**
	 * Compares the current URLs with a CSV made earlier by `urls --format=csv`. Exits with an error if any differ.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The CSV from `urls --format=csv`.
	 *
	 * @param array $args File path.
	 */
	public function compare( $args ): void {
		$before = self::read( (string) $args[0] );
		$diff   = self::diff( $before, self::collect() );

		foreach ( $diff as $line ) {
			\WP_CLI::line( $line );
		}
		if ( $diff ) {
			\WP_CLI::error( sprintf( '%d address(es) differ.', count( $diff ) ) );
		}
		\WP_CLI::success( sprintf( 'All %d addresses are identical.', count( $before ) ) );
	}

	/**
	 * Reads a CSV from `urls`.
	 *
	 * @param string $file Path.
	 * @return array<int,array{id:int,size:string,url:string}>
	 */
	public static function read( string $file ): array {
		$handle = is_readable( $file ) ? fopen( $file, 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a local CSV the operator named.
		if ( ! $handle ) {
			\WP_CLI::error( 'Cannot read the CSV file.' );
		}

		$rows = array();
		fgetcsv( $handle, 0, ',', '"', '' );
		do {
			$line = fgetcsv( $handle, 0, ',', '"', '' );
			if ( false === $line ) {
				break;
			}
			if ( count( $line ) >= 3 ) {
				$rows[] = array(
					'id'   => (int) $line[0],
					'size' => $line[1],
					'url'  => $line[2],
				);
			}
		} while ( true );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with the fopen above.

		return $rows;
	}

	/**
	 * Lines describing every difference between two lists.
	 *
	 * @param array $before Earlier list.
	 * @param array $after  Current list.
	 * @return string[]
	 */
	public static function diff( array $before, array $after ): array {
		$map = static function ( array $rows ): array {
			$out = array();
			foreach ( $rows as $r ) {
				$out[ $r['id'] . ' ' . $r['size'] ] = $r['url'];
			}

			return $out;
		};
		$old = $map( $before );
		$new = $map( $after );
		$out = array();

		foreach ( $old as $name => $url ) {
			if ( ! isset( $new[ $name ] ) ) {
				$out[] = "MISSING $name: was $url";
			} elseif ( $new[ $name ] !== $url ) {
				$out[] = "CHANGED $name: was $url, now {$new[ $name ]}";
			}
		}

		return $out;
	}
}

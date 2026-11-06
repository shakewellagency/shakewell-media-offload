<?php
/**
 * Which S3 object holds which upload. Reads this plugin's own table first, then WP Offload Media's tables
 * (never written), so addresses of files it offloaded stay exactly as they are.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload;

defined( 'ABSPATH' ) || exit;

/**
 * A row is array{source_path:string,key:string,primary:bool,bucket:string,region:string}: the upload-relative
 * path of one file (original or one size) and the object key it lives under. Private objects are never returned.
 */
final class Keys {

	private const DB_VERSION = '1';

	/**
	 * Per-request memo.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Which of WP Offload Media's tables exist: items, files.
	 *
	 * @var array|null
	 */
	private static $theirs = null;

	/**
	 * Forgets everything cached in memory (tests).
	 */
	public static function reset(): void {
		self::$memo   = array();
		self::$theirs = null;
	}

	/**
	 * This plugin's table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'shakewell_media_offload';
	}

	/**
	 * Creates or updates the table once per schema version.
	 */
	public static function install(): void {
		if ( get_option( 'shakewell_media_offload_db' ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				attachment_id BIGINT(20) UNSIGNED NOT NULL,
				source_path VARCHAR(1024) NOT NULL,
				object_key VARCHAR(1024) NOT NULL,
				bucket VARCHAR(255) NOT NULL,
				region VARCHAR(64) NOT NULL,
				PRIMARY KEY  (id),
				KEY attachment_id (attachment_id),
				KEY source_path (source_path(190))
			) $charset;"
		);
		update_option( 'shakewell_media_offload_db', self::DB_VERSION );
	}

	/**
	 * Records that a file is in the bucket.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $source Upload-relative path.
	 * @param string $key    Object key.
	 */
	public static function record( int $id, string $source, string $key ): void {
		global $wpdb;

		$cfg = Plugin::config();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, a write that must not be cached.
		$wpdb->delete(
			self::table(),
			array(
				'attachment_id' => $id,
				'source_path'   => $source,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, a write that must not be cached.
		$wpdb->insert(
			self::table(),
			array(
				'attachment_id' => $id,
				'source_path'   => $source,
				'object_key'    => $key,
				'bucket'        => $cfg['bucket'],
				'region'        => $cfg['region'],
			)
		);
		self::$memo = array();
	}

	/**
	 * Forgets an attachment's rows (its objects have been deleted).
	 *
	 * @param int $id Attachment ID.
	 */
	public static function forget( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, a write that must not be cached.
		$wpdb->delete( self::table(), array( 'attachment_id' => $id ) );
		self::$memo = array();
	}

	/**
	 * Whether one of WP Offload Media's tables exists.
	 *
	 * @param string $which items or files.
	 */
	private static function has( string $which ): bool {
		global $wpdb;

		if ( null === self::$theirs ) {
			self::$theirs = array();
			foreach ( array( 'items', 'files' ) as $name ) {
				$table = $wpdb->get_blog_prefix() . 'as3cf_' . $name;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off existence check per request.
				self::$theirs[ $name ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			}
		}

		return self::$theirs[ $which ];
	}

	/**
	 * Builds a row, or null when the key is unusable.
	 *
	 * @param string $source Upload-relative path.
	 * @param string $key    Object key.
	 * @param bool   $main   Whether this is the attachment's main file.
	 * @param string $bucket Bucket.
	 * @param string $region Region.
	 */
	private static function row( string $source, string $key, bool $main, string $bucket, string $region ): ?array {
		return '' === $key || '' === $source ? null : array(
			'source_path' => $source,
			'key'         => $key,
			'primary'     => $main,
			'bucket'      => $bucket,
			'region'      => $region,
		);
	}

	/**
	 * Every known file of an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return array[]
	 */
	public static function for_attachment( int $id ): array {
		global $wpdb;

		if ( isset( self::$memo[ 'a' . $id ] ) ) {
			return self::$memo[ 'a' . $id ];
		}

		$rows = array();
		$main = (string) get_post_meta( $id, '_wp_attached_file', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only lookups of plugin-owned or fixed table names, built from $wpdb prefixes.
		$own = esc_sql( self::table() );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT source_path, object_key, bucket, region FROM $own WHERE attachment_id = %d", $id ), ARRAY_A ) as $r ) {
			$rows[] = self::row( $r['source_path'], $r['object_key'], $r['source_path'] === $main, $r['bucket'], $r['region'] );
		}

		if ( ! $rows && self::has( 'items' ) ) {
			$items = esc_sql( $wpdb->get_blog_prefix() . 'as3cf_items' );
			$item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $items WHERE source_type = 'media-library' AND provider = 'aws' AND source_id = %d", $id ), ARRAY_A );
			if ( $item && ! (int) $item['is_private'] ) {
				if ( self::has( 'files' ) ) {
					$files = esc_sql( $wpdb->get_blog_prefix() . 'as3cf_files' );
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT source_path, path, is_private, size FROM $files WHERE item_id = %d ORDER BY id", $item['id'] ), ARRAY_A ) as $f ) {
						if ( ! (int) $f['is_private'] ) {
							$rows[] = self::row( $f['source_path'], $f['path'], '__as3cf_primary' === $f['size'], $item['bucket'], $item['region'] );
						}
					}
				}
				if ( ! $rows ) {
					$rows = self::legacy( $item );
				}
			}
		}
		// phpcs:enable

		self::$memo[ 'a' . $id ] = array_values( array_filter( $rows ) );

		return self::$memo[ 'a' . $id ];
	}

	/**
	 * The rows of an item that WP Offload Media never migrated to its files table: the main object plus the
	 * objects named in extra_info.
	 *
	 * @param array $item as3cf_items row.
	 * @return array[]
	 */
	private static function legacy( array $item ): array {
		$rows = array(
			self::row( $item['source_path'], $item['path'], true, $item['bucket'], $item['region'] ),
		);
		if ( '' !== $item['original_path'] && $item['original_path'] !== $item['path'] ) {
			$rows[] = self::row( $item['original_source_path'], $item['original_path'], false, $item['bucket'], $item['region'] );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WP Offload Media's own serialized column, read with objects disabled.
		$extra   = is_serialized( (string) $item['extra_info'] ) ? unserialize( $item['extra_info'], array( 'allowed_classes' => false ) ) : array();
		$objects = is_array( $extra ) && is_array( $extra['objects'] ?? null ) ? $extra['objects'] : array();
		$dir     = dirname( $item['path'] );
		$sdir    = dirname( $item['source_path'] );
		foreach ( $objects as $size => $object ) {
			$file = is_array( $object ) ? (string) ( $object['source_file'] ?? '' ) : '';
			if ( '' === $file || '__as3cf_primary' === $size || ! empty( $object['is_private'] ) ) {
				continue;
			}
			$rows[] = self::row( ( '.' === $sdir ? '' : "$sdir/" ) . $file, ( '.' === $dir ? '' : "$dir/" ) . $file, false, $item['bucket'], $item['region'] );
		}

		return $rows;
	}

	/**
	 * The row for one upload-relative path, or null when it is not in the bucket.
	 *
	 * @param string $source Upload-relative path.
	 */
	public static function for_path( string $source ): ?array {
		global $wpdb;

		if ( array_key_exists( 'p' . $source, self::$memo ) ) {
			return self::$memo[ 'p' . $source ];
		}

		$found = null;
		$own   = esc_sql( self::table() );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attachment_id FROM $own WHERE source_path = %s LIMIT 1", $source ) ); // phpcs:ignore WordPress.DB -- own table, read only.
		if ( ! $id && self::has( 'items' ) ) {
			$id = self::their_id( $source );
		}
		foreach ( $id ? self::for_attachment( $id ) : array() as $row ) {
			if ( $row['source_path'] === $source ) {
				$found = $row;
			}
		}

		self::$memo[ 'p' . $source ] = $found;

		return $found;
	}

	/**
	 * The attachment WP Offload Media recorded for a path: by its files table, else by the main object of
	 * a legacy item (a size is tried as its main file with the -WxH or -scaled suffix removed).
	 *
	 * @param string $source Upload-relative path.
	 */
	private static function their_id( string $source ): int {
		global $wpdb;

		$items = esc_sql( $wpdb->get_blog_prefix() . 'as3cf_items' );
		$files = esc_sql( $wpdb->get_blog_prefix() . 'as3cf_files' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only lookups of fixed table names, built from $wpdb prefixes.
		if ( self::has( 'files' ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT i.source_id FROM $files f JOIN $items i ON i.id = f.item_id WHERE f.source_path = %s AND i.source_type = 'media-library' LIMIT 1", $source ) );
			if ( $id ) {
				return $id;
			}
		}

		$ext        = pathinfo( $source, PATHINFO_EXTENSION );
		$stem       = substr( $source, 0, -strlen( $ext ) - 1 );
		$candidates = array( $source );
		$plain      = preg_replace( '/-\d+x\d+$/', '', $stem );
		if ( $plain !== $stem ) {
			$candidates[] = "$plain.$ext";
			$candidates[] = "$plain-scaled.$ext";
		}
		$marks = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );
		$sql   = "SELECT source_id FROM $items WHERE source_type = 'media-library' AND (source_path IN ($marks) OR original_source_path IN ($marks)) LIMIT 1";
		$id    = (int) $wpdb->get_var( $wpdb->prepare( $sql, ...array_merge( $candidates, $candidates ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the placeholders are built from the candidate count.
		// phpcs:enable

		return $id;
	}
}

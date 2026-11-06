<?php
/**
 * Copies an attachment's files to the bucket, and removes them when WordPress deletes the attachment.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload;

defined( 'ABSPATH' ) || exit;

/**
 * The only deletion in this plugin is Uploader::delete(), run by WordPress's own delete_attachment action,
 * and it removes only the object keys recorded for that attachment. Local files are never touched.
 */
final class Uploader {

	/**
	 * Files already sent in this request, by key, so metadata updated twice is uploaded once.
	 *
	 * @var array<string,string>
	 */
	private static $sent = array();

	/**
	 * Forgets what was sent (tests).
	 */
	public static function reset(): void {
		self::$sent = array();
	}

	/**
	 * Upload-relative paths of an attachment's original and every generated size.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $meta Attachment metadata.
	 * @return string[]
	 */
	private static function files( int $id, array $meta ): array {
		$main = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $main ) {
			return array();
		}

		$dir   = dirname( $main );
		$dir   = '.' === $dir ? '' : $dir . '/';
		$files = array( $main );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
				$files[] = $dir . $size['file'];
			}
		}
		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$files[] = $dir . $meta['original_image'];
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Filter wp_generate_attachment_metadata and wp_update_attachment_metadata: sends the files to the bucket.
	 * A file that cannot be sent is logged and stays served from the server. Always returns the metadata untouched.
	 *
	 * @param mixed $meta Attachment metadata.
	 * @param int   $id   Attachment ID.
	 * @return mixed
	 */
	public static function sync( $meta, $id ) {
		$id  = (int) $id;
		$cfg = Plugin::config();
		if ( ! is_array( $meta ) || ! $id || '' === $cfg['bucket'] || '' === $cfg['region'] ) {
			return $meta;
		}

		$base = realpath( wp_get_upload_dir()['basedir'] );
		/**
		 * Largest file sent, in bytes. The file is read into memory for the signed request.
		 *
		 * @param int $bytes Default 100 MB.
		 */
		$max = (int) apply_filters( 'shakewell_media_offload_max_bytes', 100 * MB_IN_BYTES );

		// ponytail: one in-memory PUT per file; larger files stay on the server until multipart upload is worth building.
		foreach ( self::files( $id, $meta ) as $source ) {
			$path = false === strpos( $source, '..' ) && false === strpos( $source, "\0" ) && $base ? realpath( $base . '/' . $source ) : false;
			if ( ! $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
				continue;
			}

			$key   = $cfg['prefix'] . $source;
			$stamp = filemtime( $path ) . ':' . filesize( $path );
			if ( ( self::$sent[ $key ] ?? '' ) === $stamp ) {
				continue;
			}
			if ( filesize( $path ) > $max ) {
				Aws::report( 'upload', 0, 'too_large', 'config' );
				continue;
			}

			try {
				$type = wp_check_filetype( $source )['type'];
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file inside the uploads folder, checked above.
				$status = Aws::object( 'PUT', $key, $type ? $type : 'application/octet-stream', (string) file_get_contents( $path ) );
			} catch ( S3Error $e ) {
				Aws::report( 'upload', $e->status, $e->reason, $e->stage );
				continue;
			}

			if ( $status < 200 || $status >= 300 ) {
				Aws::report( 'upload', $status );
				continue;
			}

			self::$sent[ $key ] = $stamp;
			Keys::record( $id, $source, $key );
		}

		return $meta;
	}

	/**
	 * Action delete_attachment: deletes the attachment's own objects, then forgets them. A failed delete is logged
	 * and never stops WordPress deleting the attachment.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function delete( $id ): void {
		$id     = (int) $id;
		$cfg    = Plugin::config();
		$delete = array();
		foreach ( $id ? Keys::for_attachment( $id ) : array() as $row ) {
			// Only objects in the configured bucket, and only plain keys.
			if ( $row['bucket'] === $cfg['bucket'] && '/' !== substr( $row['key'], -1 ) && false === strpos( $row['key'], '..' ) && '/' !== $row['key'][0] ) {
				$delete[ $row['key'] ] = true;
			}
		}

		$failed = false;
		foreach ( array_keys( $delete ) as $key ) {
			try {
				$status = Aws::object( 'DELETE', (string) $key );
			} catch ( S3Error $e ) {
				Aws::report( 'delete', $e->status, $e->reason, $e->stage );
				$failed = true;
				continue;
			}
			if ( $status < 200 || $status >= 300 ) {
				Aws::report( 'delete', $status );
				$failed = true;
			}
		}

		if ( ! $failed ) {
			Keys::forget( $id );
		}
	}
}

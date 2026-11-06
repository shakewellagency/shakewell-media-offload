<?php
/**
 * Rewrites upload addresses to the bucket's address, for files known to be in the bucket. Anything else is
 * left exactly as WordPress made it.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload;

defined( 'ABSPATH' ) || exit;

/**
 * The address is https://<domain>/<key>, the same shape WP Offload Media builds: the file name (last path
 * segment) is percent-encoded unless it already holds a "%", the rest of the key is left as stored.
 */
final class Urls {

	/**
	 * The public address of one object.
	 *
	 * @param array $row A Keys row.
	 */
	public static function remote( array $row ): string {
		$cfg    = Plugin::config();
		$domain = $cfg['domain'];

		if ( '' === $domain ) {
			// No delivery domain: the bucket's own address, as WP Offload Media builds it (no region label for us-east-1).
			$region = '' === $row['region'] || 'us-east-1' === $row['region'] ? 's3' : 's3.' . $row['region'];
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the bucket's own address, which is what this plugin exists to serve (documented in the readme).
			$domain = false === strpos( $row['bucket'], '.' ) ? "{$row['bucket']}.$region.amazonaws.com" : "$region.amazonaws.com/{$row['bucket']}";
		}

		return 'https://' . $domain . '/' . self::encode( $row['key'] );
	}

	/**
	 * Percent-encodes the file name of a path unless it is already encoded.
	 *
	 * @param string $path Path or URL without a query string.
	 */
	public static function encode( string $path ): string {
		$name = substr( $path, (int) strrpos( '/' . $path, '/' ) );
		if ( false !== strpos( $name, '%' ) || rawurlencode( $name ) === $name ) {
			return $path;
		}

		return str_replace( $name, rawurlencode( $name ), $path );
	}

	/**
	 * Whether serving from the bucket is possible with the current settings.
	 */
	private static function ready(): bool {
		$cfg = Plugin::config();

		return '' !== $cfg['domain'] || ( '' !== $cfg['bucket'] && '' !== $cfg['region'] );
	}

	/**
	 * The row for the file in $url that belongs to an attachment, matched by file name.
	 *
	 * @param mixed $url URL (local or already rewritten).
	 * @param int   $id  Attachment ID.
	 */
	private static function row_for( $url, $id ): ?array {
		if ( ! is_string( $url ) || '' === $url || ! $id || ! self::ready() ) {
			return null;
		}

		$name = rawurldecode( wp_basename( (string) strtok( $url, '?#' ) ) );
		foreach ( Keys::for_attachment( (int) $id ) as $row ) {
			if ( wp_basename( $row['source_path'] ) === $name ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The bucket address of the file in $url, or $url when the file is not in the bucket.
	 *
	 * @param mixed $url URL (local or already rewritten).
	 * @param int   $id  Attachment ID.
	 */
	private static function for_attachment( $url, $id ): string {
		$row = self::row_for( $url, $id );

		return $row ? self::remote( $row ) . self::tail( $url ) : (string) $url;
	}

	/**
	 * Whether $url is already the bucket address of one of the attachment's files.
	 *
	 * @param mixed $url URL.
	 * @param int   $id  Attachment ID.
	 */
	private static function is_remote( $url, $id ): bool {
		return null !== self::row_for( $url, $id ) && self::for_attachment( $url, $id ) === $url;
	}

	/**
	 * The query string and fragment of a URL, kept as they were.
	 *
	 * @param string $url URL.
	 */
	private static function tail( string $url ): string {
		$pos = strcspn( $url, '?#' );

		return substr( $url, $pos );
	}

	/**
	 * Filter wp_get_attachment_url.
	 *
	 * @param mixed $url Address.
	 * @param int   $id  Attachment ID.
	 * @return mixed
	 */
	public static function attachment_url( $url, $id ) {
		return self::for_attachment( $url, $id );
	}

	/**
	 * Filter wp_get_attachment_image_src.
	 *
	 * @param mixed $image [url, width, height, is_intermediate] or false.
	 * @param int   $id    Attachment ID.
	 * @return mixed
	 */
	public static function image_src( $image, $id ) {
		if ( is_array( $image ) && isset( $image[0] ) ) {
			$image[0] = self::for_attachment( $image[0], $id );
		}

		return $image;
	}

	/**
	 * Filter image_get_intermediate_size.
	 *
	 * @param mixed $data Size data with a url.
	 * @param int   $id   Attachment ID.
	 * @return mixed
	 */
	public static function intermediate_size( $data, $id ) {
		if ( is_array( $data ) && isset( $data['url'] ) ) {
			$data['url'] = self::for_attachment( $data['url'], $id );
		}

		return $data;
	}

	/**
	 * Filter wp_calculate_image_srcset_meta. Core builds a srcset only when the image address contains the file
	 * path from the metadata, which a bucket address does not ("2026/11/" is missing, or the name is
	 * percent-encoded). For a bucket address of this attachment, the metadata is reduced to the encoded file names.
	 *
	 * @param mixed  $meta  Attachment metadata.
	 * @param array  $size  Unused.
	 * @param string $src   The image address.
	 * @param int    $id    Attachment ID.
	 * @return mixed
	 */
	public static function srcset_meta( $meta, $size = array(), $src = '', $id = 0 ) {
		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! is_string( $meta['file'] ) || ! is_string( $src ) || false !== strpos( $src, $meta['file'] ) || ! self::is_remote( $src, $id ) ) {
			return $meta;
		}

		$meta['file'] = self::encode( wp_basename( $meta['file'] ) );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $info ) {
			if ( is_array( $info ) && ! empty( $info['file'] ) && is_string( $info['file'] ) ) {
				$meta['sizes'][ $name ]['file'] = self::encode( $info['file'] );
			}
		}

		return $meta;
	}

	/**
	 * Filter wp_calculate_image_srcset.
	 *
	 * @param mixed $sources       Candidate sources, each with a url.
	 * @param array $size_array    Unused.
	 * @param mixed $image_src     Unused.
	 * @param mixed $image_meta    Unused.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed
	 */
	public static function srcset( $sources, $size_array = array(), $image_src = '', $image_meta = array(), $attachment_id = 0 ) {
		if ( is_array( $sources ) ) {
			foreach ( $sources as $width => $source ) {
				if ( is_array( $source ) && isset( $source['url'] ) ) {
					$sources[ $width ]['url'] = self::for_attachment( $source['url'], $attachment_id );
				}
			}
		}

		return $sources;
	}

	/**
	 * Filter wp_image_file_matches_image_meta: core only builds a srcset when the image address is in the uploads
	 * folder. An address that is exactly one of this attachment's bucket addresses counts as a match too.
	 *
	 * @param mixed  $matched  Whether core matched.
	 * @param string $location The image address.
	 * @param mixed  $meta     Unused.
	 * @param int    $id       Attachment ID.
	 * @return mixed
	 */
	public static function file_matches_meta( $matched, $location = '', $meta = array(), $id = 0 ) {
		return $matched || ( is_string( $location ) && '' !== $location && self::is_remote( $location, $id ) );
	}

	/**
	 * Rewrites the upload addresses in a piece of content. A scheme-less address stays scheme-less.
	 *
	 * @param mixed $html Content.
	 * @return mixed
	 */
	public static function content( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! self::ready() ) {
			return $html;
		}

		$uploads = wp_get_upload_dir();
		$base    = preg_replace( '#^https?:#', '', (string) $uploads['baseurl'] );
		if ( false === strpos( $html, $base . '/' ) ) {
			return $html;
		}

		$pattern = '#(https?:)?' . preg_quote( $base, '#' ) . '/([^"\'\s<>()\\\\?\#&]+)#';

		$out = preg_replace_callback(
			$pattern,
			static function ( array $m ): string {
				$source = $m[2];
				$name   = wp_basename( $source );
				if ( false !== strpos( $name, '%' ) ) {
					$source = substr( $source, 0, -strlen( $name ) ) . rawurldecode( $name );
				}
				$row = Keys::for_path( $source );
				if ( ! $row ) {
					return $m[0];
				}
				$url = self::remote( $row );

				return '' === $m[1] ? substr( $url, strlen( 'https:' ) ) : $url;
			},
			$html
		);

		// A failed regex (null) must never blank the content.
		return null === $out ? $html : $out;
	}
}

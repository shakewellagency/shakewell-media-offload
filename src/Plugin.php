<?php
/**
 * Plugin bootstrap and configuration.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload;

defined( 'ABSPATH' ) || exit;

/**
 * Settings come from constants (wp-config.php) or the environment, never the database:
 * SHAKEWELL_MEDIA_OFFLOAD_ENABLED, _BUCKET, _REGION, _PREFIX, _DELIVERY_DOMAIN. When one is not set, the names
 * and AS3CF_SETTINGS used by WP Offload Media are read, so a site can switch without touching its settings.
 */
final class Plugin {

	/**
	 * Parsed settings, kept for the request.
	 *
	 * @var array|null
	 */
	private static $config = null;

	/**
	 * Registers hooks. Called on plugins_loaded. Does nothing unless switched on, and nothing (but a notice)
	 * while WP Offload Media is running.
	 */
	public static function boot(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'shakewell-media-offload', Cli::class );
		}

		if ( ! self::enabled() ) {
			return;
		}

		if ( self::conflict() ) {
			add_action( 'admin_notices', array( self::class, 'conflict_notice' ) );
			return;
		}

		self::hook();
	}

	/**
	 * Registers the upload, delete and URL hooks.
	 */
	public static function hook(): void {
		Keys::install();

		add_filter( 'pre_http_send_through_proxy', array( Aws::class, 'no_proxy' ), 10, 2 );

		add_filter( 'wp_generate_attachment_metadata', array( Uploader::class, 'sync' ), 20, 2 );
		add_filter( 'wp_update_attachment_metadata', array( Uploader::class, 'sync' ), 20, 2 );
		add_action( 'delete_attachment', array( Uploader::class, 'delete' ) );

		add_filter( 'wp_get_attachment_url', array( Urls::class, 'attachment_url' ), 99, 2 );
		add_filter( 'wp_get_attachment_image_src', array( Urls::class, 'image_src' ), 99, 2 );
		add_filter( 'image_get_intermediate_size', array( Urls::class, 'intermediate_size' ), 99, 2 );
		add_filter( 'wp_image_file_matches_image_meta', array( Urls::class, 'file_matches_meta' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset_meta', array( Urls::class, 'srcset_meta' ), 10, 4 );
		add_filter( 'wp_calculate_image_srcset', array( Urls::class, 'srcset' ), 99, 5 );
		foreach ( array( 'the_content', 'the_excerpt', 'render_block' ) as $name ) {
			add_filter( $name, array( Urls::class, 'content' ), 100 );
		}
		// Names the Merivale theme (and older code) already call.
		add_filter( 'as3cf_filter_post_local_to_provider', array( Urls::class, 'content' ) );
		add_filter( 'as3cf_filter_post_local_to_s3', array( Urls::class, 'content' ) );
	}

	/**
	 * Whether WP Offload Media (Lite or Pro) is loaded. Two plugins rewriting the same addresses and
	 * uploading the same files would fight, so this one stays idle.
	 */
	public static function conflict(): bool {
		return function_exists( 'as3cf_init' ) || class_exists( 'Amazon_S3_And_CloudFront', false );
	}

	/**
	 * Admin notice shown instead of running next to WP Offload Media.
	 */
	public static function conflict_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Shakewell Media Offload is switched on but is idle because WP Offload Media is active. Deactivate WP Offload Media to let this plugin take over.', 'shakewell-media-offload' )
		);
	}

	/**
	 * Whether the plugin is switched on.
	 */
	public static function enabled(): bool {
		$on = filter_var( self::raw( 'SHAKEWELL_MEDIA_OFFLOAD_ENABLED' ), FILTER_VALIDATE_BOOLEAN );

		/**
		 * Filters whether the plugin is on. Runs once, on plugins_loaded.
		 *
		 * @param bool $on Whether SHAKEWELL_MEDIA_OFFLOAD_ENABLED is true.
		 */
		return (bool) apply_filters( 'shakewell_media_offload_enabled', $on );
	}

	/**
	 * A constant, else an environment variable, else null.
	 *
	 * @param string $name Name.
	 * @return string|null
	 */
	private static function raw( string $name ): ?string {
		if ( defined( $name ) && is_scalar( constant( $name ) ) ) {
			return trim( (string) constant( $name ) );
		}
		$value = getenv( $name );

		return is_string( $value ) ? trim( $value ) : null;
	}

	/**
	 * Forgets the parsed settings (tests).
	 */
	public static function reset(): void {
		self::$config = null;
		Keys::reset();
	}

	/**
	 * The validated settings. An invalid value becomes '' so nothing is sent or rewritten with it.
	 *
	 * @return array{bucket:string,region:string,prefix:string,domain:string}
	 */
	public static function config(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}

		$old = array();
		$raw = defined( 'AS3CF_SETTINGS' ) ? constant( 'AS3CF_SETTINGS' ) : null;
		if ( is_string( $raw ) && is_serialized( $raw ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the constant WP Offload Media defines, read with objects disabled.
			$raw = unserialize( $raw, array( 'allowed_classes' => false ) );
		}
		if ( is_array( $raw ) ) {
			$old = $raw;
		}

		// Own name first, then the WP Offload Media name, then its AS3CF_SETTINGS.
		$get = static function ( string $name, string $setting ) use ( $old ): string {
			$value = self::raw( 'SHAKEWELL_MEDIA_OFFLOAD_' . $name ) ?? self::raw( 'AS3CF_' . $name );

			return $value ?? (string) ( $old[ $setting ] ?? '' );
		};

		$bucket = $get( 'BUCKET', 'bucket' );
		$region = $get( 'REGION', 'region' );
		$domain = self::raw( 'SHAKEWELL_MEDIA_OFFLOAD_DELIVERY_DOMAIN' ) ?? self::raw( 'AS3CF_DELIVERY_DOMAIN' ) ?? ( ! empty( $old['enable-delivery-domain'] ) ? (string) ( $old['delivery-domain'] ?? '' ) : '' );
		$prefix = self::raw( 'SHAKEWELL_MEDIA_OFFLOAD_PREFIX' );
		if ( null === $prefix ) {
			$prefix = isset( $old['enable-object-prefix'] ) && empty( $old['enable-object-prefix'] ) ? '' : (string) ( $old['object-prefix'] ?? 'wp-content/uploads/' );
		}

		$domain = preg_replace( '@[^a-zA-Z0-9.\-]@', '', (string) preg_replace( '@^[a-zA-Z]*://@', '', $domain ) );
		$prefix = '' === $prefix ? '' : trailingslashit( ltrim( $prefix, '/' ) );
		// A prefix that is not plain path characters disables uploads rather than writing somewhere else.
		$prefix_ok = (bool) preg_match( '#^[A-Za-z0-9._/ !*()\'-]*$#', $prefix ) && false === strpos( $prefix, '..' );

		self::$config = array(
			'bucket' => $prefix_ok && preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket ) ? $bucket : '',
			'region' => preg_match( '/^[a-z]{2}(-[a-z]+)+-\d$/', $region ) ? $region : '',
			'prefix' => $prefix_ok ? $prefix : '',
			'domain' => $domain,
		);

		return self::$config;
	}
}

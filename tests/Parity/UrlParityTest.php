<?php
/**
 * URL parity with WP Offload Media Lite 3.4.3, the real plugin, loaded in the test environment only.
 *
 * Attachments are offloaded the way the plugin offloads them (its own item class, its own tables, the
 * settings from AS3CF_SETTINGS). Only the copy to S3 is skipped, because the AWS SDK it uses does not go through
 * the WordPress HTTP API. Every address WP Offload Media makes is captured, its hooks are removed, this plugin's
 * hooks are added, and the addresses are compared byte for byte.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload\Tests;

use DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item;
use Shakewell\MediaOffload\Keys;
use Shakewell\MediaOffload\Plugin;
use WP_UnitTestCase;

final class UrlParityTest extends WP_UnitTestCase {

	/**
	 * Attachment IDs by case name.
	 *
	 * @var array<string,int>
	 */
	private $ids = array();

	public function set_up(): void {
		parent::set_up();
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_ENABLED=true' );
		Plugin::reset();
		add_filter( 'big_image_size_threshold', static fn() => 500 );

		$copy = static function ( string $name ): string {
			$to = get_temp_dir() . $name;
			copy( DIR_TESTDATA . '/images/canola.jpg', $to );

			return $to;
		};

		// Scaled by WordPress (original_image set), with the medium and thumbnail sizes.
		$this->ids['scaled'] = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		// Not scaled: below the threshold.
		add_filter( 'big_image_size_threshold', '__return_false', 20 );
		$this->ids['plain'] = self::factory()->attachment->create_upload_object( $copy( 'plain-photo.jpg' ) );
		// A name that needs percent-encoding.
		$this->ids['cjk'] = self::factory()->attachment->create_upload_object( $copy( '图片.jpg' ) );
		// Not an image.
		file_put_contents( get_temp_dir() . 'notes.txt', 'hello' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->ids['text'] = self::factory()->attachment->create_upload_object( get_temp_dir() . 'notes.txt' );
		// Never offloaded.
		$this->ids['local'] = self::factory()->attachment->create_upload_object( $copy( 'stays-local.jpg' ) );
		// An item that WP Offload Media has not migrated to its files table.
		$this->ids['legacy'] = self::factory()->attachment->create_upload_object( $copy( 'legacy-photo.jpg' ) );
		remove_filter( 'big_image_size_threshold', '__return_false', 20 );

		foreach ( array( 'scaled', 'plain', 'cjk', 'text', 'legacy' ) as $case ) {
			$item = Media_Library_Item::create_from_source_id( $this->ids[ $case ] );
			$this->assertNotWPError( $item );
			$this->assertTrue( (bool) $item->save() );
		}

	}

	public function tear_down(): void {
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_ENABLED' );
		Plugin::reset();
		parent::tear_down();
	}

	/**
	 * Every address the site makes for the test attachments, by name.
	 *
	 * @return array<string,string>
	 */
	private function capture(): array {
		$out = array();
		foreach ( $this->ids as $case => $id ) {
			$out[ "$case url" ] = (string) wp_get_attachment_url( $id );
			$meta               = wp_get_attachment_metadata( $id );
			$sizes              = array_merge( array( 'full' ), array_keys( (array) ( $meta['sizes'] ?? array() ) ) );
			foreach ( $sizes as $size ) {
				$image = wp_get_attachment_image_src( $id, $size );
				$out[ "$case image $size" ] = $image ? (string) $image[0] : '';
				$info                       = image_get_intermediate_size( $id, $size );
				$out[ "$case intermediate $size" ] = $info ? (string) $info['url'] : '';
			}
			// What a page shows: the image tag (src and srcset) and a bare link, passed through the content filters.
			$out[ "$case content" ] = apply_filters(
				'the_content',
				wp_get_attachment_image( $id, 'full' ) . ' <a href="' . esc_url( wp_get_attachment_url( $id ) ) . '">link</a> ' . wp_get_attachment_image( $id, 'medium' )
			);
			// The WP Offload Media content filter, on addresses it holds in their local form.
			$local                = $this->local_url( $id );
			$out[ "$case filter" ] = (string) apply_filters( 'as3cf_filter_post_local_to_provider', $local );
			// Every file of the attachment in its local form: as stored, percent-encoded, with a query string (a name that really changes when encoded is left out: WP Offload Media leaves that local form alone and this plugin rewrites it, see the next test), http and scheme-less.
			$files = array();
			$dir   = trailingslashit( dirname( $local ) );
			$names = array( wp_basename( $local ) );
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $info ) {
				$names[] = $info['file'];
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$names[] = $meta['original_image'];
			}
			foreach ( $names as $name ) {
				$files[] = $dir . $name;
				if ( rawurlencode( $name ) === $name ) {
					$files[] = $dir . rawurlencode( $name );
				}
				$files[] = $dir . $name . '?ver=2';
				$files[] = preg_replace( '#^https?:#', '', $dir . $name );
				$files[] = preg_replace( '#^https:#', 'http:', $dir . $name );
			}
			$out[ "$case every local file, content" ] = apply_filters( 'the_content', '<a href="' . implode( '">x</a><a href="', $files ) . '">x</a><img src="' . $files[0] . '" alt="">' );
			$out[ "$case every local file, filter" ]  = (string) apply_filters( 'as3cf_filter_post_local_to_provider', implode( ' ', $files ) );
			$out[ "$case filter, scheme-less" ] = (string) apply_filters( 'as3cf_filter_post_local_to_provider', preg_replace( '#^https?:#', '', $local ) );
		}

		return $out;
	}

	/**
	 * The upload address of an attachment's main file, as WordPress itself would make it.
	 *
	 * @param int $id Attachment ID.
	 */
	private function local_url( int $id ): string {
		return trailingslashit( wp_get_upload_dir()['baseurl'] ) . get_post_meta( $id, '_wp_attached_file', true );
	}

	/**
	 * Switches from WP Offload Media to this plugin: removes every hook WP Offload Media added, then adds ours.
	 */
	private function switch_plugins(): void {
		global $wp_filter;
		foreach ( array_keys( $wp_filter ) as $name ) {
			$hook = $wp_filter[ $name ];
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( is_array( $fn ) && is_object( $fn[0] ) && preg_match( '/^(AS3CF_|Amazon_S3|DeliciousBrains\\\\)/', get_class( $fn[0] ) ) ) {
						remove_filter( $name, $fn, $priority );
					}
				}
			}
		}
		Plugin::reset();
		Plugin::hook();
	}

	public function test_every_address_is_byte_identical_after_the_switch(): void {
		$before = $this->capture();

		// The capture is not trivially equal: offloaded items are on the delivery domain, the rest are local.
		$this->assertStringStartsWith( 'https://media.example.com/wp-content/uploads/', $before['scaled url'] );
		$this->assertStringContainsString( '/wp-content/uploads/', $before['local url'] );
		$this->assertStringNotContainsString( 'media.example.com', $before['local url'] );
		$this->assertStringContainsString( '%E5%9B%BE%E7%89%87', $before['cjk url'] );

		// One item loses its files-table rows, as an item from before WP Offload Media 3.4 has none: its addresses must
		// still come out the same, from the path, original_path and extra_info columns WP Offload Media also fills in.
		global $wpdb;
		$item_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}as3cf_items WHERE source_id = %d", $this->ids['legacy'] ) );
		$this->assertGreaterThan( 0, $wpdb->delete( $wpdb->prefix . 'as3cf_files', array( 'item_id' => $item_id ) ) );
		wp_cache_flush();

		$this->switch_plugins();
		$after = $this->capture();

		$this->assertSame( array_keys( $before ), array_keys( $after ) );
		foreach ( $before as $name => $url ) {
			$this->assertSame( $url, $after[ $name ], $name );
		}
	}

	public function test_a_percent_encoded_local_address_is_rewritten_to_the_same_address_as_the_plain_one(): void {
		$this->switch_plugins();
		$local = $this->local_url( $this->ids['cjk'] );
		$plain = apply_filters( 'as3cf_filter_post_local_to_provider', $local );
		$this->assertStringStartsWith( 'https://media.example.com/', $plain );
		$this->assertSame( $plain, apply_filters( 'as3cf_filter_post_local_to_provider', dirname( $local ) . '/' . rawurlencode( wp_basename( $local ) ) ) );
	}

	public function test_settings_are_read_from_the_serialized_as3cf_settings_constant(): void {
		$this->assertSame(
			array(
				'bucket' => 'example-bucket',
				'region' => 'ap-southeast-2',
				'prefix' => 'wp-content/uploads/',
				'domain' => 'media.example.com',
			),
			Plugin::config()
		);
	}

	public function test_deleting_an_item_wp_offload_media_offloaded_deletes_its_own_keys_and_no_others(): void {
		global $wpdb;
		putenv( 'AWS_ACCESS_KEY_ID=AKIDENV' );
		putenv( 'AWS_SECRET_ACCESS_KEY=SECRETENV' );
		$wanted = $wpdb->get_col( $wpdb->prepare( "SELECT f.path FROM {$wpdb->prefix}as3cf_files f JOIN {$wpdb->prefix}as3cf_items i ON i.id = f.item_id WHERE i.source_id = %d", $this->ids['scaled'] ) );
		$this->assertCount( 4, $wanted );

		$calls = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$calls ) {
				$calls[] = array( $args['method'], $url );

				return array(
					'response' => array( 'code' => 204 ),
					'body'     => '',
					'headers'  => array(),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		$this->switch_plugins();
		wp_delete_attachment( $this->ids['scaled'], true );
		putenv( 'AWS_ACCESS_KEY_ID' );
		putenv( 'AWS_SECRET_ACCESS_KEY' );

		$deleted = array();
		foreach ( $calls as $call ) {
			$this->assertSame( 'DELETE', $call[0] );
			$this->assertStringStartsWith( 'https://example-bucket.s3.ap-southeast-2.amazonaws.com/', $call[1] );
			$deleted[] = rawurldecode( ltrim( (string) wp_parse_url( $call[1], PHP_URL_PATH ), '/' ) );
		}
		sort( $deleted );
		sort( $wanted );
		$this->assertSame( $wanted, $deleted );
	}

	public function test_the_file_table_and_the_legacy_columns_agree_with_what_wp_offload_media_made(): void {
		$rows = Keys::for_attachment( $this->ids['legacy'] );
		$this->assertNotEmpty( $rows );
		$scaled = Keys::for_attachment( $this->ids['scaled'] );
		$this->assertCount( 4, $scaled, 'original, scaled, medium and thumbnail' );
		$this->assertMatchesRegularExpression( '#^wp-content/uploads/\d{4}/\d{2}/\d+/canola(-\d+)?-scaled\.jpg$#', $scaled[0]['key'], 'WP Offload Media puts a version folder in its keys, so a key cannot be rebuilt from the upload path' );
	}

	public function test_this_plugin_refuses_to_run_while_wp_offload_media_is_active(): void {
		$this->assertTrue( Plugin::conflict() );
		remove_all_filters( 'wp_get_attachment_url' );
		Plugin::boot();
		$this->assertFalse( has_filter( 'wp_get_attachment_url', array( \Shakewell\MediaOffload\Urls::class, 'attachment_url' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'conflict_notice' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::conflict_notice();
		$this->assertStringContainsString( 'WP Offload Media is active', (string) ob_get_clean() );
	}
}

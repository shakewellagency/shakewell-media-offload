<?php
/**
 * Upload, serve, delete and the capture/compare check, with S3 faked at pre_http_request.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload\Tests;

use Shakewell\MediaOffload\Cli;
use Shakewell\MediaOffload\Keys;
use Shakewell\MediaOffload\Plugin;
use Shakewell\MediaOffload\Urls;
use WP_UnitTestCase;

final class OffloadTest extends WP_UnitTestCase {

	use FakeAws;

	public function set_up(): void {
		parent::set_up();
		$this->fake_aws_up();
		add_filter( 'big_image_size_threshold', static fn() => 500 );
	}

	public function tear_down(): void {
		$this->fake_aws_down();
		parent::tear_down();
	}

	/**
	 * An attachment made from the WordPress test image.
	 *
	 * @param string $name File name to give it.
	 */
	private function upload( string $name = 'photo.jpg' ): int {
		$file = get_temp_dir() . $name;
		copy( DIR_TESTDATA . '/images/canola.jpg', $file );

		return self::factory()->attachment->create_upload_object( $file );
	}

	/**
	 * Every upload-relative path of an attachment on disk: the original, the scaled file and every size.
	 *
	 * @param int $id Attachment ID.
	 */
	private function files_of( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		$dir  = trailingslashit( dirname( get_post_meta( $id, '_wp_attached_file', true ) ) );
		$out  = array( get_post_meta( $id, '_wp_attached_file', true ), $dir . $meta['original_image'] );
		foreach ( $meta['sizes'] as $size ) {
			$out[] = $dir . $size['file'];
		}
		sort( $out );

		return $out;
	}

	// ---- The switch. ----

	public function test_off_by_default_registers_nothing_and_calls_nothing(): void {
		remove_all_filters( 'wp_generate_attachment_metadata' );
		Plugin::boot();

		$this->assertFalse( has_filter( 'wp_generate_attachment_metadata', array( \Shakewell\MediaOffload\Uploader::class, 'sync' ) ) );
		$this->assertFalse( has_filter( 'wp_get_attachment_url', array( Urls::class, 'attachment_url' ) ) );
		$this->assertFalse( has_action( 'delete_attachment', array( \Shakewell\MediaOffload\Uploader::class, 'delete' ) ) );

		$id = $this->upload();
		$this->assertSame( array(), $this->calls );
		$this->assertStringStartsWith( home_url(), wp_get_attachment_url( $id ) );
	}

	public function test_the_switch_is_a_truthy_constant_or_environment_variable_or_the_filter(): void {
		$this->assertFalse( Plugin::enabled() );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_ENABLED=false' );
		$this->assertFalse( Plugin::enabled() );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_ENABLED=1' );
		$this->assertTrue( Plugin::enabled() );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_ENABLED' );
		add_filter( 'shakewell_media_offload_enabled', '__return_true' );
		$this->assertTrue( Plugin::enabled() );
	}

	public function test_settings_fall_back_to_the_names_wp_offload_media_uses(): void {
		putenv( 'AS3CF_BUCKET=old-bucket' );
		putenv( 'AS3CF_REGION=us-east-1' );
		putenv( 'AS3CF_DELIVERY_DOMAIN=https://cdn.example.com/' );
		Plugin::reset();
		$this->assertSame(
			array(
				'bucket' => 'old-bucket',
				'region' => 'us-east-1',
				'prefix' => 'wp-content/uploads/',
				'domain' => 'cdn.example.com',
			),
			Plugin::config()
		);

		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_BUCKET=new-bucket' );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_PREFIX=media' );
		Plugin::reset();
		$this->assertSame( array( 'new-bucket', 'media/' ), array( Plugin::config()['bucket'], Plugin::config()['prefix'] ) );

		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_PREFIX=' );
		Plugin::reset();
		$this->assertSame( '', Plugin::config()['prefix'], 'An empty prefix is allowed, and means the bucket root.' );
	}

	public function test_a_bad_bucket_region_or_prefix_is_dropped_so_nothing_is_sent_with_it(): void {
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_BUCKET=Bad_Bucket' );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_REGION=moon' );
		Plugin::reset();
		$this->assertSame( array( '', '' ), array( Plugin::config()['bucket'], Plugin::config()['region'] ) );

		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_BUCKET=good-bucket' );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_PREFIX=../etc/' );
		Plugin::reset();
		$this->assertSame( '', Plugin::config()['bucket'], 'A prefix that climbs out disables uploads.' );
	}

	// ---- Upload. ----

	public function test_an_upload_puts_the_original_and_every_size_signed_with_the_right_key_and_type(): void {
		$this->switch_on();
		$id = $this->upload();

		$puts = $this->s3_calls( 'PUT' );
		$keys = array_map( fn( $c ) => $this->key_of( $c[1] ), $puts );
		sort( $keys );
		$expected = array_map( static fn( $f ) => 'wp-content/uploads/' . $f, $this->files_of( $id ) );

		$this->assertSame( $expected, $keys );
		$this->assertCount( 4, $puts, 'scaled, original, medium and thumbnail' );
		foreach ( $puts as $call ) {
			$this->assertStringStartsWith( 'https://test-bucket.s3.ap-southeast-2.amazonaws.com/wp-content/uploads/', $call[1] );
			$this->assertSame( 'image/jpeg', $call[2]['headers']['content-type'] );
			$this->assertSame( hash( 'sha256', $call[2]['body'] ), $call[2]['headers']['x-amz-content-sha256'] );
			$this->assertSame( 0, $call[2]['redirection'] );
			$this->assertSame( hash_file( 'sha256', wp_get_upload_dir()['basedir'] . '/' . substr( $this->key_of( $call[1] ), strlen( 'wp-content/uploads/' ) ) ), hash( 'sha256', $call[2]['body'] ), 'the file content is what is sent' );
			$this->assertTrue( $this->signature_is_valid( $call, 'SECRETENV' ) );
		}
	}

	/**
	 * Recomputes a request's Signature Version 4 signature from the request itself, with its own code.
	 *
	 * @param array  $call   [method, url, args].
	 * @param string $secret The secret key.
	 */
	private function signature_is_valid( array $call, string $secret ): bool {
		list( $method, $url, $args ) = $call;
		$headers                     = array_change_key_case( $args['headers'] );
		$this->assertSame( 1, preg_match( '#^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/s3/aws4_request, SignedHeaders=([^,]+), Signature=([0-9a-f]{64})$#', $headers['authorization'], $m ) );
		list( , $access, $day, $region, $signed, $signature ) = $m;

		$this->assertSame( 'AKIDENV', $access );
		$this->assertSame( substr( $headers['x-amz-date'], 0, 8 ), $day );
		$parts   = wp_parse_url( $url );
		$headers = $headers + array( 'host' => $parts['host'] );
		$canon   = '';
		foreach ( explode( ';', $signed ) as $name ) {
			$canon .= $name . ':' . $headers[ $name ] . "\n";
		}
		$request = $method . "\n" . $parts['path'] . "\n\n" . $canon . "\n" . $signed . "\n" . hash( 'sha256', $args['body'] );
		$string  = "AWS4-HMAC-SHA256\n" . $headers['x-amz-date'] . "\n$day/$region/s3/aws4_request\n" . hash( 'sha256', $request );
		$key     = 'AWS4' . $secret;
		foreach ( array( $day, $region, 's3', 'aws4_request' ) as $part ) {
			$key = hash_hmac( 'sha256', $part, $key, true );
		}

		return hash_equals( hash_hmac( 'sha256', $string, $key ), $signature );
	}

	public function test_local_files_are_kept_and_no_key_is_stored_in_the_database(): void {
		$this->switch_on();
		$id = $this->upload();

		foreach ( $this->files_of( $id ) as $file ) {
			$this->assertFileExists( wp_get_upload_dir()['basedir'] . '/' . $file );
		}

		global $wpdb;
		$dump = wp_json_encode( array( $wpdb->get_results( "SELECT * FROM {$wpdb->options}", ARRAY_A ), $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta}", ARRAY_A ), $wpdb->get_results( 'SELECT * FROM ' . Keys::table(), ARRAY_A ) ) );
		$this->assertStringNotContainsString( 'SECRETENV', (string) $dump );
		$this->assertStringNotContainsString( 'AKIDENV', (string) $dump );
	}

	public function test_a_file_is_not_sent_twice_in_one_request(): void {
		$this->switch_on();
		$id = $this->upload();
		$n  = count( $this->s3_calls( 'PUT' ) );

		wp_update_attachment_metadata( $id, wp_get_attachment_metadata( $id ) );

		$this->assertSame( $n, count( $this->s3_calls( 'PUT' ) ) );
	}

	public function test_credentials_come_from_the_instance_role_when_the_environment_has_none(): void {
		$this->switch_on();
		putenv( 'AWS_ACCESS_KEY_ID' );
		putenv( 'AWS_SECRET_ACCESS_KEY' );
		$this->upload();

		$this->assertStringContainsString( '/latest/api/token', $this->calls[0][1] );
		$this->assertStringContainsString( 'Credential=AKIDIMDS/', $this->s3_calls( 'PUT' )[0][2]['headers']['Authorization'] );
	}

	public function test_a_failed_put_is_logged_with_tokens_only_and_the_file_stays_local(): void {
		$this->switch_on();
		$this->routes['s3'] = array(
			'response' => array( 'code' => 403 ),
			'body'     => '<Error><Code>AccessDenied</Code><Message>secret detail SECRETENV</Message></Error>',
		);
		$id = $this->upload();

		$this->assertSame( array(), Keys::for_attachment( $id ) );
		$this->assertStringStartsWith( home_url(), wp_get_attachment_url( $id ) );
		$log = implode( "\n", $this->log_lines() );
		$this->assertStringContainsString( 'upload failed stage=s3 status=403 reason=none', $log );
		$this->assertStringNotContainsString( 'SECRETENV', $log );
		$this->assertStringNotContainsString( 'secret detail', $log );
	}

	public function test_a_path_that_climbs_out_of_the_uploads_folder_is_never_read(): void {
		$this->switch_on();
		$id   = $this->upload();
		$meta = wp_get_attachment_metadata( $id );
		$this->calls = array();
		$meta['sizes']['evil'] = array( 'file' => '../../../wp-config.php' );
		$meta['original_image'] = '../../../../etc/passwd';

		\Shakewell\MediaOffload\Uploader::reset();
		\Shakewell\MediaOffload\Uploader::sync( $meta, $id );

		foreach ( $this->s3_calls( 'PUT' ) as $call ) {
			$this->assertStringNotContainsString( 'wp-config', $call[1] );
			$this->assertStringNotContainsString( 'passwd', $call[1] );
		}
	}

	// ---- Serving. ----

	public function test_uploaded_files_are_served_from_the_delivery_domain_and_others_stay_local(): void {
		$local = $this->upload( 'local-only.jpg' );
		$this->switch_on();
		$id = $this->upload( 'on-cdn.jpg' );

		$meta = wp_get_attachment_metadata( $id );
		$base = 'https://media.example.com/wp-content/uploads/' . dirname( $meta['file'] ) . '/';
		$this->assertSame( $base . wp_basename( $meta['file'] ), wp_get_attachment_url( $id ) );
		$this->assertSame( $base . $meta['sizes']['medium']['file'], wp_get_attachment_image_src( $id, 'medium' )[0] );
		$this->assertSame( $base . $meta['sizes']['thumbnail']['file'], image_get_intermediate_size( $id, 'thumbnail' )['url'] );

		$srcset = wp_get_attachment_image_srcset( $id, 'medium' );
		$this->assertStringContainsString( $base . $meta['sizes']['medium']['file'] . ' 300w', (string) $srcset );
		$this->assertStringNotContainsString( home_url(), (string) $srcset );

		$path = dirname( $meta['file'] ) . '/' . $meta['sizes']['thumbnail']['file'];
		$html = apply_filters( 'the_content', '<img src="' . wp_get_upload_dir()['baseurl'] . '/' . $path . '"><a href="//' . preg_replace( '#^https?://#', '', wp_get_upload_dir()['baseurl'] ) . '/' . $path . '?v=1">x</a>' );
		$this->assertStringContainsString( 'src="' . $base . $meta['sizes']['thumbnail']['file'] . '"', $html );
		$this->assertStringContainsString( 'href="//media.example.com/wp-content/uploads/' . $path . '?v=1"', $html );
		$this->assertSame( $base . $meta['sizes']['thumbnail']['file'], apply_filters( 'as3cf_filter_post_local_to_provider', wp_get_upload_dir()['baseurl'] . '/' . $path ), 'the compatibility filter the Merivale theme calls' );
		$this->assertSame( $base . $meta['sizes']['thumbnail']['file'], apply_filters( 'as3cf_filter_post_local_to_s3', wp_get_upload_dir()['baseurl'] . '/' . $path ) );

		$this->assertStringStartsWith( home_url(), wp_get_attachment_url( $local ) );
		$this->assertStringContainsString( home_url(), (string) wp_get_attachment_image_srcset( $local, 'medium' ) );
	}

	public function test_without_a_delivery_domain_the_files_come_from_the_bucket_address(): void {
		$this->switch_on();
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_DELIVERY_DOMAIN=' );
		Plugin::reset();
		$id = $this->upload();
		$this->assertStringStartsWith( 'https://test-bucket.s3.ap-southeast-2.amazonaws.com/wp-content/uploads/', wp_get_attachment_url( $id ) );

		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_BUCKET=a.dotted.bucket' );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_REGION=us-east-1' );
		Plugin::reset();
		$this->assertStringStartsWith( 'https://s3.amazonaws.com/a.dotted.bucket/', Urls::remote( array( 'key' => 'k', 'bucket' => 'a.dotted.bucket', 'region' => '' ) ) ); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment
		$this->assertSame( 'https://plain.s3.amazonaws.com/a%20b.jpg', Urls::remote( array( 'key' => 'a b.jpg', 'bucket' => 'plain', 'region' => 'us-east-1' ) ) ); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment
	}

	// ---- Delete. ----

	public function test_deleting_an_attachment_deletes_exactly_its_own_objects(): void {
		$this->switch_on();
		$keep = $this->upload( 'keep-me.jpg' );
		$gone = $this->upload( 'delete-me.jpg' );
		$this->assertCount( 8, $this->s3_calls( 'PUT' ) );
		$this->calls = array();

		wp_delete_attachment( $gone, true );

		$deleted  = array_map( fn( $c ) => $this->key_of( $c[1] ), $this->s3_calls( 'DELETE' ) );
		sort( $deleted );
		$this->assertCount( 4, $deleted );
		foreach ( $deleted as $key ) {
			$this->assertStringContainsString( 'delete-me', $key );
			$this->assertStringStartsWith( 'wp-content/uploads/', $key );
		}
		foreach ( $this->s3_calls( 'DELETE' ) as $call ) {
			$this->assertSame( 'DELETE', $call[0] );
			$this->assertSame( '', $call[2]['body'] );
			$this->assertTrue( $this->signature_is_valid( $call, 'SECRETENV' ) );
		}
		$this->assertSame( array(), Keys::for_attachment( $gone ) );
		$this->assertCount( 4, Keys::for_attachment( $keep ), 'the other attachment is untouched' );
	}

	public function test_nothing_is_deleted_for_an_attachment_that_is_not_in_the_bucket_or_when_files_are_replaced(): void {
		$local = $this->upload( 'never-offloaded.jpg' );
		$this->switch_on();
		$id = $this->upload( 'replaced.jpg' );
		$this->calls = array();

		// Regenerating or editing metadata sends files but never deletes anything.
		\Shakewell\MediaOffload\Uploader::reset();
		wp_update_attachment_metadata( $id, wp_get_attachment_metadata( $id ) );
		wp_delete_attachment( $local, true );

		$this->assertSame( array(), $this->s3_calls( 'DELETE' ) );
	}

	public function test_a_failed_delete_is_logged_does_not_stop_wordpress_and_keeps_the_record(): void {
		$this->switch_on();
		$id = $this->upload();
		$this->routes['s3delete'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => 'boom',
		);

		$this->assertNotFalse( wp_delete_attachment( $id, true ) );
		$this->assertNull( get_post( $id ) );
		$this->assertNotEmpty( Keys::for_attachment( $id ), 'rows stay so a later run can retry' );
		$this->assertStringContainsString( 'delete failed stage=s3 status=500', implode( "\n", $this->log_lines() ) );
	}

	public function test_objects_in_another_bucket_are_never_deleted(): void {
		$this->switch_on();
		$id = $this->upload();
		global $wpdb;
		$wpdb->update( Keys::table(), array( 'bucket' => 'someone-elses-bucket' ), array( 'attachment_id' => $id ) );
		Keys::reset();
		$this->calls = array();

		wp_delete_attachment( $id, true );

		$this->assertSame( array(), $this->s3_calls( 'DELETE' ) );
	}

	// ---- Capture and compare. ----

	public function test_the_capture_lists_every_attachment_with_its_full_and_size_addresses(): void {
		$this->switch_on();
		$id = $this->upload();

		$rows = array_values( array_filter( Cli::collect(), static fn( $r ) => $r['id'] === $id ) );

		$this->assertSame( array( 'full', 'medium', 'thumbnail' ), array_column( $rows, 'size' ) );
		foreach ( $rows as $row ) {
			$this->assertStringStartsWith( 'https://media.example.com/wp-content/uploads/', $row['url'] );
		}
	}

	public function test_compare_reports_a_planted_difference_and_nothing_when_all_match(): void {
		$this->switch_on();
		$this->upload();

		$file = (string) tempnam( sys_get_temp_dir(), 'before' );
		$fh   = fopen( $file, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $fh, array( 'id', 'size', 'url' ), ',', '"', '' );
		foreach ( Cli::collect() as $row ) {
			fputcsv( $fh, $row, ',', '"', '' );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$before = Cli::read( $file );
		$this->assertNotEmpty( $before );
		$this->assertSame( array(), Cli::diff( $before, Cli::collect() ), 'identical addresses report nothing' );

		$before[0]['url']  .= 'x';
		$before[]           = array(
			'id'   => 999999,
			'size' => 'full',
			'url'  => 'https://example.com/gone.jpg',
		);
		$diff               = Cli::diff( $before, Cli::collect() );
		$this->assertCount( 2, $diff );
		$this->assertStringStartsWith( "CHANGED {$before[0]['id']} {$before[0]['size']}: was ", $diff[0] );
		$this->assertSame( 'MISSING 999999 full: was https://example.com/gone.jpg', $diff[1] );
		unlink( $file );
	}
}

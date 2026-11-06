<?php
/**
 * Signing and credentials, with the HTTP layer faked at pre_http_request.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload\Tests;

use Shakewell\MediaOffload\Aws;
use Shakewell\MediaOffload\Credentials;
use Shakewell\MediaOffload\S3Error;
use WP_UnitTestCase;

final class AwsTest extends WP_UnitTestCase {

	use FakeAws;

	private const T = 1793754123;

	public function set_up(): void {
		parent::set_up();
		$this->fake_aws_up();
	}

	public function tear_down(): void {
		$this->fake_aws_down();
		parent::tear_down();
	}

	public function test_metadata_and_container_calls_never_use_a_proxy(): void {
		$this->assertFalse( Aws::no_proxy( null, 'http://169.254.169.254/latest/api/token' ) );
		$this->assertFalse( Aws::no_proxy( true, 'http://169.254.170.2/v2/credentials/abc' ) );
		$this->assertTrue( Aws::no_proxy( true, 'https://bucket.s3.ap-southeast-2.amazonaws.com/a.jpg' ) );
	}

	public function test_sigv4_matches_the_example_in_the_aws_documentation(): void {
		// "Example: PUT Object" in the S3 Signature Version 4 documentation.
		$creds = array(
			'AccessKeyId'     => 'AKIAIOSFODNN7EXAMPLE',
			'SecretAccessKey' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
		);
		$body  = 'Welcome to Amazon S3.';
		$hash  = hash( 'sha256', $body );
		$this->assertSame( '44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', $hash );

		$signed = Aws::sign(
			'PUT',
			'examplebucket.s3.amazonaws.com',
			'/' . Aws::encode_key( 'test$file.text' ),
			array(
				'date'                => 'Fri, 24 May 2013 00:00:00 GMT',
				'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
			),
			$hash,
			$creds,
			'us-east-1',
			gmmktime( 0, 0, 0, 5, 24, 2013 )
		);

		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class, Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
			$signed['Authorization']
		);
	}

	public function test_a_session_token_is_signed_and_sent_only_when_there_is_one(): void {
		$with    = Aws::sign( 'DELETE', 'b.s3.us-east-1.amazonaws.com', '/a', array(), hash( 'sha256', '' ), array( 'AccessKeyId' => 'A', 'SecretAccessKey' => 'S', 'Token' => 'TOK' ), 'us-east-1', self::T ); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment
		$without = Aws::sign( 'DELETE', 'b.s3.us-east-1.amazonaws.com', '/a', array(), hash( 'sha256', '' ), array( 'AccessKeyId' => 'A', 'SecretAccessKey' => 'S' ), 'us-east-1', self::T ); // phpcs:ignore Universal.Arrays.MultipleStatementAlignment

		$this->assertSame( 'TOK', $with['x-amz-security-token'] );
		$this->assertStringContainsString( 'x-amz-date;x-amz-security-token,', $with['Authorization'] );
		$this->assertArrayNotHasKey( 'x-amz-security-token', $without );
		$this->assertStringContainsString( 'x-amz-date,', $without['Authorization'] );
	}

	public function test_a_key_is_encoded_per_segment_and_a_dotted_bucket_uses_the_path_style(): void {
		$this->assertSame( 'wp-content/uploads/2026/11/a%20b%20%E5%9B%BE.jpg', Aws::encode_key( 'wp-content/uploads/2026/11/a b 图.jpg' ) );
		$this->assertSame( array( 'plain.s3.ap-southeast-2.amazonaws.com', '' ), Aws::host( 'plain', 'ap-southeast-2' ) );
		$this->assertSame( array( 's3.ap-southeast-2.amazonaws.com', '/staging.assets.example.com' ), Aws::host( 'staging.assets.example.com', 'ap-southeast-2' ) );
	}

	public function test_environment_variables_come_first_and_make_no_http_call(): void {
		putenv( 'AWS_ACCESS_KEY_ID=AKIDENV' );
		putenv( 'AWS_SECRET_ACCESS_KEY=SECRETENV' );
		putenv( 'AWS_SESSION_TOKEN=SESSIONENV' );
		putenv( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI=/v2/credentials/x' );

		$creds = Credentials::get();

		$this->assertSame( array( 'env', 'AKIDENV', 'SESSIONENV' ), array( $creds['Source'], $creds['AccessKeyId'], $creds['Token'] ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_half_an_environment_pair_is_ignored(): void {
		putenv( 'AWS_ACCESS_KEY_ID=AKIDENV' );

		$this->assertSame( 'imds', Credentials::get( self::T )['Source'] );
	}

	public function test_container_endpoint_beats_instance_metadata(): void {
		putenv( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI=/v2/credentials/abc' );

		$creds = Credentials::get( time() );

		$this->assertSame( array( 'ecs', 'AKIDECS' ), array( $creds['Source'], $creds['AccessKeyId'] ) );
		$this->assertSame( 'http://169.254.170.2/v2/credentials/abc', $this->calls[0][1] );
		$this->assertCount( 1, $this->calls, 'IMDS is never asked when ECS answers.' );
	}

	public function test_container_full_uri_over_plain_http_to_a_remote_host_is_refused(): void {
		putenv( 'AWS_CONTAINER_CREDENTIALS_FULL_URI=http://example.com/creds' );

		$this->expectException( S3Error::class );
		try {
			Credentials::get();
		} finally {
			$this->assertSame( array(), $this->calls );
		}
	}

	public function test_instance_metadata_is_the_last_resort_and_follows_imdsv2(): void {
		$creds = Credentials::get( self::T );

		$this->assertSame( array( 'imds', 'AKIDIMDS' ), array( $creds['Source'], $creds['AccessKeyId'] ) );
		$this->assertCount( 3, $this->calls );
		list( $token, $role, $key ) = $this->calls;
		$this->assertSame( array( 'PUT', 'http://169.254.169.254/latest/api/token' ), array( $token[0], $token[1] ) );
		$this->assertSame( '300', $token[2]['headers']['X-aws-ec2-metadata-token-ttl-seconds'] );
		$this->assertSame( 'imds-token', $role[2]['headers']['X-aws-ec2-metadata-token'] );
		$this->assertSame( 'imds-token', $key[2]['headers']['X-aws-ec2-metadata-token'] );
	}

	public function test_metadata_can_be_switched_off_with_the_standard_variable(): void {
		putenv( 'AWS_EC2_METADATA_DISABLED=true' );

		$this->expectException( S3Error::class );
		try {
			Credentials::get();
		} finally {
			$this->assertSame( array(), $this->calls );
		}
	}

	public function test_aws_calls_never_follow_redirects(): void {
		Credentials::get( self::T );
		Aws::http( 'GET', 'https://bucket.s3.ap-southeast-2.amazonaws.com/a', array() );

		$this->assertCount( 4, $this->calls );
		foreach ( $this->calls as $call ) {
			$this->assertSame( 0, $call[2]['redirection'] );
		}
	}

	public function test_credentials_are_reused_until_five_minutes_before_expiry_then_refreshed(): void {
		$expiry                = self::T + 3600;
		$this->routes['creds'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'AccessKeyId'     => 'A',
					'SecretAccessKey' => 'S',
					'Token'           => 'T',
					'Expiration'      => gmdate( 'Y-m-d\TH:i:s\Z', $expiry ),
				)
			),
		);

		Credentials::get( self::T );
		Credentials::get( $expiry - 301 );
		$this->assertCount( 3, $this->calls, 'Reused 301 seconds before expiry.' );
		Credentials::get( $expiry - 300 );
		$this->assertCount( 6, $this->calls, 'Refreshed 300 seconds before expiry.' );
	}
}

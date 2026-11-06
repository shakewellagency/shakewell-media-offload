<?php
/**
 * A fake AWS for tests: the HTTP layer is answered at pre_http_request, so nothing leaves the container.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload\Tests;

use Shakewell\MediaOffload\Credentials;
use Shakewell\MediaOffload\Plugin;

trait FakeAws {

	private static $aws_env = array(
		'AWS_ACCESS_KEY_ID',
		'AWS_SECRET_ACCESS_KEY',
		'AWS_SESSION_TOKEN',
		'AWS_EC2_METADATA_DISABLED',
		'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI',
		'AWS_CONTAINER_CREDENTIALS_FULL_URI',
		'AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE',
		'AWS_CONTAINER_AUTHORIZATION_TOKEN',
		'SHAKEWELL_MEDIA_OFFLOAD_ENABLED',
		'SHAKEWELL_MEDIA_OFFLOAD_BUCKET',
		'SHAKEWELL_MEDIA_OFFLOAD_REGION',
		'SHAKEWELL_MEDIA_OFFLOAD_PREFIX',
		'SHAKEWELL_MEDIA_OFFLOAD_DELIVERY_DOMAIN',
		'AS3CF_BUCKET',
		'AS3CF_REGION',
		'AS3CF_DELIVERY_DOMAIN',
	);

	/**
	 * Every HTTP request made: array of [method, url, args].
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Per-route replacements for the fake AWS (token, role, creds, ecs, s3): a response array or WP_Error.
	 *
	 * @var array
	 */
	private $routes = array();

	/**
	 * Error log file.
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Original error_log ini value.
	 *
	 * @var string
	 */
	private $old_log;

	private function fake_aws_up(): void {
		foreach ( self::$aws_env as $name ) {
			putenv( $name );
		}
		Credentials::reset();
		Plugin::reset();
		$this->calls  = array();
		$this->routes = array();

		$this->old_log  = (string) ini_get( 'error_log' );
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'molog' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test only.
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	private function fake_aws_down(): void {
		foreach ( self::$aws_env as $name ) {
			putenv( $name );
		}
		Credentials::reset();
		Plugin::reset();
		\Shakewell\MediaOffload\Uploader::reset();
		ini_set( 'error_log', $this->old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test only.
		unlink( $this->log_file );
	}

	/**
	 * Switches the plugin on with a bucket, region, delivery domain and environment keys, and registers its hooks.
	 */
	private function switch_on(): void {
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_ENABLED=true' );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_BUCKET=test-bucket' );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_REGION=ap-southeast-2' );
		putenv( 'SHAKEWELL_MEDIA_OFFLOAD_DELIVERY_DOMAIN=media.example.com' );
		putenv( 'AWS_ACCESS_KEY_ID=AKIDENV' );
		putenv( 'AWS_SECRET_ACCESS_KEY=SECRETENV' );
		Plugin::reset();
		Plugin::boot();
	}

	/**
	 * The fake AWS. Defaults are a working instance role and a successful S3 PUT and DELETE.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->calls[] = array( $args['method'], $url, $args );

		$ok    = static function ( string $body, int $code = 200 ) {
			return array(
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'body'     => $body,
				'headers'  => array(),
				'cookies'  => array(),
			);
		};
		$creds = static function ( string $id ) use ( $ok ) {
			return $ok(
				(string) wp_json_encode(
					array(
						'AccessKeyId'     => $id,
						'SecretAccessKey' => 'SECRET-' . $id,
						'Token'           => 'SESSION-' . $id,
						'Expiration'      => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
					)
				)
			);
		};

		if ( str_contains( $url, '/latest/api/token' ) ) {
			$key = 'token';
			$def = $ok( 'imds-token' );
		} elseif ( str_ends_with( $url, '/security-credentials/' ) ) {
			$key = 'role';
			$def = $ok( "role-name\n" );
		} elseif ( str_contains( $url, '/security-credentials/' ) ) {
			$key = 'creds';
			$def = $creds( 'AKIDIMDS' );
		} elseif ( str_contains( $url, 'amazonaws.com' ) ) {
			$key = 'DELETE' === $args['method'] ? 's3delete' : 's3';
			$def = $ok( '', 'DELETE' === $args['method'] ? 204 : 200 );
		} else {
			$key = 'ecs';
			$def = $creds( 'AKIDECS' );
		}

		return $this->routes[ $key ] ?? $def;
	}

	/**
	 * Requests to S3 with one method.
	 *
	 * @param string $method PUT or DELETE.
	 */
	private function s3_calls( string $method ): array {
		return array_values( array_filter( $this->calls, static fn( $c ) => $method === $c[0] && str_contains( $c[1], 'amazonaws.com' ) ) );
	}

	/**
	 * The object key of an S3 request URL (virtual-hosted style).
	 *
	 * @param string $url Request URL.
	 */
	private function key_of( string $url ): string {
		return rawurldecode( ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
	}

	/**
	 * Log lines written by the plugin.
	 */
	private function log_lines(): array {
		return array_values( array_filter( explode( "\n", (string) file_get_contents( $this->log_file ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}

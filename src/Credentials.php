<?php
/**
 * AWS credentials in the standard order: environment, container endpoint, instance metadata.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload;

defined( 'ABSPATH' ) || exit;

/**
 * Credentials are held in a static variable for the life of the request. They are never written to
 * options, transients, the object cache, files or logs, and are never read from the database.
 */
final class Credentials {

	private const IMDS = 'http://169.254.169.254';
	private const ECS  = 'http://169.254.170.2';

	/**
	 * Hosts that may be called over plain HTTP for AWS_CONTAINER_CREDENTIALS_FULL_URI (as the AWS SDKs allow).
	 */
	private const LOCAL_HOSTS = array( '127.0.0.1', 'localhost', '[::1]', '169.254.170.2', '169.254.170.23', '[fd00:ec2::23]' );

	/**
	 * Container or instance credentials, reused until five minutes before they expire.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Forgets the in-memory credentials.
	 */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * Resolves credentials from the first source that has them.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return array{AccessKeyId:string,SecretAccessKey:string,Token:string,Expiration:int,Source:string}
	 * @throws S3Error When no source yields credentials.
	 */
	public static function get( ?int $now = null ): array {
		$now = $now ?? time();

		$id     = self::env( 'AWS_ACCESS_KEY_ID' );
		$secret = self::env( 'AWS_SECRET_ACCESS_KEY' );
		if ( '' !== $id && '' !== $secret ) {
			return array(
				'AccessKeyId'     => $id,
				'SecretAccessKey' => $secret,
				'Token'           => self::env( 'AWS_SESSION_TOKEN' ),
				'Expiration'      => 0,
				'Source'          => 'env',
			);
		}

		if ( null !== self::$cache && self::$cache['Expiration'] - 300 > $now ) {
			return self::$cache;
		}

		self::$cache = null;
		$creds       = self::ecs( $now ) ?? self::imds( $now );
		self::$cache = $creds;

		return $creds;
	}

	/**
	 * Reads an environment variable the server was started with.
	 *
	 * @param string $name Variable name.
	 */
	private static function env( string $name ): string {
		$value = getenv( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Container credentials (ECS and Fargate). Null when the container endpoint is not configured.
	 *
	 * @param int $now Unix time.
	 * @throws S3Error On any failure.
	 */
	private static function ecs( int $now ): ?array {
		$relative = self::env( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI' );
		$full     = self::env( 'AWS_CONTAINER_CREDENTIALS_FULL_URI' );

		if ( '' !== $relative ) {
			if ( '/' !== $relative[0] || preg_match( '/[\s#]/', $relative ) ) {
				throw new S3Error( 'The container credentials URI is not valid.', 'ecs', 0, 'uri' );
			}
			$url = self::ECS . $relative;
		} elseif ( '' !== $full ) {
			$parts = wp_parse_url( $full );
			$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
			$ok    = 'https' === ( $parts['scheme'] ?? '' ) || ( 'http' === ( $parts['scheme'] ?? '' ) && in_array( $host, self::LOCAL_HOSTS, true ) );
			if ( ! $ok || '' === $host ) {
				throw new S3Error( 'The container credentials URI is not valid.', 'ecs', 0, 'uri' );
			}
			$url = $full;
		} else {
			return null;
		}

		$headers = array();
		$file    = self::env( 'AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE' );
		$auth    = self::env( 'AWS_CONTAINER_AUTHORIZATION_TOKEN' );
		if ( '' !== $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local token file named by the server's own environment.
			$auth = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
			if ( '' === $auth ) {
				throw new S3Error( 'The container authorization token file could not be read.', 'ecs', 0, 'token' );
			}
		}
		if ( '' !== $auth ) {
			if ( preg_match( '/[\r\n]/', $auth ) ) {
				throw new S3Error( 'The container authorization token is not valid.', 'ecs', 0, 'token' );
			}
			$headers['Authorization'] = $auth;
		}

		list( $status, $json ) = Aws::http( 'GET', $url, $headers, '', 2, 'ecs' );

		return self::parse( $json, $status, 'ecs', 'ecs', $now );
	}

	/**
	 * Instance role credentials from the metadata service, IMDSv2 token first.
	 *
	 * @param int $now Unix time.
	 * @throws S3Error On any failure.
	 */
	private static function imds( int $now ): array {
		if ( filter_var( self::env( 'AWS_EC2_METADATA_DISABLED' ), FILTER_VALIDATE_BOOLEAN ) ) {
			throw new S3Error( 'No AWS credentials are available.', 'credentials', 0, 'none' );
		}

		list( $status, $token ) = Aws::http( 'PUT', self::IMDS . '/latest/api/token', array( 'X-aws-ec2-metadata-token-ttl-seconds' => '300' ), '', 2, 'imds' );
		if ( 200 !== $status || '' === $token ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed; only fixed tokens are logged.
			throw new S3Error( 'No AWS instance role available (metadata token request failed).', 'imds', $status, 'token' );
		}
		$auth = array( 'X-aws-ec2-metadata-token' => $token );

		list( $status, $role ) = Aws::http( 'GET', self::IMDS . '/latest/meta-data/iam/security-credentials/', $auth, '', 2, 'imds' );
		$role                  = trim( strtok( $role, "\n" ) ? strtok( $role, "\n" ) : '' );
		if ( 200 !== $status || ! preg_match( '/^[\w+=,.@-]+$/', $role ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed; only fixed tokens are logged.
			throw new S3Error( 'No AWS instance role is attached to this server.', 'imds', $status, 'role' );
		}

		list( $status, $json ) = Aws::http( 'GET', self::IMDS . '/latest/meta-data/iam/security-credentials/' . $role, $auth, '', 2, 'imds' );

		return self::parse( $json, $status, 'imds', 'imds', $now );
	}

	/**
	 * Validates a credentials JSON document (the shape ECS and IMDS share).
	 *
	 * @param string $json   Response body.
	 * @param int    $status HTTP status.
	 * @param string $stage  Stage token for failures.
	 * @param string $source Source name.
	 * @param int    $now    Unix time.
	 * @throws S3Error When the document is unusable.
	 */
	private static function parse( #[\SensitiveParameter] string $json, int $status, string $stage, string $source, int $now ): array {
		$creds = json_decode( $json, true );
		if ( 200 !== $status || ! is_array( $creds ) || empty( $creds['AccessKeyId'] ) || empty( $creds['SecretAccessKey'] ) || empty( $creds['Token'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed; only fixed tokens are logged.
			throw new S3Error( 'Could not read the AWS credentials.', $stage, $status, 'credentials' );
		}

		$expiry = strtotime( (string) ( $creds['Expiration'] ?? '' ) );

		return array(
			'AccessKeyId'     => (string) $creds['AccessKeyId'],
			'SecretAccessKey' => (string) $creds['SecretAccessKey'],
			'Token'           => (string) $creds['Token'],
			'Expiration'      => $expiry ? $expiry : $now + 900,
			'Source'          => $source,
		);
	}
}

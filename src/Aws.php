<?php
/**
 * HTTP and Signature Version 4 for the two S3 calls this plugin makes (PUT and DELETE of one object). No AWS SDK.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload;

defined( 'ABSPATH' ) || exit;

/**
 * Credentials are passed in and used for one request. Parameters that carry credentials or file content
 * are SensitiveParameter, so PHP leaves them out of backtraces (PHP 8.2 and later).
 */
final class Aws {

	/**
	 * Keeps the instance metadata and container credential calls off any HTTP proxy (WP_PROXY_HOST),
	 * so the server's AWS credentials never pass through a third party.
	 *
	 * @param bool|null $send Whether core would use the proxy.
	 * @param string    $uri  Request URL.
	 * @return bool|null
	 */
	public static function no_proxy( $send, $uri ) {
		$host = (string) wp_parse_url( (string) $uri, PHP_URL_HOST );

		return in_array( $host, array( '169.254.169.254', '169.254.170.2', '169.254.170.23' ), true ) ? false : $send;
	}

	/**
	 * Reduces an AWS error type or WordPress error code to a bare token, or '' if it is anything else.
	 *
	 * @param string $raw Raw type or code.
	 */
	public static function token( string $raw ): string {
		$raw = explode( ':', $raw, 2 )[0];
		$raw = substr( $raw, (int) strrpos( '#' . $raw, '#' ) );

		return preg_match( '/^[A-Za-z0-9_]{1,64}$/', $raw ) ? $raw : '';
	}

	/**
	 * One HTTP call through the WordPress HTTP API. A transport failure throws, tagged with $stage.
	 * Redirects are refused so credentials in headers never follow one.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Full URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body    Request body.
	 * @param int                  $timeout Seconds.
	 * @param string               $stage   Stage token for failures.
	 * @return array{0:int,1:string,2:string} Status, body, x-amz-request-id header.
	 * @throws S3Error On a transport error.
	 */
	public static function http(
		string $method,
		string $url,
		#[\SensitiveParameter] array $headers,
		#[\SensitiveParameter] string $body = '',
		int $timeout = 10,
		string $stage = 's3'
	): array {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed; only fixed tokens are logged.
			throw new S3Error( $response->get_error_message(), $stage, 0, self::token( (string) $response->get_error_code() ) );
		}

		return array(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			(string) wp_remote_retrieve_header( $response, 'x-amz-request-id' ),
		);
	}

	/**
	 * The S3 host and path prefix for a bucket: virtual-hosted style, or path style when the bucket name
	 * has a dot (a dotted name cannot match the wildcard certificate).
	 *
	 * @param string $bucket Bucket name.
	 * @param string $region AWS region.
	 * @return array{0:string,1:string} Host, path prefix ('' or '/bucket').
	 */
	public static function host( string $bucket, string $region ): array {
		return false === strpos( $bucket, '.' )
			? array( "$bucket.s3.$region.amazonaws.com", '' ) // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the S3 API host this plugin uploads to (documented in the readme).
			: array( "s3.$region.amazonaws.com", "/$bucket" ); // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- as above.
	}

	/**
	 * Percent-encodes an object key for the request path, segment by segment (RFC 3986, slashes kept).
	 *
	 * @param string $key Object key.
	 */
	public static function encode_key( string $key ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}

	/**
	 * Signature Version 4 headers for one S3 request.
	 *
	 * @param string $method  PUT or DELETE.
	 * @param string $host    Host being called.
	 * @param string $path    Request path, already percent-encoded.
	 * @param array  $extra   Extra headers to sign, lower-case names (for example content-type).
	 * @param string $payload Hex SHA-256 of the body.
	 * @param array  $creds   AccessKeyId, SecretAccessKey and optional Token.
	 * @param string $region  AWS region.
	 * @param int    $now     Unix time.
	 * @return array<string,string> Headers to send (host is added by the HTTP layer).
	 */
	public static function sign( string $method, string $host, string $path, array $extra, string $payload, #[\SensitiveParameter] array $creds, string $region, int $now ): array {
		$token    = $creds['Token'] ?? '';
		$amz_date = gmdate( 'Ymd\THis\Z', $now );
		$day      = gmdate( 'Ymd', $now );
		$scope    = "$day/$region/s3/aws4_request";

		$headers = $extra + array(
			'host'                 => $host,
			'x-amz-content-sha256' => $payload,
			'x-amz-date'           => $amz_date,
		);
		if ( '' !== $token ) {
			$headers['x-amz-security-token'] = $token;
		}
		ksort( $headers );
		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= "$name:$value\n";
		}
		$signed = implode( ';', array_keys( $headers ) );

		$canonical = implode( "\n", array( $method, $path, '', $canonical_headers, $signed, $payload ) );
		$to_sign   = implode( "\n", array( 'AWS4-HMAC-SHA256', $amz_date, $scope, hash( 'sha256', $canonical ) ) );
		$key       = hash_hmac( 'sha256', 'aws4_request', hash_hmac( 'sha256', 's3', hash_hmac( 'sha256', $region, hash_hmac( 'sha256', $day, 'AWS4' . $creds['SecretAccessKey'], true ), true ), true ), true );
		$signature = hash_hmac( 'sha256', $to_sign, $key );

		unset( $headers['host'] );

		return $headers + array( 'Authorization' => "AWS4-HMAC-SHA256 Credential={$creds['AccessKeyId']}/$scope, SignedHeaders=$signed, Signature=$signature" );
	}

	/**
	 * Sends one signed object request. Returns the HTTP status; the caller decides what counts as success.
	 *
	 * @param string $method       PUT or DELETE.
	 * @param string $key          Object key.
	 * @param string $content_type Content-Type for a PUT, '' for a DELETE.
	 * @param string $body         File content for a PUT.
	 * @return int HTTP status.
	 * @throws S3Error On a transport or credentials failure.
	 */
	public static function object( string $method, string $key, string $content_type = '', #[\SensitiveParameter] string $body = '' ): int {
		$cfg = Plugin::config();
		if ( '' === $cfg['bucket'] || '' === $cfg['region'] ) {
			throw new S3Error( 'No valid bucket or region is configured.', 'config', 0, 'config' );
		}

		$now                 = time();
		$creds               = Credentials::get( $now );
		list( $host, $base ) = self::host( $cfg['bucket'], $cfg['region'] );
		$path                = $base . '/' . self::encode_key( $key );
		$extra               = '' === $content_type ? array() : array( 'content-type' => $content_type );
		$headers             = self::sign( $method, $host, $path, $extra, hash( 'sha256', $body ), $creds, $cfg['region'], $now );
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the S3 API endpoint (documented in the readme), not an offloaded asset.
		list( $status ) = self::http( $method, "https://$host$path", $headers, $body, 'PUT' === $method ? 60 : 15 );

		return $status;
	}

	/**
	 * One log line per failure, through the PHP error log. Stage, status and reason only: no key, path,
	 * credential or S3 text.
	 *
	 * @param string $what    What was being done (upload or delete).
	 * @param int    $status  HTTP status, 0 for none.
	 * @param string $reason  Reason token.
	 * @param string $stage   Stage token.
	 */
	public static function report( string $what, int $status, string $reason = '', string $stage = 's3' ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the one deliberate log call; the line holds fixed tokens only.
		error_log( sprintf( '[shakewell-media-offload] %s failed stage=%s status=%d reason=%s', $what, $stage, $status, '' !== $reason ? $reason : 'none' ) );
	}
}

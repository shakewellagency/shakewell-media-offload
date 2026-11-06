<?php
/**
 * An S3 failure with the safe facts kept apart from the message.
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload;

defined( 'ABSPATH' ) || exit;

/**
 * Where it failed (config, credentials, imds, ecs, s3), the HTTP status (0 for none) and a short
 * reason token (an AWS error type or WordPress error code). The message can hold S3's own wording;
 * only stage, status and reason are ever logged.
 */
final class S3Error extends \RuntimeException {

	/**
	 * Stage the failure happened in.
	 *
	 * @var string
	 */
	public $stage;

	/**
	 * HTTP status, 0 when there was no response.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Bare identifier token, or an empty string.
	 *
	 * @var string
	 */
	public $reason;

	/**
	 * Builds the error.
	 *
	 * @param string $message Human message. Never logged.
	 * @param string $stage   Stage token.
	 * @param int    $status  HTTP status.
	 * @param string $reason  Reason token.
	 */
	public function __construct( string $message, string $stage, int $status = 0, string $reason = '' ) {
		parent::__construct( $message );
		$this->stage  = $stage;
		$this->status = $status;
		$this->reason = $reason;
	}
}

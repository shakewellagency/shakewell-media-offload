<?php
/**
 * Plugin Name:       Shakewell Media Offload for Amazon S3
 * Plugin URI:        https://github.com/shakewellagency/shakewell-media-offload
 * Description:       Copies uploads to an Amazon S3 bucket and serves them from it or from CloudFront, with the AWS credentials the server already has. Keeps every existing media address. Off until SHAKEWELL_MEDIA_OFFLOAD_ENABLED is true.
 * Version:           0.1.1
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-media-offload
 *
 * @package Shakewell\MediaOffload
 */

namespace Shakewell\MediaOffload;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.1';
const FILE    = __FILE__;

foreach ( array( 'S3Error', 'Aws', 'Credentials', 'Keys', 'Urls', 'Uploader', 'Cli', 'Plugin' ) as $shakewell_media_offload_class ) {
	require_once __DIR__ . '/src/' . $shakewell_media_offload_class . '.php';
}
unset( $shakewell_media_offload_class );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );

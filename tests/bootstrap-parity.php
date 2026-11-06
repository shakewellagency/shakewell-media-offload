<?php
/**
 * Loads the WordPress test library, WP Offload Media Lite (test environment only) and then this plugin.
 *
 * WP Offload Media is mapped into the tests container by .wp-env.json; it is never part of the plugin.
 *
 * @package Shakewell\MediaOffload
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $tests_dir || ! is_file( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run the tests with: npm test\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $tests_dir . '/includes/functions.php';

// Merivale's offload settings, in the form its config/application.php defines them (a serialized array).
define(
	'AS3CF_SETTINGS',
	serialize(
		array(
			'provider'                       => 'aws',
			'use-server-roles'               => true,
			'bucket'                         => 'merivale-test-bucket',
			'region'                         => 'ap-southeast-2',
			'copy-to-s3'                     => true,
			'serve-from-s3'                  => true,
			'enable-object-prefix'           => true,
			'object-prefix'                  => 'wp-content/uploads/',
			'use-yearmonth-folders'          => true,
			'force-https'                    => true,
			'remove-local-file'              => false,
			'use-bucket-acls'                => false,
			'delivery-provider'              => 'aws',
			'delivery-provider-service-name' => 'cloudfront',
			'enable-delivery-domain'         => true,
			'delivery-domain'                => 'media.example.com',
		)
	)
);

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/amazon-s3-and-cloudfront/wordpress-s3.php';
		require dirname( __DIR__ ) . '/' . basename( dirname( __DIR__ ) ) . '.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';

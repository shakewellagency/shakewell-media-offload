<?php
/**
 * Removes the setting this plugin stored when it is deleted from the Plugins screen.
 *
 * @package Shakewell\MediaOffload
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shakewell_media_offload_db' );

// The shakewell_media_offload table is left in place on purpose. It is the only record of which bucket
// object belongs to which upload; dropping it would turn those uploads back into server addresses.

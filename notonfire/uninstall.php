<?php
/**
 * Removes everything the plugin left behind when it is deleted: the
 * must-use loader, the settings, the synchronized state, the cached update
 * check and the spool of undelivered events.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-notonfire-config.php';
require_once __DIR__ . '/includes/class-notonfire-transport.php';
require_once __DIR__ . '/includes/class-notonfire-loader.php';

NotOnFire_Loader::remove();
NotOnFire_Transport::remove_spool_directory();

delete_option( NotOnFire_Config::SETTINGS_OPTION );
delete_option( NotOnFire_Config::STATE_OPTION );
delete_site_transient( 'notonfire_update_info' );

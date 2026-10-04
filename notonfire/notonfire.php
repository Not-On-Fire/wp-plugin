<?php
/**
 * Plugin Name:       NotOnFire
 * Plugin URI:        https://notonfire.systems
 * Description:       Fatal-error reporting, WordPress update status and analytics for NotOnFire.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NotOnFire
 * Author URI:        https://notonfire.systems
 * License:           GPL-2.0-or-later
 * Text Domain:       notonfire
 * Update URI:        https://notonfire.systems/wordpress-plugin
 *
 * Download this plugin from the application's settings page in the NotOnFire
 * dashboard: the ZIP comes with the site's connection already filled in, so
 * uploading and activating it is the whole install.
 *
 * Activation writes a small loader to wp-content/mu-plugins so the
 * fatal-error reporter runs before other plugins and the theme.
 * Deactivation removes it again.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOTONFIRE_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-notonfire-reporter.php';
require_once __DIR__ . '/includes/class-notonfire-loader.php';
require_once __DIR__ . '/includes/class-notonfire-analytics.php';
require_once __DIR__ . '/includes/class-notonfire-updater.php';

if ( ! class_exists( 'NotOnFire_Plugin', false ) ) {
    final class NotOnFire_Plugin {

        public static function activate() {
            if ( is_multisite() ) {
                deactivate_plugins( plugin_basename( NOTONFIRE_PLUGIN_FILE ) );

                wp_die(
                    esc_html__( 'NotOnFire does not support WordPress multisite yet.', 'notonfire' ),
                    esc_html__( 'Plugin not activated', 'notonfire' ),
                    [ 'back_link' => true ]
                );
            }

            self::seed_defaults();
            NotOnFire_Loader::install( plugin_basename( NOTONFIRE_PLUGIN_FILE ) );

            if ( NotOnFire_Config::is_connected() ) {
                NotOnFire_Reporter::sync();
            }
        }

        public static function deactivate() {
            NotOnFire_Loader::remove();
            NotOnFire_Updater::forget();
        }

        /**
         * Takes the connection the dashboard wrote into defaults.php, but
         * only into empty settings: activating a re-uploaded copy never
         * overwrites a connection someone changed on the settings page.
         */
        private static function seed_defaults() {
            if ( array() !== NotOnFire_Config::settings() ) {
                return;
            }

            $defaults = include __DIR__ . '/defaults.php';
            if ( ! is_array( $defaults ) ) {
                return;
            }

            $settings = [];
            foreach ( [ 'server_url', 'site_id', 'site_token' ] as $key ) {
                if ( isset( $defaults[ $key ] ) && is_scalar( $defaults[ $key ] ) && '' !== trim( (string) $defaults[ $key ] ) ) {
                    $settings[ $key ] = trim( (string) $defaults[ $key ] );
                }
            }

            if ( array() !== $settings ) {
                NotOnFire_Config::save_settings( $settings );
            }
        }
    }
}

register_activation_hook( __FILE__, [ 'NotOnFire_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'NotOnFire_Plugin', 'deactivate' ] );

NotOnFire_Analytics::register();
NotOnFire_Updater::register();

if ( is_admin() ) {
    require_once __DIR__ . '/includes/class-notonfire-admin.php';
    NotOnFire_Admin::register();
}

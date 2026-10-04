<?php
/**
 * Offers new plugin versions through WordPress's own update screens.
 *
 * The plugin header's `Update URI` keeps WordPress.org from ever matching
 * this plugin by its slug, and makes WordPress ask the
 * `update_plugins_notonfire.systems` filter instead. The answer comes from
 * the signed dashboard endpoint, so the version a site is offered is the one
 * the dashboard serves, and the package link is a short-lived signed URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'NotOnFire_Updater', false ) ) {
    final class NotOnFire_Updater {

        const ENDPOINT = '/api/v1/wordpress/plugin-update';
        const UPDATE_HOST = 'notonfire.systems';
        const SLUG = 'notonfire';
        const CACHE_KEY = 'notonfire_update_info';
        const CACHE_TTL = 43200;
        const FAILURE_TTL = 3600;

        public static function register() {
            add_filter( 'update_plugins_' . self::UPDATE_HOST, [ __CLASS__, 'check' ], 10, 3 );
            add_filter( 'plugins_api', [ __CLASS__, 'details' ], 10, 3 );
            add_action( 'upgrader_process_complete', [ __CLASS__, 'after_upgrade' ], 10, 2 );
            add_action( 'load-update-core.php', [ __CLASS__, 'forget_on_force_check' ] );
        }

        /**
         * @param array|false $update
         * @param array       $plugin_data
         * @param string      $plugin_file
         */
        public static function check( $update, $plugin_data, $plugin_file ) {
            if ( plugin_basename( NOTONFIRE_PLUGIN_FILE ) !== $plugin_file ) {
                return $update;
            }

            $info = self::info();
            if ( null === $info ) {
                return $update;
            }

            return [
                'id' => self::UPDATE_HOST . '/' . self::SLUG,
                'slug' => self::SLUG,
                'plugin' => $plugin_file,
                'version' => $info['version'],
                'new_version' => $info['version'],
                'url' => 'https://' . self::UPDATE_HOST,
                'package' => $info['package'],
                'requires' => $info['requires'],
                'requires_php' => $info['requires_php'],
                'tested' => '',
                'icons' => [],
                'banners' => [],
                'translations' => [],
            ];
        }

        /**
         * Fills the "View details" dialog, which otherwise asks
         * WordPress.org about a plugin it does not have.
         */
        public static function details( $result, $action, $args ) {
            if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
                return $result;
            }

            $info = self::info();

            return (object) [
                'name' => 'NotOnFire',
                'slug' => self::SLUG,
                'version' => null !== $info ? $info['version'] : NotOnFire_Config::VERSION,
                'author' => '<a href="https://' . self::UPDATE_HOST . '">NotOnFire</a>',
                'homepage' => 'https://' . self::UPDATE_HOST,
                'requires' => null !== $info ? $info['requires'] : '',
                'requires_php' => null !== $info ? $info['requires_php'] : '',
                'download_link' => null !== $info ? $info['package'] : '',
                'sections' => [
                    'description' => esc_html__( 'Fatal-error reporting, WordPress update status and analytics for NotOnFire.', 'notonfire' ),
                ],
            ];
        }

        public static function after_upgrade( $upgrader, $options ) {
            $plugins = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? $options['plugins'] : [];

            if ( ! isset( $options['type'] ) || 'plugin' !== $options['type'] || ! in_array( plugin_basename( NOTONFIRE_PLUGIN_FILE ), $plugins, true ) ) {
                return;
            }

            delete_site_transient( self::CACHE_KEY );
            NotOnFire_Loader::install( plugin_basename( NOTONFIRE_PLUGIN_FILE ) );
        }

        /**
         * "Check again" on Dashboard → Updates asks the dashboard too.
         */
        public static function forget_on_force_check() {
            if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress core checks this request itself.
                delete_site_transient( self::CACHE_KEY );
            }
        }

        public static function forget() {
            delete_site_transient( self::CACHE_KEY );
        }

        /**
         * The release the dashboard offers, cached for 12 hours, or null when
         * it could not be asked.
         *
         * @return array{version:string,package:string,requires:string,requires_php:string}|null
         */
        private static function info() {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                return isset( $cached['version'] ) ? $cached : null;
            }

            $response = NotOnFire_Client::get( self::ENDPOINT );
            $info = '' === $response['error'] ? self::normalize( $response['body'] ) : null;

            set_site_transient( self::CACHE_KEY, null !== $info ? $info : [ 'failed' => true ], null !== $info ? self::CACHE_TTL : self::FAILURE_TTL );

            return $info;
        }

        private static function normalize( $body ) {
            $version = isset( $body['version'] ) ? (string) $body['version'] : '';
            $package = isset( $body['package'] ) ? (string) $body['package'] : '';
            $package_parts = parse_url( $package );

            if ( ! preg_match( '/^\d+(?:\.\d+){0,3}$/', $version )
                || ! is_array( $package_parts ) || ! isset( $package_parts['scheme'], $package_parts['host'] )
                || 'https' !== strtolower( $package_parts['scheme'] )
            ) {
                return null;
            }

            return [
                'version' => $version,
                'package' => $package,
                'requires' => isset( $body['requires'] ) ? sanitize_text_field( (string) $body['requires'] ) : '',
                'requires_php' => isset( $body['requires_php'] ) ? sanitize_text_field( (string) $body['requires_php'] ) : '',
            ];
        }
    }
}

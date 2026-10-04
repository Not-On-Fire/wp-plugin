<?php
/**
 * Where the plugin's settings and cached dashboard state live.
 *
 * Loaded by the must-use loader before regular plugins and the theme, so it
 * may only use what WordPress has available at that point: options,
 * transients and the HTTP API, never anything from wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'NotOnFire_Config', false ) ) {
    final class NotOnFire_Config {

        /**
         * The plugin version. The `Version:` header in notonfire.php and the
         * git tag are the same number; the dashboard reads this constant to
         * decide whether a site is behind.
         */
        const VERSION = '1.0.0';

        const SETTINGS_OPTION = 'notonfire_settings';
        const STATE_OPTION = 'notonfire_state';

        /**
         * Settings that a constant in wp-config.php or an environment
         * variable may pin, mapped to that constant's name.
         */
        const OVERRIDES = [
            'server_url' => 'WP_NOTONFIRE_SERVER_URL',
            'site_id' => 'WP_NOTONFIRE_SITE_ID',
            'site_token' => 'WP_NOTONFIRE_SITE_TOKEN',
        ];

        public static function server_url() {
            $server_url = rtrim( self::value( 'server_url' ), '/' );

            return self::is_valid_server_url( $server_url ) ? $server_url : '';
        }

        public static function site_id() {
            $site_id = self::value( 'site_id' );

            return ctype_digit( $site_id ) ? (int) $site_id : 0;
        }

        public static function site_token() {
            return self::value( 'site_token' );
        }

        public static function is_connected() {
            return '' !== self::server_url() && self::site_id() > 0 && '' !== self::site_token();
        }

        /**
         * Whether wp-config.php or the environment pins this setting, which
         * makes it read-only on the settings page.
         */
        public static function is_overridden( $key ) {
            return isset( self::OVERRIDES[ $key ] ) && '' !== self::override( self::OVERRIDES[ $key ] );
        }

        public static function exclude_editors() {
            $settings = self::settings();

            return ! empty( $settings['exclude_editors'] );
        }

        /**
         * @return array{server_url?:string,site_id?:string,site_token?:string,exclude_editors?:bool}
         */
        public static function settings() {
            $settings = get_option( self::SETTINGS_OPTION, [] );

            return is_array( $settings ) ? $settings : [];
        }

        public static function save_settings( array $settings ) {
            update_option( self::SETTINGS_OPTION, $settings, true );
        }

        /**
         * The configuration last synchronized from the dashboard, plus the
         * bookkeeping of when that happened.
         */
        public static function state() {
            $state = get_option( self::STATE_OPTION, [] );

            return is_array( $state ) ? $state : [];
        }

        public static function save_state( array $state ) {
            update_option( self::STATE_OPTION, $state, true );
        }

        public static function forget_state() {
            delete_option( self::STATE_OPTION );
        }

        public static function is_valid_server_url( $server_url ) {
            $parts = parse_url( (string) $server_url );

            return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] )
                && 'https' === strtolower( $parts['scheme'] )
                && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] )
                && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
        }

        /**
         * A wp-config.php constant or environment variable that is neither
         * one of the three pinnable settings nor stored, such as
         * WP_NOTONFIRE_DSN or WP_NOTONFIRE_ENVIRONMENT.
         */
        public static function override( $name ) {
            if ( defined( $name ) ) {
                $value = constant( $name );

                return is_scalar( $value ) ? trim( (string) $value ) : '';
            }

            $value = getenv( $name );

            return is_string( $value ) ? trim( $value ) : '';
        }

        public static function debug_log( $message ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[NotOnFire] ' . $message );
            }
        }

        private static function value( $key ) {
            if ( isset( self::OVERRIDES[ $key ] ) ) {
                $override = self::override( self::OVERRIDES[ $key ] );
                if ( '' !== $override ) {
                    return $override;
                }
            }

            $settings = self::settings();

            return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
        }
    }
}

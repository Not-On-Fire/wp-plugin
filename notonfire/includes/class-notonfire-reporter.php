<?php
/**
 * Early fatal-error reporting, the signed read-only monitoring endpoint, and
 * the configuration sync with the NotOnFire dashboard.
 *
 * The must-use loader the plugin installs requires this file before regular
 * plugins and the active theme load, which is what lets the reporter survive
 * a broken theme. Without the loader the plugin requires it itself: later in
 * the request, but still working.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-notonfire-config.php';
require_once __DIR__ . '/class-notonfire-client.php';
require_once __DIR__ . '/class-notonfire-transport.php';

if ( ! class_exists( 'NotOnFire_Reporter', false ) ) {
    final class NotOnFire_Reporter {

        const CONFIG_ENDPOINT = '/api/v1/wordpress/config';
        const REST_NAMESPACE = 'notonfire/v1';
        const REST_ROUTE = '/monitoring';
        const CONFIG_SYNC_INTERVAL = 3600;
        const CONFIG_RETRY_INTERVAL = 300;
        const NONCE_TTL = 600;
        const MAX_CLOCK_SKEW = 300;
        const ERROR_TRACKING_STATES = [ 'active', 'disabled', 'provisioning' ];

        private static $bootstrapped = false;
        private static $capturing = false;
        private static $reserved_memory = '';

        public static function bootstrap() {
            if ( self::$bootstrapped ) {
                return;
            }

            self::$bootstrapped = true;
            self::refresh_transport();
            self::$reserved_memory = str_repeat( 'R', 512 * 1024 );

            register_shutdown_function( [ __CLASS__, 'capture_shutdown_error' ] );
            add_action( 'rest_api_init', [ __CLASS__, 'register_monitoring_route' ] );

            // WordPress loads themes before init. Synchronize while the loader
            // runs so a broken theme cannot prevent the first DSN.
            self::maybe_sync();
        }

        public static function capture_shutdown_error() {
            self::$reserved_memory = '';

            if ( self::$capturing ) {
                return;
            }

            $error = error_get_last();
            if ( ! is_array( $error ) || ! isset( $error['type'] ) || ! self::is_fatal_error_type( (int) $error['type'] ) ) {
                NotOnFire_Transport::maybe_replay();

                return;
            }

            if ( ! NotOnFire_Transport::is_ready() ) {
                NotOnFire_Config::debug_log( 'Fatal error was not reported because no managed DSN is cached.' );

                return;
            }

            self::$capturing = true;

            $event_id = self::event_id();
            $event_json = json_encode(
                self::build_event( $event_id, $error ),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if ( ! is_string( $event_json ) ) {
                return;
            }

            $status = NotOnFire_Transport::send( $event_id, $event_json );

            if ( NotOnFire_Transport::should_retry( $status ) ) {
                NotOnFire_Config::debug_log( 'The fatal event was not received (HTTP ' . $status . '). Keeping it for a later request.' );
                NotOnFire_Transport::spool( $event_id, $event_json );
            } elseif ( $status < 200 || $status >= 300 ) {
                NotOnFire_Config::debug_log( 'The fatal event was rejected (HTTP ' . $status . ').' );
            }
        }

        /**
         * Sends one event straight away, so the person on the settings page
         * can see that reporting works. Returns the HTTP status, 0 when
         * nothing answered, or -1 when there is no DSN to send to.
         */
        public static function send_test_event() {
            $event_id = self::event_id();
            $event = self::build_event( $event_id, [
                'type' => E_USER_ERROR,
                'message' => 'This is a test event from the NotOnFire plugin.',
                'file' => __FILE__,
                'line' => __LINE__,
            ] );
            $event['level'] = 'error';
            $event['exception']['values'][0]['type'] = 'NotOnFire_TestEvent';
            $event['exception']['values'][0]['mechanism'] = [ 'type' => 'wp-notonfire.test', 'handled' => true ];

            $event_json = json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

            return is_string( $event_json ) ? NotOnFire_Transport::send( $event_id, $event_json ) : -1;
        }

        public static function register_monitoring_route() {
            register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [ __CLASS__, 'monitoring_status' ],
                'permission_callback' => [ __CLASS__, 'authorize_monitoring_request' ],
            ] );
        }

        public static function authorize_monitoring_request( WP_REST_Request $request ) {
            $site_id = NotOnFire_Config::site_id();
            $site_token = NotOnFire_Config::site_token();
            $request_site_id = $request->get_header( 'X-NotOnFire-Site-ID' );
            $timestamp = $request->get_header( 'X-NotOnFire-Timestamp' );
            $nonce = $request->get_header( 'X-NotOnFire-Nonce' );
            $signature = $request->get_header( 'X-NotOnFire-Signature' );

            if ( $site_id <= 0 || '' === $site_token
                || ! is_string( $request_site_id ) || ! ctype_digit( $request_site_id )
                || ! is_string( $timestamp ) || ! ctype_digit( $timestamp )
                || ! is_string( $nonce ) || '' === $nonce
                || ! is_string( $signature ) || 64 !== strlen( $signature ) || ! ctype_xdigit( $signature )
            ) {
                return new WP_Error( 'notonfire_unauthorized', 'Invalid NotOnFire signature.', [ 'status' => 401 ] );
            }

            if ( (int) $request_site_id !== $site_id || abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
                return new WP_Error( 'notonfire_unauthorized', 'Invalid NotOnFire signature.', [ 'status' => 401 ] );
            }

            $expected = NotOnFire_Client::signature(
                $timestamp,
                $nonce,
                $request->get_method(),
                $request->get_route(),
                (string) $request->get_body(),
                $site_token
            );

            if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
                return new WP_Error( 'notonfire_unauthorized', 'Invalid NotOnFire signature.', [ 'status' => 401 ] );
            }

            $nonce_key = 'wp_notonfire_nonce_' . hash( 'sha256', $request_site_id . ':' . $nonce );
            if ( false !== get_transient( $nonce_key ) ) {
                return new WP_Error( 'notonfire_replay', 'NotOnFire nonce has already been used.', [ 'status' => 401 ] );
            }

            set_transient( $nonce_key, 1, self::NONCE_TTL );

            return true;
        }

        public static function monitoring_status( WP_REST_Request $request ) {
            global $wp_version;

            self::note_dashboard_revision( $request->get_header( 'X-NotOnFire-Config-Revision' ) );

            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $installed_plugins = get_plugins();
            $plugin_updates = get_site_transient( 'update_plugins' );
            $plugin_update_data = isset( $plugin_updates->response ) && is_array( $plugin_updates->response )
                ? $plugin_updates->response
                : [];
            $plugin_update_names = [];
            $plugin_update_count = 0;

            foreach ( $plugin_update_data as $plugin_file => $update ) {
                if ( ! isset( $installed_plugins[ $plugin_file ] ) ) {
                    continue;
                }

                $plugin_update_count++;
                $name = isset( $installed_plugins[ $plugin_file ]['Name'] )
                    ? wp_strip_all_tags( (string) $installed_plugins[ $plugin_file ]['Name'] )
                    : '';

                if ( '' !== $name ) {
                    $plugin_update_names[] = $name;
                }
            }

            $installed_themes = wp_get_themes();
            $theme_updates = get_site_transient( 'update_themes' );
            $theme_update_data = isset( $theme_updates->response ) && is_array( $theme_updates->response )
                ? $theme_updates->response
                : [];
            $theme_update_names = [];
            $theme_update_count = 0;

            foreach ( $theme_update_data as $stylesheet => $update ) {
                if ( ! isset( $installed_themes[ $stylesheet ] ) ) {
                    continue;
                }

                $theme_update_count++;
                $name = wp_strip_all_tags( (string) $installed_themes[ $stylesheet ]->get( 'Name' ) );
                if ( '' !== $name ) {
                    $theme_update_names[] = $name;
                }
            }

            $core_update_version = self::core_update_version();

            $response = new WP_REST_Response( [
                'version' => (string) $wp_version,
                'core_updates' => '' === $core_update_version ? 0 : 1,
                'core_update_version' => $core_update_version,
                'plugin_updates' => $plugin_update_count,
                'theme_updates' => $theme_update_count,
                'plugin_update_names' => array_values( array_unique( $plugin_update_names ) ),
                'theme_update_names' => array_values( array_unique( $theme_update_names ) ),
            ], 200 );
            $response->header( 'Cache-Control', 'no-store, private' );

            return $response;
        }

        public static function maybe_sync() {
            if ( ! NotOnFire_Config::is_connected() ) {
                return;
            }

            $state = NotOnFire_Config::state();
            $last_attempt_at = isset( $state['last_attempt_at'] ) ? (int) $state['last_attempt_at'] : 0;
            $last_success_at = isset( $state['last_success_at'] ) ? (int) $state['last_success_at'] : 0;
            $sync_interval = 'provisioning' === self::error_tracking_state( $state )
                ? self::CONFIG_RETRY_INTERVAL
                : self::CONFIG_SYNC_INTERVAL;
            $now = time();

            if ( empty( $state['stale'] ) && $last_success_at > 0 && $last_success_at > $now - $sync_interval ) {
                return;
            }

            if ( $last_attempt_at > $now - self::CONFIG_RETRY_INTERVAL ) {
                return;
            }

            self::sync();
        }

        /**
         * Fetches this site's configuration from the dashboard now, whatever
         * the interval says.
         *
         * @return string An empty string on success, otherwise an error code:
         *                not_configured, unreachable, unauthorized,
         *                http_error or invalid_response.
         */
        public static function sync() {
            $state = NotOnFire_Config::state();
            $state['last_attempt_at'] = time();
            NotOnFire_Config::save_state( $state );

            $response = NotOnFire_Client::get( self::CONFIG_ENDPOINT );
            $configuration = '' === $response['error'] ? self::normalize_configuration( $response['body'] ) : null;
            $error = '' !== $response['error'] ? $response['error'] : ( null === $configuration ? 'invalid_response' : '' );

            $state = NotOnFire_Config::state();

            if ( '' !== $error ) {
                $state['last_error'] = $error;
                NotOnFire_Config::save_state( $state );

                return $error;
            }

            NotOnFire_Config::save_state( array_merge( $state, $configuration, [
                'last_success_at' => time(),
                'last_error' => '',
                'stale' => false,
            ] ) );

            self::refresh_transport();

            // Error tracking was switched off, so kept events must not be sent later.
            if ( 'disabled' === $configuration['error_tracking']['state'] && ! NotOnFire_Transport::is_ready() ) {
                NotOnFire_Transport::discard_spool();
            }

            return '';
        }

        /**
         * Forgets everything synchronized for the previous connection and
         * the events kept for it, then connects again. Called when the
         * Server URL, Site ID or Site Token change.
         */
        public static function reconnect() {
            NotOnFire_Config::forget_state();
            NotOnFire_Transport::discard_spool();
            self::refresh_transport();

            return self::sync();
        }

        public static function error_tracking_state( ?array $state = null ) {
            $state = null === $state ? NotOnFire_Config::state() : $state;

            return isset( $state['error_tracking']['state'] ) ? (string) $state['error_tracking']['state'] : '';
        }

        /**
         * The analytics tag attributes, or an empty array when analytics is
         * off for this application.
         *
         * @return array<string,string>
         */
        public static function analytics_attributes() {
            $state = NotOnFire_Config::state();

            return ! empty( $state['analytics']['enabled'] ) && isset( $state['analytics']['attributes'] ) && is_array( $state['analytics']['attributes'] )
                ? $state['analytics']['attributes']
                : [];
        }

        /**
         * The dashboard names the revision of this site's configuration on
         * every signed poll. A different one means something was switched in
         * the dashboard, so the next request synchronizes instead of waiting
         * out the interval. Synchronizing here would make the dashboard's
         * poll wait for a request back to itself.
         */
        private static function note_dashboard_revision( $revision ) {
            if ( ! is_string( $revision ) || '' === $revision ) {
                return;
            }

            $state = NotOnFire_Config::state();
            if ( ! empty( $state['stale'] ) || ( isset( $state['revision'] ) && hash_equals( (string) $state['revision'], $revision ) ) ) {
                return;
            }

            $state['stale'] = true;
            NotOnFire_Config::save_state( $state );
        }

        /**
         * Accepts only the shape the dashboard sends, so nothing malformed
         * ends up printed into a page or used as a send target.
         */
        private static function normalize_configuration( $body ) {
            if ( ! is_array( $body ) || ! isset( $body['error_tracking'] ) || ! is_array( $body['error_tracking'] ) ) {
                return null;
            }

            $error_tracking_state = isset( $body['error_tracking']['state'] ) ? sanitize_key( (string) $body['error_tracking']['state'] ) : '';
            $dsn = isset( $body['error_tracking']['dsn'] ) && is_string( $body['error_tracking']['dsn'] ) ? trim( $body['error_tracking']['dsn'] ) : '';

            if ( ! in_array( $error_tracking_state, self::ERROR_TRACKING_STATES, true ) ) {
                return null;
            }

            if ( 'active' === $error_tracking_state && '' === NotOnFire_Transport::envelope_endpoint( $dsn ) ) {
                return null;
            }

            $application = isset( $body['application'] ) && is_array( $body['application'] ) ? $body['application'] : [];
            $settings_url = isset( $application['settings_url'] ) ? (string) $application['settings_url'] : '';
            $latest_version = isset( $body['plugin']['latest_version'] ) ? (string) $body['plugin']['latest_version'] : '';

            return [
                'error_tracking' => [
                    'state' => $error_tracking_state,
                    'dsn' => 'active' === $error_tracking_state ? $dsn : '',
                ],
                'analytics' => self::normalize_analytics( isset( $body['analytics'] ) ? $body['analytics'] : null ),
                'application' => [
                    'name' => isset( $application['name'] ) ? sanitize_text_field( (string) $application['name'] ) : '',
                    'domain' => isset( $application['domain'] ) ? sanitize_text_field( (string) $application['domain'] ) : '',
                    'settings_url' => self::is_https_url( $settings_url ) ? $settings_url : '',
                ],
                'latest_version' => preg_match( '/^\d+(?:\.\d+){0,3}$/', $latest_version ) ? $latest_version : '',
                'revision' => isset( $body['revision'] ) && is_string( $body['revision'] ) ? substr( $body['revision'], 0, 64 ) : '',
            ];
        }

        /**
         * The tag's attributes come from the dashboard as they are, so the
         * plugin and the dashboard snippet cannot drift apart. Names must be
         * plain attribute names and the script must load over HTTPS.
         */
        private static function normalize_analytics( $analytics ) {
            $disabled = [ 'enabled' => false, 'attributes' => [] ];

            if ( ! is_array( $analytics ) || empty( $analytics['enabled'] ) || ! isset( $analytics['attributes'] ) || ! is_array( $analytics['attributes'] ) ) {
                return $disabled;
            }

            $attributes = [];
            foreach ( $analytics['attributes'] as $name => $value ) {
                if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9-]*$/', $name ) || ! is_scalar( $value ) ) {
                    continue;
                }

                $attributes[ $name ] = (string) $value;
            }

            if ( ! isset( $attributes['src'] ) || ! self::is_https_url( $attributes['src'] ) || empty( $attributes['data-website-id'] ) ) {
                return $disabled;
            }

            return [ 'enabled' => true, 'attributes' => $attributes ];
        }

        private static function is_https_url( $url ) {
            $parts = parse_url( (string) $url );

            return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( $parts['scheme'] );
        }

        private static function refresh_transport() {
            $dsn = NotOnFire_Config::override( 'WP_NOTONFIRE_DSN' );

            if ( '' === $dsn ) {
                $state = NotOnFire_Config::state();
                $dsn = isset( $state['error_tracking']['dsn'] ) ? (string) $state['error_tracking']['dsn'] : '';
            }

            NotOnFire_Transport::configure( $dsn );
        }

        /**
         * The core version WordPress is offering, or an empty string when it
         * is already current.
         *
         * Read straight off the `update_core` transient rather than through
         * `get_preferred_from_update_core()`, which lives in
         * wp-admin/includes/update.php and is not loaded on a REST request.
         * The transient carries one entry per offer — an `upgrade` row for a
         * newer release, `latest` for the installed one, and `development`
         * for a nightly — so the first `upgrade` row is the pending update.
         *
         * An absent transient means `wp_version_check()` has not run yet, not
         * that the site is current. That reads the same as up to date here,
         * which is the same assumption the plugin and theme counts make.
         */
        private static function core_update_version() {
            $core = get_site_transient( 'update_core' );

            if ( ! isset( $core->updates ) || ! is_array( $core->updates ) ) {
                return '';
            }

            foreach ( $core->updates as $update ) {
                if ( ! isset( $update->response ) || 'upgrade' !== $update->response ) {
                    continue;
                }

                if ( isset( $update->current ) && is_string( $update->current ) && '' !== $update->current ) {
                    return $update->current;
                }
            }

            return '';
        }

        private static function build_event( $event_id, array $error ) {
            $message = isset( $error['message'] ) ? (string) $error['message'] : 'Fatal PHP error';
            $file = isset( $error['file'] ) ? (string) $error['file'] : '';
            $line = isset( $error['line'] ) ? max( 0, (int) $error['line'] ) : 0;

            return [
                'event_id' => $event_id,
                'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
                'platform' => 'php',
                'level' => 'fatal',
                'logger' => 'wp-notonfire',
                'release' => 'wp-notonfire@' . NotOnFire_Config::VERSION,
                'environment' => self::environment(),
                'sdk' => [ 'name' => 'wp-notonfire', 'version' => NotOnFire_Config::VERSION ],
                'exception' => [
                    'values' => [
                        [
                            'type' => self::exception_type( (int) $error['type'], $message ),
                            'value' => self::sanitize_message( $message ),
                            'mechanism' => [
                                'type' => 'wp-notonfire.shutdown',
                                'handled' => false,
                            ],
                            'stacktrace' => [
                                'frames' => [
                                    [
                                        'filename' => self::normalize_path( $file ),
                                        'lineno' => $line,
                                        'in_app' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        private static function sanitize_message( $message ) {
            $message = preg_replace( '/\s+Stack trace:.*$/s', '', (string) $message );
            $message = self::normalize_path( is_string( $message ) ? $message : '' );
            $message = preg_replace( '~https?://[^\s\'"<>]+~iu', '[url]', $message );
            $message = preg_replace( '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[email]', $message );
            $message = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/u', '[ip]', $message );
            $message = preg_replace( '/\b(?:[A-F0-9]{1,4}:){2,7}[A-F0-9]{0,4}\b/iu', '[ip]', $message );
            $message = preg_replace( '/\b[A-F0-9]{8}-[A-F0-9]{4}-[1-5][A-F0-9]{3}-[89AB][A-F0-9]{3}-[A-F0-9]{12}\b/iu', '[id]', $message );
            $message = preg_replace( '/\b(password|passwd|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/iu', '$1=[redacted]', $message );
            $message = preg_replace( '/\bSQLSTATE\[[A-Z0-9]+\].*$/iu', 'SQLSTATE [database message redacted]', $message );
            $message = preg_replace( '/\'[^\'\r\n]*\'|"[^"\r\n]*"/u', '[value]', $message );
            $message = preg_replace( '/\b\d{4,}\b/u', '#', $message );
            $message = preg_replace( '/[\r\n\t]+/u', ' ', is_string( $message ) ? $message : '' );
            $message = trim( strip_tags( is_string( $message ) ? $message : '' ) );

            if ( '' === $message ) {
                return 'Fatal PHP error';
            }

            return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
        }

        private static function normalize_path( $value ) {
            $value = str_replace( '\\', '/', (string) $value );
            $replacements = [];

            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $replacements[str_replace( '\\', '/', WP_CONTENT_DIR )] = '[WP_CONTENT]';
            }
            if ( defined( 'ABSPATH' ) ) {
                $replacements[str_replace( '\\', '/', ABSPATH )] = '[ABSPATH]/';
            }

            uksort( $replacements, function ( $left, $right ) {
                return strlen( $right ) - strlen( $left );
            } );

            return str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
        }

        private static function exception_type( $error_type, $message ) {
            if ( preg_match( '/Uncaught\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*):/u', (string) $message, $matches ) ) {
                return $matches[1];
            }

            $types = [
                E_ERROR => 'E_ERROR',
                E_PARSE => 'E_PARSE',
                E_CORE_ERROR => 'E_CORE_ERROR',
                E_COMPILE_ERROR => 'E_COMPILE_ERROR',
                E_USER_ERROR => 'E_USER_ERROR',
                E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            ];

            return isset( $types[ $error_type ] ) ? $types[ $error_type ] : 'FatalError';
        }

        private static function is_fatal_error_type( $error_type ) {
            return in_array( $error_type, [
                E_ERROR,
                E_PARSE,
                E_CORE_ERROR,
                E_COMPILE_ERROR,
                E_USER_ERROR,
                E_RECOVERABLE_ERROR,
            ], true );
        }

        private static function environment() {
            $environment = NotOnFire_Config::override( 'WP_NOTONFIRE_ENVIRONMENT' );
            if ( '' === $environment ) {
                $environment = NotOnFire_Config::override( 'WP_ENVIRONMENT_TYPE' );
            }
            if ( '' === $environment ) {
                return 'production';
            }

            $environment = preg_replace( '/[^a-z0-9._-]/i', '-', $environment );

            return is_string( $environment ) && '' !== $environment ? substr( $environment, 0, 64 ) : 'production';
        }

        private static function event_id() {
            try {
                return bin2hex( random_bytes( 16 ) );
            } catch ( Exception $exception ) {
                return md5( uniqid( 'wp-notonfire-', true ) );
            }
        }
    }

    NotOnFire_Reporter::bootstrap();
}

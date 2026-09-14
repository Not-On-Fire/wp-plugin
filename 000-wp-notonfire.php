<?php
/**
 * Plugin Name: NotOnFire WordPress Monitor
 * Plugin URI:  https://notonfire.systems
 * Description: Early fatal-error reporting and authenticated WordPress update status for NotOnFire.
 * Version:     0.4.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 *
 * Install this file as:
 * wp-content/mu-plugins/000-wp-notonfire.php
 *
 * The NotOnFire dashboard offers this file as a download with the site
 * credentials already written into the block below, so a plain upload is
 * enough. Configuring it by hand works as well: define the same constants in
 * wp-config.php before WordPress is loaded, or export them as environment
 * variables.
 *
 * define( 'WP_NOTONFIRE_SERVER_URL', 'https://dashboard.example' );
 * define( 'WP_NOTONFIRE_SITE_ID', 12345 );
 * define( 'WP_NOTONFIRE_SITE_TOKEN', 'replace-with-the-site-token' );
 *
 * The managed error-tracking DSN is synchronized from NotOnFire and cached in
 * WordPress. WP_NOTONFIRE_DSN may be defined to override it explicitly.
 *
 * A fatal error that cannot reach GlitchTip is kept in
 * wp-content/notonfire-spool for up to 24 hours and sent by a later request.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* NotOnFire credentials: begin */
/* The dashboard download replaces this block. Left empty the plugin reads the
   same constants from wp-config.php or the environment instead. */
/* NotOnFire credentials: end */

if ( ! class_exists( 'WP_NotOnFire_Monitor', false ) ) {
    final class WP_NotOnFire_Monitor {

        const VERSION = '0.4.0';
        const OPTION_KEY = 'wp_notonfire_monitor_state';
        const CONFIG_ENDPOINT = '/api/v1/wordpress/error-tracking-config';
        const REST_NAMESPACE = 'notonfire/v1';
        const REST_ROUTE = '/monitoring';
        const CONFIG_SYNC_INTERVAL = 21600;
        const CONFIG_RETRY_INTERVAL = 300;
        const NONCE_TTL = 600;
        const MAX_CLOCK_SKEW = 300;
        const SPOOL_DIRECTORY = 'notonfire-spool';
        const SPOOL_GUARD = "<?php exit; ?>\n";
        const SPOOL_MAX_EVENTS = 50;
        const SPOOL_MAX_AGE = 86400;
        const SPOOL_REPLAY_BATCH = 5;
        const SPOOL_REPLAY_INTERVAL = 60;

        private static $capturing = false;
        private static $dsn = '';
        private static $envelope_endpoint = '';
        private static $reserved_memory = '';

        public static function bootstrap() {
            self::$dsn = self::configured_dsn();
            self::$envelope_endpoint = self::envelope_endpoint( self::$dsn );
            self::$reserved_memory = str_repeat( 'R', 512 * 1024 );

            register_shutdown_function( [ __CLASS__, 'capture_shutdown_error' ] );
            add_action( 'rest_api_init', [ __CLASS__, 'register_monitoring_route' ] );

            // WordPress loads themes before init. Synchronize while this MU
            // plugin is loading so a broken theme cannot prevent the first DSN.
            self::maybe_sync_configuration();
        }

        public static function capture_shutdown_error() {
            self::$reserved_memory = '';

            if ( self::$capturing ) {
                return;
            }

            $error = error_get_last();
            if ( ! is_array( $error ) || ! isset( $error['type'] ) || ! self::is_fatal_error_type( (int) $error['type'] ) ) {
                self::maybe_replay_spool();

                return;
            }

            if ( '' === self::$envelope_endpoint ) {
                self::debug_log( 'Fatal error was not reported because no managed DSN is cached.' );

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

            $status = self::send_event( $event_id, $event_json );

            if ( self::should_retry( $status ) ) {
                self::debug_log( 'GlitchTip did not receive the fatal event (HTTP ' . $status . '). Keeping it for a later request.' );
                self::spool_event( $event_id, $event_json );
            } elseif ( $status < 200 || $status >= 300 ) {
                self::debug_log( 'GlitchTip rejected the fatal event (HTTP ' . $status . ').' );
            }
        }

        public static function register_monitoring_route() {
            register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [ __CLASS__, 'monitoring_status' ],
                'permission_callback' => [ __CLASS__, 'authorize_monitoring_request' ],
            ] );
        }

        public static function authorize_monitoring_request( WP_REST_Request $request ) {
            $site_id = self::site_id();
            $site_token = self::site_token();
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

            $canonical = implode( "\n", [
                $timestamp,
                $nonce,
                strtoupper( $request->get_method() ),
                $request->get_route(),
                hash( 'sha256', (string) $request->get_body() ),
            ] );
            $expected = hash_hmac( 'sha256', $canonical, $site_token );

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
         * which is the same assumption the plugin and theme counts above
         * already make.
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

        public static function monitoring_status() {
            global $wp_version;

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

        public static function maybe_sync_configuration() {
            if ( '' === self::server_url() || self::site_id() <= 0 || '' === self::site_token() ) {
                return;
            }

            $state = self::state();
            $last_attempt_at = isset( $state['last_attempt_at'] ) ? (int) $state['last_attempt_at'] : 0;
            $last_success_at = isset( $state['last_success_at'] ) ? (int) $state['last_success_at'] : 0;
            $configuration_state = isset( $state['state'] ) ? (string) $state['state'] : 'pending';
            $sync_interval = 'provisioning' === $configuration_state
                ? self::CONFIG_RETRY_INTERVAL
                : self::CONFIG_SYNC_INTERVAL;
            $now = time();

            if ( $last_success_at > 0 && $last_success_at > $now - $sync_interval ) {
                return;
            }

            if ( $last_attempt_at > $now - self::CONFIG_RETRY_INTERVAL ) {
                return;
            }

            $state['last_attempt_at'] = $now;
            self::save_state( $state );

            $response = self::request_configuration();
            if ( ! is_array( $response ) || ! isset( $response['state'] ) ) {
                return;
            }

            $configuration_state = sanitize_key( (string) $response['state'] );
            $dsn = isset( $response['dsn'] ) ? trim( (string) $response['dsn'] ) : '';

            if ( 'active' === $configuration_state && '' === self::envelope_endpoint( $dsn ) ) {
                return;
            }

            if ( ! in_array( $configuration_state, [ 'active', 'disabled', 'provisioning' ], true ) ) {
                return;
            }

            $state['state'] = $configuration_state;
            $state['dsn'] = 'active' === $configuration_state ? $dsn : '';
            $state['last_success_at'] = $now;
            self::save_state( $state );

            self::$dsn = self::configured_dsn();
            self::$envelope_endpoint = self::envelope_endpoint( self::$dsn );

            // Error tracking was switched off, so kept events must not be sent later.
            if ( 'disabled' === $configuration_state && '' === self::$envelope_endpoint ) {
                self::discard_spool();
            }
        }

        private static function request_configuration() {
            $path = self::CONFIG_ENDPOINT;
            $timestamp = (string) time();
            $nonce = self::nonce();
            $signature = hash_hmac( 'sha256', implode( "\n", [
                $timestamp,
                $nonce,
                'GET',
                $path,
                hash( 'sha256', '' ),
            ] ), self::site_token() );

            $response = wp_remote_get( self::server_url() . $path, [
                'timeout' => 10,
                'redirection' => 0,
                'sslverify' => true,
                'headers' => [
                    'Accept' => 'application/json',
                    'X-NotOnFire-Site-ID' => (string) self::site_id(),
                    'X-NotOnFire-Timestamp' => $timestamp,
                    'X-NotOnFire-Nonce' => $nonce,
                    'X-NotOnFire-Signature' => $signature,
                    'User-Agent' => 'WP-NotOnFire/' . self::VERSION,
                ],
            ] );

            if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                return null;
            }

            $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

            return is_array( $body ) ? $body : null;
        }

        private static function configured_dsn() {
            $dsn = self::configuration_value( 'WP_NOTONFIRE_DSN' );
            if ( '' !== $dsn ) {
                return '' !== self::envelope_endpoint( $dsn ) ? $dsn : '';
            }

            $state = self::state();
            $dsn = isset( $state['dsn'] ) ? trim( (string) $state['dsn'] ) : '';

            return '' !== self::envelope_endpoint( $dsn ) ? $dsn : '';
        }

        private static function server_url() {
            $server_url = rtrim( self::configuration_value( 'WP_NOTONFIRE_SERVER_URL' ), '/' );
            $parts = parse_url( $server_url );

            if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] )
                || 'https' !== strtolower( $parts['scheme'] )
                || isset( $parts['query'] ) || isset( $parts['fragment'] )
            ) {
                return '';
            }

            return $server_url;
        }

        private static function site_id() {
            $site_id = self::configuration_value( 'WP_NOTONFIRE_SITE_ID' );

            return ctype_digit( $site_id ) ? (int) $site_id : 0;
        }

        private static function site_token() {
            return self::configuration_value( 'WP_NOTONFIRE_SITE_TOKEN' );
        }

        private static function configuration_value( $name ) {
            if ( defined( $name ) ) {
                $value = constant( $name );

                return is_scalar( $value ) ? trim( (string) $value ) : '';
            }

            $value = getenv( $name );

            return is_string( $value ) ? trim( $value ) : '';
        }

        private static function state() {
            $state = get_option( self::OPTION_KEY, [] );

            return is_array( $state ) ? $state : [];
        }

        private static function save_state( array $state ) {
            update_option( self::OPTION_KEY, $state, false );
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
                'release' => 'wp-notonfire@' . self::VERSION,
                'environment' => self::environment(),
                'sdk' => [ 'name' => 'wp-notonfire', 'version' => self::VERSION ],
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

        private static function envelope_endpoint( $dsn ) {
            if ( ! is_string( $dsn ) || '' === trim( $dsn ) ) {
                return '';
            }

            $parts = parse_url( trim( $dsn ) );
            if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['user'], $parts['path'] ) ) {
                return '';
            }

            if ( 'https' !== strtolower( $parts['scheme'] ) || '' === $parts['host'] || '' === $parts['user']
                || isset( $parts['query'] ) || isset( $parts['fragment'] )
            ) {
                return '';
            }

            $segments = explode( '/', trim( $parts['path'], '/' ) );
            $project_id = array_pop( $segments );
            if ( ! is_string( $project_id ) || ! ctype_digit( $project_id ) ) {
                return '';
            }

            $base_path = empty( $segments ) ? '' : '/' . implode( '/', $segments );
            $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

            return 'https://' . $parts['host'] . $port . $base_path . '/api/' . $project_id . '/envelope/';
        }

        private static function send_envelope( $endpoint, $body ) {
            $headers = [
                'Content-Type: application/x-sentry-envelope',
                'X-Sentry-Auth: ' . self::sentry_auth_header(),
                'User-Agent: WP-NotOnFire/' . self::VERSION,
                'Content-Length: ' . strlen( $body ),
            ];

            if ( function_exists( 'curl_init' ) ) {
                $handle = curl_init( $endpoint );
                if ( false !== $handle ) {
                    $options = [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $body,
                        CURLOPT_HTTPHEADER => $headers,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CONNECTTIMEOUT => 1,
                        CURLOPT_TIMEOUT => 3,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                    ];

                    if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
                        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                    }

                    curl_setopt_array( $handle, $options );
                    curl_exec( $handle );
                    $status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
                    curl_close( $handle );

                    return $status;
                }
            }

            $context = stream_context_create( [
                'http' => [
                    'method' => 'POST',
                    'header' => implode( "\r\n", $headers ),
                    'content' => $body,
                    'timeout' => 3,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ] );

            @file_get_contents( $endpoint, false, $context );

            if ( function_exists( 'http_get_last_response_headers' ) ) {
                $response_headers = http_get_last_response_headers();
            } else {
                $defined_variables = get_defined_vars();
                $response_headers = isset( $defined_variables['http_response_header'] ) && is_array( $defined_variables['http_response_header'] )
                    ? $defined_variables['http_response_header']
                    : [];
            }

            if ( is_array( $response_headers ) ) {
                foreach ( array_reverse( $response_headers ) as $header ) {
                    if ( preg_match( '/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches ) ) {
                        return (int) $matches[1];
                    }
                }
            }

            return 0;
        }

        /**
         * Sends one fatal event as a Sentry envelope. Returns the HTTP status,
         * 0 when nothing answered, or -1 when the envelope could not be built.
         */
        private static function send_event( $event_id, $event_json ) {
            $envelope_header = json_encode(
                [ 'event_id' => $event_id, 'dsn' => self::$dsn ],
                JSON_UNESCAPED_SLASHES
            );
            $item_header = json_encode( [
                'type' => 'event',
                'length' => strlen( $event_json ),
                'content_type' => 'application/json',
            ], JSON_UNESCAPED_SLASHES );

            if ( ! is_string( $envelope_header ) || ! is_string( $item_header ) ) {
                return -1;
            }

            return self::send_envelope(
                self::$envelope_endpoint,
                $envelope_header . "\n" . $item_header . "\n" . $event_json
            );
        }

        /**
         * Only a malformed or oversized event is dropped. Everything else is
         * kept until it ages out, including an unreachable host, every 5xx and
         * a 401: dropping loses the error for good, and a rotated key heals
         * once the next configuration sync brings the new DSN.
         */
        private static function should_retry( $status ) {
            if ( $status < 0 || ( $status >= 200 && $status < 300 ) ) {
                return false;
            }

            return ! in_array( $status, [ 400, 413, 422 ], true );
        }

        /**
         * Keeps a fatal event that did not reach GlitchTip until a later
         * request can send it.
         *
         * Files rather than an option, because the database may be what
         * failed. Only the event is stored and the envelope is rebuilt at
         * replay, so a rotated DSN does not strand it. wp-content is usually
         * served, so the directory is private to the PHP user, closed to
         * Apache, has no listing, and every file starts with an exit guard.
         */
        private static function spool_event( $event_id, $event_json ) {
            $directory = self::spool_directory();
            if ( '' === $directory || ! ctype_xdigit( (string) $event_id ) ) {
                return;
            }

            if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
                self::debug_log( 'The fatal event was lost because ' . $directory . ' could not be created.' );

                return;
            }

            if ( ! is_file( $directory . '/index.php' ) ) {
                @file_put_contents( $directory . '/index.php', "<?php\n// Silence is golden.\n" );
            }
            if ( ! is_file( $directory . '/.htaccess' ) ) {
                @file_put_contents( $directory . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
            }

            if ( count( self::spooled_files( $directory ) ) >= self::SPOOL_MAX_EVENTS ) {
                self::debug_log( 'The fatal event was lost because ' . self::SPOOL_MAX_EVENTS . ' events are already waiting.' );

                return;
            }

            // Written aside and renamed, so a replay never reads half a file.
            $temporary = $directory . '/event-' . $event_id . '.tmp';
            if ( false === @file_put_contents( $temporary, self::SPOOL_GUARD . $event_json, LOCK_EX ) ) {
                self::debug_log( 'The fatal event was lost because it could not be written to ' . $directory . '.' );

                return;
            }

            @chmod( $temporary, 0600 );
            if ( ! @rename( $temporary, $directory . '/event-' . $event_id . '.php' ) ) {
                @unlink( $temporary );
                self::debug_log( 'The fatal event was lost because it could not be written to ' . $directory . '.' );
            }
        }

        /**
         * Sends up to SPOOL_REPLAY_BATCH kept events, oldest first, and stops
         * at the first one GlitchTip still does not take.
         *
         * Runs at the end of requests without a fatal error, at most once per
         * SPOOL_REPLAY_INTERVAL. The lock file's mtime is the throttle, so a
         * site with nothing kept pays one stat per request, and flock keeps
         * two requests from sending the same event.
         */
        private static function maybe_replay_spool() {
            if ( '' === self::$envelope_endpoint ) {
                return;
            }

            $directory = self::spool_directory();
            if ( '' === $directory || ! is_dir( $directory ) ) {
                return;
            }

            $lock_path = $directory . '/replay.lock';
            $last_replay_at = @filemtime( $lock_path );
            if ( false !== $last_replay_at && $last_replay_at > time() - self::SPOOL_REPLAY_INTERVAL ) {
                return;
            }

            $lock = @fopen( $lock_path, 'c' );
            if ( false === $lock ) {
                return;
            }

            if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
                fclose( $lock );

                return;
            }

            @touch( $lock_path );

            $sent = 0;
            foreach ( self::spooled_files( $directory ) as $path ) {
                if ( $sent >= self::SPOOL_REPLAY_BATCH ) {
                    break;
                }

                $event_json = self::read_spooled_event( $path );
                if ( '' === $event_json ) {
                    @unlink( $path );
                    continue;
                }

                $sent++;
                $status = self::send_event( substr( basename( $path, '.php' ), strlen( 'event-' ) ), $event_json );
                if ( self::should_retry( $status ) ) {
                    break;
                }

                @unlink( $path );
            }

            flock( $lock, LOCK_UN );
            fclose( $lock );
        }

        /**
         * Kept events, oldest first. Anything older than SPOOL_MAX_AGE is
         * deleted on the way.
         *
         * @return string[]
         */
        private static function spooled_files( $directory ) {
            $paths = glob( $directory . '/event-*.php' );
            if ( ! is_array( $paths ) ) {
                return [];
            }

            $expires_before = time() - self::SPOOL_MAX_AGE;
            $kept = [];
            foreach ( $paths as $path ) {
                $modified_at = @filemtime( $path );
                if ( false === $modified_at ) {
                    continue;
                }

                if ( $modified_at < $expires_before ) {
                    @unlink( $path );
                    continue;
                }

                $kept[ $path ] = $modified_at;
            }

            asort( $kept );

            return array_keys( $kept );
        }

        private static function read_spooled_event( $path ) {
            $contents = @file_get_contents( $path );
            if ( ! is_string( $contents ) || 0 !== strpos( $contents, self::SPOOL_GUARD ) ) {
                return '';
            }

            $event_json = (string) substr( $contents, strlen( self::SPOOL_GUARD ) );

            return is_array( json_decode( $event_json, true ) ) ? $event_json : '';
        }

        private static function discard_spool() {
            $directory = self::spool_directory();
            if ( '' === $directory || ! is_dir( $directory ) ) {
                return;
            }

            $paths = glob( $directory . '/event-*.php' );
            foreach ( is_array( $paths ) ? $paths : [] as $path ) {
                @unlink( $path );
            }
        }

        private static function spool_directory() {
            if ( ! defined( 'WP_CONTENT_DIR' ) ) {
                return '';
            }

            return rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/' . self::SPOOL_DIRECTORY;
        }

        private static function sentry_auth_header() {
            $parts = parse_url( self::$dsn );
            $public_key = is_array( $parts ) && isset( $parts['user'] )
                ? rawurldecode( (string) $parts['user'] )
                : '';

            return 'Sentry sentry_version=7, sentry_key=' . $public_key . ', sentry_client=wp-notonfire/' . self::VERSION;
        }

        private static function debug_log( $message ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WP NotOnFire] ' . $message );
            }
        }

        private static function environment() {
            $environment = self::configuration_value( 'WP_NOTONFIRE_ENVIRONMENT' );
            if ( '' === $environment ) {
                $environment = self::configuration_value( 'WP_ENVIRONMENT_TYPE' );
            }
            if ( '' === $environment ) {
                return 'production';
            }

            $environment = preg_replace( '/[^a-z0-9._-]/i', '-', $environment );

            return is_string( $environment ) && '' !== $environment ? substr( $environment, 0, 64 ) : 'production';
        }

        private static function nonce() {
            try {
                $bytes = random_bytes( 32 );
            } catch ( Exception $exception ) {
                $bytes = openssl_random_pseudo_bytes( 32 );
            }

            if ( ! is_string( $bytes ) || 32 !== strlen( $bytes ) ) {
                $bytes = hash( 'sha256', uniqid( 'wp-notonfire-nonce-', true ), true );
            }

            return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
        }

        private static function event_id() {
            try {
                return bin2hex( random_bytes( 16 ) );
            } catch ( Exception $exception ) {
                return md5( uniqid( 'wp-notonfire-', true ) );
            }
        }
    }

    WP_NotOnFire_Monitor::bootstrap();
}

<?php
/**
 * Delivers events to the error-tracking backend as Sentry envelopes, and
 * keeps the ones it cannot deliver in wp-content/notonfire-spool until a
 * later request can.
 *
 * Runs inside a shutdown function after a fatal error, so it deliberately
 * uses curl or a stream rather than the WordPress HTTP API, which may be what
 * just failed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'NotOnFire_Transport', false ) ) {
    final class NotOnFire_Transport {

        const SPOOL_DIRECTORY = 'notonfire-spool';
        const SPOOL_GUARD = "<?php exit; ?>\n";
        const SPOOL_MAX_EVENTS = 50;
        const SPOOL_MAX_AGE = 86400;
        const SPOOL_REPLAY_BATCH = 5;
        const SPOOL_REPLAY_INTERVAL = 60;

        private static $dsn = '';
        private static $endpoint = '';

        public static function configure( $dsn ) {
            $endpoint = self::envelope_endpoint( $dsn );

            self::$dsn = '' !== $endpoint ? trim( (string) $dsn ) : '';
            self::$endpoint = $endpoint;
        }

        public static function is_ready() {
            return '' !== self::$endpoint;
        }

        /**
         * The envelope URL a DSN points at, or an empty string for anything
         * that is not an HTTPS DSN with a numeric project.
         */
        public static function envelope_endpoint( $dsn ) {
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

        /**
         * Sends one event as a Sentry envelope. Returns the HTTP status, 0
         * when nothing answered, or -1 when the envelope could not be built.
         */
        public static function send( $event_id, $event_json ) {
            if ( ! self::is_ready() ) {
                return -1;
            }

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

            return self::post( $envelope_header . "\n" . $item_header . "\n" . $event_json );
        }

        /**
         * Only a malformed or oversized event is dropped. Everything else is
         * kept until it ages out, including an unreachable host, every 5xx and
         * a 401: dropping loses the error for good, and a rotated key heals
         * once the next configuration sync brings the new DSN.
         */
        public static function should_retry( $status ) {
            if ( $status < 0 || ( $status >= 200 && $status < 300 ) ) {
                return false;
            }

            return ! in_array( $status, [ 400, 413, 422 ], true );
        }

        /**
         * Keeps an event that was not received until a later request can
         * send it.
         *
         * Files rather than an option, because the database may be what
         * failed. Only the event is stored and the envelope is rebuilt at
         * replay, so a rotated DSN does not strand it. wp-content is usually
         * served, so the directory is private to the PHP user, closed to
         * Apache, has no listing, and every file starts with an exit guard.
         */
        public static function spool( $event_id, $event_json ) {
            $directory = self::spool_directory();
            if ( '' === $directory || ! ctype_xdigit( (string) $event_id ) ) {
                return;
            }

            if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) {
                NotOnFire_Config::debug_log( 'The fatal event was lost because ' . $directory . ' could not be created.' );

                return;
            }

            if ( ! is_file( $directory . '/index.php' ) ) {
                @file_put_contents( $directory . '/index.php', "<?php\n// Silence is golden.\n" );
            }
            if ( ! is_file( $directory . '/.htaccess' ) ) {
                @file_put_contents( $directory . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
            }

            if ( count( self::spooled_files( $directory ) ) >= self::SPOOL_MAX_EVENTS ) {
                NotOnFire_Config::debug_log( 'The fatal event was lost because ' . self::SPOOL_MAX_EVENTS . ' events are already waiting.' );

                return;
            }

            // Written aside and renamed, so a replay never reads half a file.
            $temporary = $directory . '/event-' . $event_id . '.tmp';
            if ( false === @file_put_contents( $temporary, self::SPOOL_GUARD . $event_json, LOCK_EX ) ) {
                NotOnFire_Config::debug_log( 'The fatal event was lost because it could not be written to ' . $directory . '.' );

                return;
            }

            @chmod( $temporary, 0600 );
            if ( ! @rename( $temporary, $directory . '/event-' . $event_id . '.php' ) ) {
                @unlink( $temporary );
                NotOnFire_Config::debug_log( 'The fatal event was lost because it could not be written to ' . $directory . '.' );
            }
        }

        /**
         * Sends up to SPOOL_REPLAY_BATCH kept events, oldest first, and stops
         * at the first one that is still not taken.
         *
         * Runs at the end of requests without a fatal error, at most once per
         * SPOOL_REPLAY_INTERVAL. The lock file's mtime is the throttle, so a
         * site with nothing kept pays one stat per request, and flock keeps
         * two requests from sending the same event.
         */
        public static function maybe_replay() {
            if ( ! self::is_ready() ) {
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
                $status = self::send( substr( basename( $path, '.php' ), strlen( 'event-' ) ), $event_json );
                if ( self::should_retry( $status ) ) {
                    break;
                }

                @unlink( $path );
            }

            flock( $lock, LOCK_UN );
            fclose( $lock );
        }

        /**
         * Deletes every kept event. Used when error tracking is switched off
         * and when the site is connected to a different application, whose
         * project must never receive the old one's errors.
         */
        public static function discard_spool() {
            $directory = self::spool_directory();
            if ( '' === $directory || ! is_dir( $directory ) ) {
                return;
            }

            $paths = glob( $directory . '/event-*.php' );
            foreach ( is_array( $paths ) ? $paths : [] as $path ) {
                @unlink( $path );
            }
        }

        /**
         * Removes the spool directory itself. Only the uninstaller calls it.
         */
        public static function remove_spool_directory() {
            $directory = self::spool_directory();
            if ( '' === $directory || ! is_dir( $directory ) ) {
                return;
            }

            foreach ( [ 'event-*.php', 'event-*.tmp', 'index.php', '.htaccess', 'replay.lock' ] as $pattern ) {
                $paths = glob( $directory . '/' . $pattern );
                foreach ( is_array( $paths ) ? $paths : [] as $path ) {
                    @unlink( $path );
                }
            }

            @rmdir( $directory );
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

        private static function spool_directory() {
            if ( ! defined( 'WP_CONTENT_DIR' ) ) {
                return '';
            }

            return rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/' . self::SPOOL_DIRECTORY;
        }

        private static function post( $body ) {
            $headers = [
                'Content-Type: application/x-sentry-envelope',
                'X-Sentry-Auth: ' . self::auth_header(),
                'User-Agent: WP-NotOnFire/' . NotOnFire_Config::VERSION,
                'Content-Length: ' . strlen( $body ),
            ];

            if ( function_exists( 'curl_init' ) ) {
                $handle = curl_init( self::$endpoint );
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

                    // A no-op since PHP 8.0 and deprecated in 8.5.
                    if ( PHP_VERSION_ID < 80000 ) {
                        curl_close( $handle );
                    }

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

            @file_get_contents( self::$endpoint, false, $context );

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

        private static function auth_header() {
            $parts = parse_url( self::$dsn );
            $public_key = is_array( $parts ) && isset( $parts['user'] )
                ? rawurldecode( (string) $parts['user'] )
                : '';

            return 'Sentry sentry_version=7, sentry_key=' . $public_key . ', sentry_client=wp-notonfire/' . NotOnFire_Config::VERSION;
        }
    }
}

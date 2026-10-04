<?php
/**
 * The must-use loader that makes the reporter run before regular plugins and
 * the active theme.
 *
 * The loader holds no reporter code of its own. It requires the reporter from
 * this plugin's directory, so updating the plugin updates the reporter and
 * there is never a second copy to keep in step. It also checks that the
 * plugin is still active, so a loader that could not be removed on
 * deactivation stops reporting anyway.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'NotOnFire_Loader', false ) ) {
    final class NotOnFire_Loader {

        const FILENAME = '000-notonfire.php';
        const MARKER = 'Managed by the NotOnFire plugin.';

        public static function path() {
            $directory = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

            return rtrim( (string) $directory, '/\\' ) . '/' . self::FILENAME;
        }

        public static function install( $plugin_basename ) {
            $path = self::path();
            $directory = dirname( $path );

            if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
                return false;
            }

            $contents = self::contents( $plugin_basename );
            if ( is_file( $path ) && @file_get_contents( $path ) === $contents ) {
                return true;
            }

            // Written aside and renamed, so a request never loads half a file.
            $temporary = $path . '.' . wp_generate_password( 8, false ) . '.tmp';
            if ( false === @file_put_contents( $temporary, $contents, LOCK_EX ) ) {
                return false;
            }

            if ( ! @rename( $temporary, $path ) ) {
                @unlink( $temporary );

                return false;
            }

            return true;
        }

        /**
         * Deletes the loader, but only one this plugin wrote.
         */
        public static function remove() {
            $path = self::path();

            if ( ! is_file( $path ) ) {
                return true;
            }

            $contents = @file_get_contents( $path );
            if ( ! is_string( $contents ) || false === strpos( $contents, self::MARKER ) ) {
                return false;
            }

            return @unlink( $path );
        }

        public static function is_current( $plugin_basename ) {
            $path = self::path();

            return is_file( $path ) && @file_get_contents( $path ) === self::contents( $plugin_basename );
        }

        private static function contents( $plugin_basename ) {
            $reporter = __DIR__ . '/class-notonfire-reporter.php';

            return "<?php\n"
                . "/**\n"
                . " * Plugin Name: NotOnFire (early loader)\n"
                . " * Description: Loads the NotOnFire fatal-error reporter before other plugins and the theme. " . self::MARKER . " Deactivating the plugin removes this file.\n"
                . " */\n"
                . "\n"
                . "if ( ! defined( 'ABSPATH' ) ) {\n"
                . "    exit;\n"
                . "}\n"
                . "\n"
                . '$notonfire_reporter = ' . var_export( $reporter, true ) . ";\n"
                . "\n"
                . 'if ( is_readable( $notonfire_reporter ) && in_array( ' . var_export( (string) $plugin_basename, true ) . ", (array) get_option( 'active_plugins', [] ), true ) ) {\n"
                . "    require_once \$notonfire_reporter;\n"
                . "}\n"
                . "\n"
                . "unset( \$notonfire_reporter );\n";
        }
    }
}

<?php
/**
 * Prints the analytics tag into every front-end page while analytics is
 * switched on for this application in the NotOnFire dashboard.
 *
 * The attributes come from the dashboard exactly as it would render the
 * snippet itself, so the two never drift apart.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'NotOnFire_Analytics', false ) ) {
    final class NotOnFire_Analytics {

        public static function register() {
            add_action( 'wp_head', [ __CLASS__, 'print_tag' ], 1 );
        }

        public static function print_tag() {
            $attributes = NotOnFire_Reporter::analytics_attributes();

            if ( empty( $attributes ) || ! self::should_track() ) {
                return;
            }

            $html = '<script defer';
            foreach ( $attributes as $name => $value ) {
                $html .= ' ' . $name . '="' . ( 'src' === $name ? esc_url( $value ) : esc_attr( $value ) ) . '"';
            }

            echo $html . "></script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute is escaped above.
        }

        /**
         * Previews and the customizer are not visits. Logged-in editors are
         * only left out when the site asks for it on the settings page.
         */
        private static function should_track() {
            $track = ! is_admin() && ! is_preview() && ! is_customize_preview();

            if ( $track && NotOnFire_Config::exclude_editors() && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
                $track = false;
            }

            /**
             * Filters whether the NotOnFire analytics tag is printed on this
             * request.
             *
             * @param bool $track Whether to print the tag.
             */
            return (bool) apply_filters( 'notonfire_inject_analytics', $track );
        }
    }
}

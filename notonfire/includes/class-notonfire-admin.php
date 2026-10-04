<?php
/**
 * Settings → NotOnFire.
 *
 * Shows the connection and the dashboard's view of this site. Error tracking
 * and analytics are switched on and off in the NotOnFire dashboard only; this
 * page reports their state and links there. What can be changed here is the
 * connection itself (for a site that moves to another NotOnFire application)
 * and whether logged-in editors are left out of analytics.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'NotOnFire_Admin', false ) ) {
    final class NotOnFire_Admin {

        const PAGE = 'notonfire';
        const CAPABILITY = 'manage_options';
        const NOTICE_KEY = 'notonfire_notice_';
        const REFRESH_AFTER = 60;

        public static function register() {
            add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
            add_action( 'admin_init', [ __CLASS__, 'repair_loader' ] );
            add_action( 'admin_notices', [ __CLASS__, 'print_site_notices' ] );
            add_action( 'admin_post_notonfire_save', [ __CLASS__, 'save' ] );
            add_action( 'admin_post_notonfire_sync', [ __CLASS__, 'sync' ] );
            add_action( 'admin_post_notonfire_test_event', [ __CLASS__, 'send_test_event' ] );
            add_filter( 'plugin_action_links_' . plugin_basename( NOTONFIRE_PLUGIN_FILE ), [ __CLASS__, 'action_links' ] );
        }

        public static function add_page() {
            add_options_page(
                __( 'NotOnFire', 'notonfire' ),
                __( 'NotOnFire', 'notonfire' ),
                self::CAPABILITY,
                self::PAGE,
                [ __CLASS__, 'render' ]
            );
        }

        public static function action_links( $links ) {
            array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'notonfire' ) . '</a>' );

            return $links;
        }

        /**
         * Puts the must-use loader back when it went missing or points at an
         * old path, for example after the plugin directory moved.
         */
        public static function repair_loader() {
            if ( ! current_user_can( self::CAPABILITY ) || wp_doing_ajax() ) {
                return;
            }

            $basename = plugin_basename( NOTONFIRE_PLUGIN_FILE );
            if ( ! NotOnFire_Loader::is_current( $basename ) ) {
                NotOnFire_Loader::install( $basename );
            }
        }

        public static function print_site_notices() {
            if ( ! current_user_can( self::CAPABILITY ) ) {
                return;
            }

            if ( ! NotOnFire_Loader::is_current( plugin_basename( NOTONFIRE_PLUGIN_FILE ) ) ) {
                self::print_notice( 'warning', sprintf(
                    /* translators: %s: path of the must-use loader file */
                    __( 'NotOnFire could not write %s. Fatal errors are still reported, but ones caused by other plugins or the theme while they load can be missed. Make wp-content/mu-plugins writable for the web server, then open the NotOnFire settings again.', 'notonfire' ),
                    '<code>' . esc_html( NotOnFire_Loader::path() ) . '</code>'
                ), false );
            }

            if ( ! NotOnFire_Config::is_connected() && ! self::is_settings_screen() ) {
                self::print_notice( 'info', sprintf(
                    /* translators: %s: link to the settings page */
                    __( 'NotOnFire is not connected yet. %s', 'notonfire' ),
                    '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Enter the connection details', 'notonfire' ) . '</a>'
                ), false );
            }
        }

        public static function save() {
            self::authorize( 'notonfire_save' );

            $previous = [
                'server_url' => NotOnFire_Config::server_url(),
                'site_id' => NotOnFire_Config::site_id(),
                'site_token' => NotOnFire_Config::site_token(),
            ];
            $settings = NotOnFire_Config::settings();
            $errors = [];

            if ( ! NotOnFire_Config::is_overridden( 'server_url' ) ) {
                $server_url = isset( $_POST['server_url'] ) ? rtrim( trim( sanitize_text_field( wp_unslash( $_POST['server_url'] ) ) ), '/' ) : '';
                if ( '' !== $server_url && ! NotOnFire_Config::is_valid_server_url( $server_url ) ) {
                    $errors[] = __( 'The Server URL must be an https:// address.', 'notonfire' );
                } else {
                    $settings['server_url'] = $server_url;
                }
            }

            if ( ! NotOnFire_Config::is_overridden( 'site_id' ) ) {
                $site_id = isset( $_POST['site_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) ) : '';
                if ( '' !== $site_id && ! ctype_digit( $site_id ) ) {
                    $errors[] = __( 'The Site ID is a number.', 'notonfire' );
                } else {
                    $settings['site_id'] = $site_id;
                }
            }

            // An empty token field keeps the saved token: it is never printed
            // back into the page.
            if ( ! NotOnFire_Config::is_overridden( 'site_token' ) ) {
                $site_token = isset( $_POST['site_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['site_token'] ) ) ) : '';
                if ( '' !== $site_token ) {
                    $settings['site_token'] = $site_token;
                }
            }

            $settings['exclude_editors'] = ! empty( $_POST['exclude_editors'] );

            NotOnFire_Config::save_settings( $settings );

            $current = [
                'server_url' => NotOnFire_Config::server_url(),
                'site_id' => NotOnFire_Config::site_id(),
                'site_token' => NotOnFire_Config::site_token(),
            ];

            if ( ! empty( $errors ) ) {
                self::flash( 'error', implode( ' ', $errors ) );
            } elseif ( $current !== $previous ) {
                self::flash_sync_result( NotOnFire_Reporter::reconnect() );
            } else {
                self::flash( 'success', __( 'Settings saved.', 'notonfire' ) );
            }

            self::redirect();
        }

        public static function sync() {
            self::authorize( 'notonfire_sync' );
            self::flash_sync_result( NotOnFire_Reporter::sync() );
            self::redirect();
        }

        public static function send_test_event() {
            self::authorize( 'notonfire_test_event' );

            $status = NotOnFire_Reporter::send_test_event();

            if ( $status >= 200 && $status < 300 ) {
                self::flash( 'success', __( 'Test error sent. It shows up in the NotOnFire dashboard within a minute.', 'notonfire' ) );
            } elseif ( -1 === $status ) {
                self::flash( 'error', __( 'There is nothing to send to yet: error tracking is not active for this site.', 'notonfire' ) );
            } else {
                self::flash( 'error', sprintf(
                    /* translators: %d: HTTP status code, 0 when nothing answered */
                    __( 'The test error was not accepted (HTTP %d).', 'notonfire' ),
                    $status
                ) );
            }

            self::redirect();
        }

        public static function render() {
            if ( ! current_user_can( self::CAPABILITY ) ) {
                return;
            }

            self::refresh_if_due();

            $state = NotOnFire_Config::state();
            $application = isset( $state['application'] ) && is_array( $state['application'] ) ? $state['application'] : [];
            $connected = NotOnFire_Config::is_connected() && ! empty( $state['last_success_at'] );
            $error_tracking = NotOnFire_Reporter::error_tracking_state( $state );
            $analytics = ! empty( NotOnFire_Reporter::analytics_attributes() );
            $latest_version = isset( $state['latest_version'] ) ? (string) $state['latest_version'] : '';
            $settings_url = isset( $application['settings_url'] ) ? (string) $application['settings_url'] : '';
            $last_error = isset( $state['last_error'] ) ? (string) $state['last_error'] : '';
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'NotOnFire', 'notonfire' ); ?></h1>

                <?php self::print_flash(); ?>

                <h2><?php esc_html_e( 'Status', 'notonfire' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Connection', 'notonfire' ); ?></th>
                        <td>
                            <?php if ( $connected && '' === $last_error ) : ?>
                                <?php
                                echo esc_html( sprintf(
                                    /* translators: 1: application name, 2: application domain */
                                    __( 'Connected to %1$s (%2$s)', 'notonfire' ),
                                    isset( $application['name'] ) ? $application['name'] : '',
                                    isset( $application['domain'] ) ? $application['domain'] : ''
                                ) );
                                ?>
                            <?php elseif ( '' !== $last_error ) : ?>
                                <span style="color:#b32d2e"><?php echo esc_html( self::sync_error_message( $last_error ) ); ?></span>
                            <?php else : ?>
                                <?php esc_html_e( 'Not connected yet.', 'notonfire' ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Error tracking', 'notonfire' ); ?></th>
                        <td><?php echo esc_html( self::error_tracking_label( $error_tracking ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Analytics', 'notonfire' ); ?></th>
                        <td><?php echo esc_html( $analytics ? __( 'On — the tracking script is added to every page.', 'notonfire' ) : __( 'Off', 'notonfire' ) ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Last sync', 'notonfire' ); ?></th>
                        <td>
                            <?php
                            echo esc_html( ! empty( $state['last_success_at'] )
                                ? sprintf(
                                    /* translators: %s: human-readable time difference */
                                    __( '%s ago', 'notonfire' ),
                                    human_time_diff( (int) $state['last_success_at'] )
                                )
                                : __( 'Never', 'notonfire' ) );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Plugin version', 'notonfire' ); ?></th>
                        <td>
                            <?php echo esc_html( NotOnFire_Config::VERSION ); ?>
                            <?php if ( '' !== $latest_version && version_compare( NotOnFire_Config::VERSION, $latest_version, '<' ) ) : ?>
                                —
                                <a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>">
                                    <?php
                                    echo esc_html( sprintf(
                                        /* translators: %s: version number */
                                        __( 'version %s is available', 'notonfire' ),
                                        $latest_version
                                    ) );
                                    ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <p>
                    <?php self::action_button( 'notonfire_sync', __( 'Sync now', 'notonfire' ), ! NotOnFire_Config::is_connected() ); ?>
                    <?php if ( 'active' === $error_tracking ) : ?>
                        <?php self::action_button( 'notonfire_test_event', __( 'Send test error', 'notonfire' ) ); ?>
                    <?php endif; ?>
                    <?php if ( '' !== $settings_url ) : ?>
                        <a class="button button-link" href="<?php echo esc_url( $settings_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage in NotOnFire', 'notonfire' ); ?></a>
                    <?php endif; ?>
                </p>
                <p class="description"><?php esc_html_e( 'Error tracking and analytics are switched on and off in the NotOnFire dashboard. Changes reach this site within 15 minutes, or right away with "Sync now".', 'notonfire' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="notonfire_save">
                    <?php wp_nonce_field( 'notonfire_save' ); ?>

                    <h2><?php esc_html_e( 'Connection', 'notonfire' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Pre-filled by the download from the NotOnFire dashboard. Change these only to connect this site to a different NotOnFire application.', 'notonfire' ); ?></p>
                    <table class="form-table" role="presentation">
                        <?php self::text_field( 'server_url', __( 'Server URL', 'notonfire' ), NotOnFire_Config::server_url(), 'url' ); ?>
                        <?php self::text_field( 'site_id', __( 'Site ID', 'notonfire' ), NotOnFire_Config::site_id() > 0 ? (string) NotOnFire_Config::site_id() : '', 'text' ); ?>
                        <?php self::token_field(); ?>
                    </table>

                    <h2><?php esc_html_e( 'Analytics', 'notonfire' ); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Logged-in editors', 'notonfire' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="exclude_editors" value="1" <?php checked( NotOnFire_Config::exclude_editors() ); ?>>
                                    <?php esc_html_e( 'Don\'t track logged-in editors', 'notonfire' ); ?>
                                </label>
                                <p class="description"><?php esc_html_e( 'Leaves out visits by users who can edit posts (authors, editors, administrators). Customers and subscribers are still counted.', 'notonfire' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( __( 'Save settings', 'notonfire' ) ); ?>
                </form>
            </div>
            <?php
        }

        private static function text_field( $key, $label, $value, $type ) {
            $overridden = NotOnFire_Config::is_overridden( $key );
            ?>
            <tr>
                <th scope="row"><label for="notonfire-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                <td>
                    <input type="<?php echo esc_attr( $type ); ?>" class="regular-text code" id="notonfire-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php disabled( $overridden ); ?>>
                    <?php self::override_hint( $key ); ?>
                </td>
            </tr>
            <?php
        }

        private static function token_field() {
            $overridden = NotOnFire_Config::is_overridden( 'site_token' );
            $saved = '' !== NotOnFire_Config::site_token();
            ?>
            <tr>
                <th scope="row"><label for="notonfire-site_token"><?php esc_html_e( 'Site Token', 'notonfire' ); ?></label></th>
                <td>
                    <input type="password" class="regular-text code" id="notonfire-site_token" name="site_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $saved ? __( 'Saved — leave empty to keep it', 'notonfire' ) : '' ); ?>" <?php disabled( $overridden ); ?>>
                    <?php self::override_hint( 'site_token' ); ?>
                    <p class="description"><?php esc_html_e( 'Treat the Site Token like a password.', 'notonfire' ); ?></p>
                </td>
            </tr>
            <?php
        }

        private static function override_hint( $key ) {
            if ( ! NotOnFire_Config::is_overridden( $key ) ) {
                return;
            }

            echo '<p class="description">' . sprintf(
                /* translators: %s: constant name */
                esc_html__( 'Set by %s in wp-config.php or the environment.', 'notonfire' ),
                '<code>' . esc_html( NotOnFire_Config::OVERRIDES[ $key ] ) . '</code>'
            ) . '</p>';
        }

        private static function action_button( $action, $label, $disabled = false ) {
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
                <?php wp_nonce_field( $action ); ?>
                <button type="submit" class="button" <?php disabled( $disabled ); ?>><?php echo esc_html( $label ); ?></button>
            </form>
            <?php
        }

        /**
         * Asks the dashboard again when the page is opened, so it shows what
         * the dashboard says now rather than up to an hour ago. At most once
         * a minute, so reloading the page does not hammer the dashboard.
         */
        private static function refresh_if_due() {
            if ( ! NotOnFire_Config::is_connected() ) {
                return;
            }

            $state = NotOnFire_Config::state();
            $last_attempt_at = isset( $state['last_attempt_at'] ) ? (int) $state['last_attempt_at'] : 0;

            if ( $last_attempt_at <= time() - self::REFRESH_AFTER ) {
                NotOnFire_Reporter::sync();
            }
        }

        private static function error_tracking_label( $state ) {
            switch ( $state ) {
                case 'active':
                    return __( 'Active — fatal errors are reported.', 'notonfire' );
                case 'provisioning':
                    return __( 'Being set up — this takes a minute.', 'notonfire' );
                case 'disabled':
                    return __( 'Off', 'notonfire' );
                default:
                    return __( 'Unknown until the site is connected.', 'notonfire' );
            }
        }

        private static function sync_error_message( $error ) {
            switch ( $error ) {
                case 'not_configured':
                    return __( 'Enter the Server URL, Site ID and Site Token.', 'notonfire' );
                case 'unreachable':
                    return __( 'The NotOnFire dashboard could not be reached.', 'notonfire' );
                case 'unauthorized':
                    return __( 'The NotOnFire dashboard rejected the Site ID or Site Token. Check that WordPress monitoring is enabled for this application.', 'notonfire' );
                case 'invalid_response':
                    return __( 'The NotOnFire dashboard sent an answer this plugin does not understand.', 'notonfire' );
                default:
                    return __( 'The NotOnFire dashboard answered with an error.', 'notonfire' );
            }
        }

        private static function flash_sync_result( $error ) {
            if ( '' !== $error ) {
                self::flash( 'error', self::sync_error_message( $error ) );

                return;
            }

            $state = NotOnFire_Config::state();
            $application = isset( $state['application'] ) && is_array( $state['application'] ) ? $state['application'] : [];

            self::flash( 'success', sprintf(
                /* translators: 1: application name, 2: application domain */
                __( 'Connected to %1$s (%2$s).', 'notonfire' ),
                isset( $application['name'] ) ? $application['name'] : '',
                isset( $application['domain'] ) ? $application['domain'] : ''
            ) );
        }

        private static function authorize( $action ) {
            if ( ! current_user_can( self::CAPABILITY ) ) {
                wp_die( esc_html__( 'You are not allowed to change the NotOnFire settings.', 'notonfire' ), 403 );
            }

            check_admin_referer( $action );
        }

        private static function flash( $type, $message ) {
            set_transient( self::NOTICE_KEY . get_current_user_id(), [ 'type' => $type, 'message' => $message ], 60 );
        }

        private static function print_flash() {
            $key = self::NOTICE_KEY . get_current_user_id();
            $notice = get_transient( $key );

            if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
                return;
            }

            delete_transient( $key );
            self::print_notice( $notice['type'], esc_html( $notice['message'] ), true );
        }

        /**
         * @param string $message Already escaped HTML.
         */
        private static function print_notice( $type, $message, $dismissible ) {
            printf(
                '<div class="notice notice-%1$s%2$s"><p>%3$s</p></div>',
                esc_attr( $type ),
                $dismissible ? ' is-dismissible' : '',
                $message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
            );
        }

        private static function redirect() {
            wp_safe_redirect( self::page_url() );
            exit;
        }

        private static function page_url() {
            return admin_url( 'options-general.php?page=' . self::PAGE );
        }

        private static function is_settings_screen() {
            return isset( $_GET['page'] ) && self::PAGE === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
        }
    }
}

<?php
namespace Riffit;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Auth {
    public function hooks(): void {
        add_action( 'wp_set_password', function ( $password, $user_id ) { $this->revoke_user_sessions( (int) $user_id ); }, 10, 2 );
        add_action( 'profile_update', function ( $user_id, $old ) { $new = get_userdata( $user_id ); if ( $new && ( $old->user_email !== $new->user_email || $old->user_pass !== $new->user_pass ) ) { $this->revoke_user_sessions( (int) $user_id ); } }, 10, 2 );
        add_action( 'rest_api_init', array( $this, 'routes' ) );
        add_filter( 'rest_pre_dispatch', array( $this, 'authenticate' ), 5, 3 );
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'settings' ) );
        add_action( 'riffit_auth_cleanup', array( $this, 'cleanup' ) );
        add_filter( 'rest_post_dispatch', static function ( $response, $server, $request ) {
            if ( str_starts_with( $request->get_route(), '/riffit/v1/auth/' ) || '/riffit/v1/me' === $request->get_route() || str_starts_with( $request->get_route(), '/riffit/v1/account' ) ) {
                $response->header( 'Cache-Control', 'private, no-store' );
                $response->header( 'Referrer-Policy', 'no-referrer' );
            }
            return $response;
        }, 10, 3 );
    }

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $challenges = $wpdb->prefix . 'riffit_challenges';
        $sessions = $wpdb->prefix . 'riffit_sessions';
        $limits = $wpdb->prefix . 'riffit_auth_limits';
        dbDelta( "CREATE TABLE {$challenges} (
            token_hash char(64) NOT NULL,
            email varchar(100) NOT NULL,
            proof char(64) NOT NULL,
            expires_at datetime NOT NULL,
            consumed tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (token_hash),
            KEY expires_at (expires_at)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$sessions} (
            token_hash char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            auth_method varchar(20) NOT NULL DEFAULT 'email',
            expires_at datetime NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$limits} (
            bucket char(64) NOT NULL,
            hits int unsigned NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (bucket),
            KEY expires_at (expires_at)
        ) {$charset};" );
        if ( ! wp_next_scheduled( 'riffit_auth_cleanup' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'riffit_auth_cleanup' ); }
    }

    public function routes(): void {
        register_rest_route( 'riffit/v1', '/auth/email/request', array(
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'request_link' ),
            'args' => array(
                'email' => array( 'type' => 'string', 'format' => 'email', 'maxLength' => 100, 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
                'proof' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'required' => true ),
            ),
        ) );
        register_rest_route( 'riffit/v1', '/auth/email/consume', array(
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'consume' ),
            'args' => array(
                'code' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'required' => true ),
                'verifier' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{43,128}$', 'required' => true ),
            ),
        ) );
        register_rest_route( 'riffit/v1', '/account', array(
            'methods' => 'GET', 'permission_callback' => 'is_user_logged_in', 'callback' => array( $this, 'account' ),
        ) );
        register_rest_route( 'riffit/v1', '/account/sessions', array(
            'methods' => 'DELETE', 'permission_callback' => 'is_user_logged_in', 'callback' => array( $this, 'logout_other_sessions' ),
        ) );
        register_rest_route( 'riffit/v1', '/auth/session', array(
            'methods' => 'DELETE', 'permission_callback' => 'is_user_logged_in', 'callback' => array( $this, 'logout' ),
        ) );
    }

    private function error( string $code, string $message, int $status ): \WP_Error {
        return new \WP_Error( 'riffit_' . $code, $message, array( 'status' => $status ) );
    }

    public function limited( string $scope, int $maximum, int $window ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'riffit_auth_limits';
        $bucket = hash_hmac( 'sha256', $scope . ':' . (int) floor( time() / $window ), wp_salt( 'auth' ) );
        $result = $wpdb->query( $wpdb->prepare(
            'INSERT INTO %i (bucket,hits,expires_at) VALUES (%s,1,%s) ON DUPLICATE KEY UPDATE hits=hits+1',
            $table, $bucket, gmdate( 'Y-m-d H:i:s', time() + 2 * $window )
        ) );
        if ( false === $result ) { return true; }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE bucket=%s', $table, $bucket ) ) > $maximum;
    }

    public function request_link( \WP_REST_Request $request ) {
        global $wpdb;
        $frontend = get_option( 'riffit_frontend_url', '' );
        if ( ! $frontend ) { return $this->error( 'setup_required', 'Member sign-in is not configured yet.', 503 ); }
        $email = strtolower( $request['email'] );
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        if ( $this->limited( 'request-ip:' . $ip, 300, HOUR_IN_SECONDS ) || $this->limited( 'request-email:' . $email, 3, 15 * MINUTE_IN_SECONDS ) ) {
            return $this->error( 'rate_limited', 'Please wait before requesting another sign-in link.', 429 );
        }
        $token = bin2hex( random_bytes( 32 ) );
        $hash = hash( 'sha256', $token );
        $saved = $wpdb->insert( $wpdb->prefix . 'riffit_challenges', array(
            'token_hash' => $hash, 'email' => $email, 'proof' => $request['proof'],
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ), 'consumed' => 0,
        ), array( '%s', '%s', '%s', '%s', '%d' ) );
        if ( false === $saved ) { return $this->error( 'unavailable', 'Sign-in is temporarily unavailable.', 503 ); }
        $url = add_query_arg( 'code', $token, trailingslashit( $frontend ) . 'auth/verify' );
        $message = "Sign in to Riffit\n\nOpen this link in the browser where you requested it, then confirm your sign-in:\n\n" . $url . "\n\nThis link expires in 15 minutes and works once. If you didn't request it, you can ignore this email.\n";
        if ( ! wp_mail( $email, 'Your Riffit sign-in link', $message ) ) {
            $wpdb->delete( $wpdb->prefix . 'riffit_challenges', array( 'token_hash' => $hash ), array( '%s' ) );
            return $this->error( 'mail_unavailable', 'We could not send a sign-in link. Please try again later.', 503 );
        }
        return new \WP_REST_Response( array( 'accepted' => true ), 202 );
    }

    public function consume( \WP_REST_Request $request ) {
        global $wpdb;
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        if ( $this->limited( 'consume-ip:' . $ip, 300, HOUR_IN_SECONDS ) ) { return $this->error( 'rate_limited', 'Please wait before trying again.', 429 ); }
        $table = $wpdb->prefix . 'riffit_challenges';
        $hash = hash( 'sha256', $request['code'] );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s', $table, $hash ) );
        $invalid = $this->error( 'invalid_link', 'This link has expired, was already used, or belongs to another browser.', 401 );
        if ( ! $row || $row->consumed || strtotime( $row->expires_at . ' UTC' ) <= time() || ! hash_equals( $row->proof, hash( 'sha256', $request['verifier'] ) ) ) { return $invalid; }
        $claimed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET consumed=1 WHERE token_hash=%s AND consumed=0 AND expires_at>%s', $table, $hash, gmdate( 'Y-m-d H:i:s' ) ) );
        if ( 1 !== $claimed ) { return $invalid; }
        $user = get_user_by( 'email', $row->email );
        if ( ! $user ) {
            $handle = 'riffit-' . bin2hex( random_bytes( 6 ) );
            $id = wp_insert_user( array( 'user_login' => $handle, 'user_nicename' => $handle, 'display_name' => $handle,
                'user_email' => $row->email, 'user_pass' => wp_generate_password( 48 ), 'role' => 'subscriber' ) );
            if ( is_wp_error( $id ) ) { return $this->error( 'account_unavailable', 'Could not prepare your account. Request a new link.', 503 ); }
            $user = get_userdata( $id );
        }
        return $this->session_for_user( $user );
    }

    public function session_for_user( \WP_User $user, string $method = 'email' ) {
        global $wpdb;
        if ( user_can( $user, 'manage_options' ) && 'wordpress' !== $method ) {
            return $this->error( 'admin_wordpress_required', 'Site administrators must use WordPress.com sign-in.', 403 );
        }
        if ( ! user_can( $user, 'read' ) || ( function_exists( 'bp_is_user_active' ) && ! bp_is_user_active( $user->ID ) ) || ( function_exists( 'bbp_get_user_role' ) && bbp_get_blocked_role() === bbp_get_user_role( $user->ID ) ) ) {
            return $this->error( 'account_unavailable', 'This account cannot sign in.', 403 );
        }
        update_user_meta( $user->ID, 'riffit_email_verified', 1 );
        if ( function_exists( 'bbp_get_user_role' ) && ! bbp_get_user_role( $user->ID ) ) { bbp_set_user_role( $user->ID, bbp_get_participant_role() ); }
        $session = bin2hex( random_bytes( 32 ) );
        $expires = time() + 14 * DAY_IN_SECONDS;
        $saved = $wpdb->insert( $wpdb->prefix . 'riffit_sessions', array( 'token_hash' => hash( 'sha256', $session ),
            'user_id' => $user->ID, 'auth_method' => $method, 'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ) ), array( '%s', '%d', '%s', '%s' ) );
        if ( false === $saved ) { return $this->error( 'session_unavailable', 'Could not create your session. Request a new link.', 503 ); }
        return array( 'token' => $session, 'expires_at' => $expires, 'user' => array( 'id' => $user->ID, 'handle' => $user->user_nicename, 'name' => $user->display_name ) );
    }

    public function authenticate( $result, $server, \WP_REST_Request $request ) {
        if ( ! str_starts_with( $request->get_route(), '/riffit/v1/' ) ) { return $result; }
        $header = $request->get_header( 'authorization' );
        if ( ! preg_match( '/^Bearer ([a-f0-9]{64})$/', $header, $match ) ) {
            if ( str_starts_with( strtolower( $header ), 'bearer ' ) ) { return $this->error( 'invalid_session', 'Please sign in again.', 401 ); }
            return $result;
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id,auth_method FROM %i WHERE token_hash=%s AND expires_at>%s',
            $wpdb->prefix . 'riffit_sessions', hash( 'sha256', $match[1] ), gmdate( 'Y-m-d H:i:s' ) ) );
        if ( ! $row || ! get_userdata( $row->user_id ) ) { return $this->error( 'invalid_session', 'Please sign in again.', 401 ); }
        $user = get_userdata( $row->user_id );
        if ( ( user_can( $user, 'manage_options' ) && 'wordpress' !== $row->auth_method ) || ! user_can( $user, 'read' ) || ( function_exists( 'bp_is_user_active' ) && ! bp_is_user_active( $user->ID ) ) || ( function_exists( 'bbp_get_user_role' ) && bbp_get_blocked_role() === bbp_get_user_role( $user->ID ) ) ) {
            $this->revoke_user_sessions( (int) $user->ID );
            return $this->error( 'invalid_session', 'Please sign in again.', 401 );
        }
        wp_set_current_user( (int) $row->user_id );
        return $result;
    }

    public function logout( \WP_REST_Request $request ) {
        global $wpdb;
        $header = $request->get_header( 'authorization' );
        if ( preg_match( '/^Bearer ([a-f0-9]{64})$/', $header, $match ) ) {
            $wpdb->delete( $wpdb->prefix . 'riffit_sessions', array( 'token_hash' => hash( 'sha256', $match[1] ) ), array( '%s' ) );
        }
        return array( 'signed_out' => true );
    }

    public function account() {
        global $wpdb;
        $user = wp_get_current_user();
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id=%d AND expires_at>%s', $wpdb->prefix . 'riffit_sessions', $user->ID, gmdate( 'Y-m-d H:i:s' ) ) );
        return array( 'email' => $user->user_email, 'sessions' => $count );
    }

    public function logout_other_sessions( \WP_REST_Request $request ) {
        global $wpdb;
        if ( ! preg_match( '/^Bearer ([a-f0-9]{64})$/', $request->get_header( 'authorization' ), $match ) ) { return $this->error( 'invalid_session', 'Please sign in again.', 401 ); }
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id=%d AND token_hash<>%s', $wpdb->prefix . 'riffit_sessions', get_current_user_id(), hash( 'sha256', $match[1] ) ) );
        return array( 'signed_out_others' => true );
    }

    public function revoke_user_sessions( int $user_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'riffit_sessions', array( 'user_id' => $user_id ), array( '%d' ) );
    }

    public function cleanup(): void {
        global $wpdb;
        foreach ( array( 'riffit_challenges', 'riffit_sessions', 'riffit_auth_limits' ) as $name ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at<%s', $wpdb->prefix . $name, gmdate( 'Y-m-d H:i:s' ) ) );
        }
    }

    public function menu(): void { add_options_page( 'Riffit', 'Riffit', 'manage_options', 'riffit', array( $this, 'render' ) ); }
    public function settings(): void {
        register_setting( 'riffit', 'riffit_frontend_url', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_origin' ), 'default' => '' ) );
    }
    public function sanitize_origin( $input ): string {
        $parts = wp_parse_url( trim( (string) $input ) );
        if ( empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) || ( ! empty( $parts['path'] ) && '/' !== $parts['path'] ) || ( 'https' !== ( $parts['scheme'] ?? '' ) && ! ( 'http' === ( $parts['scheme'] ?? '' ) && in_array( $parts['host'], array( 'localhost', '127.0.0.1' ), true ) ) ) ) {
            add_settings_error( 'riffit', 'riffit_invalid_origin', 'Use your frontend HTTPS origin, without a path. Localhost HTTP is allowed for development.' );
            return get_option( 'riffit_frontend_url', '' );
        }
        return untrailingslashit( esc_url_raw( trim( (string) $input ) ) );
    }
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="wrap"><h1>Riffit</h1><p>Connect the frontend used by your members. Email links return to this address.</p>';
        settings_errors( 'riffit' );
        echo '<form action="options.php" method="post">';
        settings_fields( 'riffit' );
        echo '<p><label for="riffit_frontend_url">Frontend URL</label></p><input class="regular-text" type="url" id="riffit_frontend_url" name="riffit_frontend_url" value="' . esc_attr( get_option( 'riffit_frontend_url', '' ) ) . '" placeholder="https://your-community.example" required>';
        do_settings_sections( 'riffit' );
        echo '<p>Google is deferred. Save your dedicated WordPress.com application settings above to enable that option.</p>';
        submit_button( 'Save Riffit settings' );
        echo '</form>';
        do_action( 'riffit_after_settings_form' );
        echo '</div>';
    }
}

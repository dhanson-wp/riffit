<?php
namespace Riffit;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WordPressConnect {
    public function hooks(): void {
        add_action( 'admin_post_riffit_wordpress_link', array( $this, 'admin_link_start' ) );
        add_action( 'admin_post_riffit_wordpress_complete', array( $this, 'admin_link_complete' ) );
        add_action( 'riffit_after_settings_form', array( $this, 'render_admin_link' ) );
        add_action( 'rest_api_init', array( $this, 'routes' ) );
        add_action( 'admin_init', array( $this, 'settings' ) );
        add_action( 'riffit_auth_cleanup', array( $this, 'cleanup' ) );
    }
    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$wpdb->prefix}riffit_oauth_states (
            token_hash char(64) NOT NULL,
            proof char(64) NOT NULL,
            link_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            consumed tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (token_hash),
            KEY expires_at (expires_at)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}riffit_identities (
            provider varchar(40) NOT NULL,
            subject varchar(100) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (provider,subject),
            KEY user_id (user_id)
        ) {$charset};" );
    }
    private function error( string $code, string $message, int $status = 400 ): \WP_Error { return new \WP_Error( 'riffit_' . $code, $message, array( 'status' => $status ) ); }
    private function configured(): bool { return (bool) get_option( 'riffit_wpcom_enabled', false ) && (bool) get_option( 'riffit_wpcom_client_id', '' ) && (bool) get_option( 'riffit_wpcom_secret', '' ) && (bool) get_option( 'riffit_frontend_url', '' ); }
    private function redirect_uri(): string { return trailingslashit( get_option( 'riffit_frontend_url', '' ) ) . 'auth/wordpress'; }
    public function routes(): void {
        register_rest_route( 'riffit/v1', '/auth/options', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn() => array( 'email' => (bool) get_option( 'riffit_frontend_url', '' ), 'wordpress' => $this->configured() ) ) );
        register_rest_route( 'riffit/v1', '/auth/wordpress/start', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'start' ), 'args' => array(
            'proof' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'required' => true ),
            'mode' => array( 'type' => 'string', 'enum' => array( 'signin','link' ), 'default' => 'signin' ),
        ) ) );
        register_rest_route( 'riffit/v1', '/auth/wordpress/consume', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'consume' ), 'args' => array(
            'state' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'required' => true ),
            'code' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'required' => true ),
            'verifier' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{43,128}$', 'required' => true ),
        ) ) );
    }
    public function start( \WP_REST_Request $request, bool $native = false ) {
        global $wpdb;
        if ( ! $this->configured() ) { return $this->error( 'oauth_setup', 'WordPress.com sign-in is not configured yet.', 503 ); }
        if ( $native && ( ! current_user_can( 'manage_options' ) || 'link' !== $request['mode'] ) ) { return $this->error( 'oauth_admin', 'Use your signed-in WordPress administrator account to link this identity.', 403 ); }
        $link = 0;
        if ( 'link' === $request['mode'] ) {
            $allowed = ( new Api() )->member_permission();
            if ( is_wp_error( $allowed ) ) { return $allowed; }
            $link = get_current_user_id();
        }
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        if ( ( new Auth() )->limited( 'wpcom-start:' . $ip, 300, HOUR_IN_SECONDS ) ) { return $this->error( 'rate_limited', 'Please wait before trying again.', 429 ); }
        $state = ( $native ? 'admin-' : '' ) . bin2hex( random_bytes( 32 ) );
        $saved = $wpdb->insert( $wpdb->prefix . 'riffit_oauth_states', array( 'token_hash' => hash( 'sha256', $state ), 'proof' => $request['proof'], 'link_user_id' => $link, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS ), 'consumed' => 0 ) );
        if ( false === $saved ) { return $this->error( 'oauth_unavailable', 'Could not start WordPress.com sign-in.', 503 ); }
        return array( 'url' => add_query_arg( array( 'client_id' => get_option( 'riffit_wpcom_client_id' ), 'redirect_uri' => $this->redirect_uri(), 'response_type' => 'code', 'scope' => 'auth', 'state' => $state ), 'https://public-api.wordpress.com/oauth2/authenticate' ) );
    }
    public function consume( \WP_REST_Request $request, bool $native = false ) {
        global $wpdb;
        if ( ! $this->configured() ) { return $this->error( 'oauth_setup', 'WordPress.com sign-in is not configured yet.', 503 ); }
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        if ( ( new Auth() )->limited( 'wpcom-consume:' . $ip, 300, HOUR_IN_SECONDS ) ) { return $this->error( 'rate_limited', 'Please wait before trying again.', 429 ); }
        if ( $native !== str_starts_with( $request['state'], 'admin-' ) || ( $native && ! current_user_can( 'manage_options' ) ) ) { return $this->error( 'oauth_admin', 'Complete this connection in your signed-in WordPress administrator browser.', 403 ); }
        $table = $wpdb->prefix . 'riffit_oauth_states'; $hash = hash( 'sha256', $request['state'] );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s', $table, $hash ) );
        if ( ! $row || $row->consumed || strtotime( $row->expires_at . ' UTC' ) <= time() || ! hash_equals( $row->proof, hash( 'sha256', $request['verifier'] ) ) ) { return $this->error( 'oauth_state', 'This sign-in expired or belongs to another browser. Start again.', 401 ); }
        if ( $row->link_user_id && ( (int) $row->link_user_id !== get_current_user_id() || is_wp_error( ( new Api() )->member_permission() ) ) ) { return $this->error( 'oauth_link_session', 'Sign in to your Riffit account again before linking WordPress.com.', 401 ); }
        $claimed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET consumed=1 WHERE token_hash=%s AND consumed=0 AND expires_at>%s', $table, $hash, gmdate( 'Y-m-d H:i:s' ) ) );
        if ( 1 !== $claimed ) { return $this->error( 'oauth_state', 'This sign-in has already been used. Start again.', 401 ); }
        $response = wp_remote_post( 'https://public-api.wordpress.com/oauth2/token', array( 'timeout' => 15, 'redirection' => 0, 'body' => array( 'client_id' => get_option( 'riffit_wpcom_client_id' ), 'client_secret' => get_option( 'riffit_wpcom_secret' ), 'code' => $request['code'], 'grant_type' => 'authorization_code', 'redirect_uri' => $this->redirect_uri() ) ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return $this->error( 'oauth_exchange', 'WordPress.com could not complete this sign-in. Start again.', 401 ); }
        $token = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $token ) || empty( $token['access_token'] ) || ! is_string( $token['access_token'] ) || strlen( $token['access_token'] ) > 4000 || 'bearer' !== strtolower( $token['token_type'] ?? '' ) || 'auth' !== ( $token['scope'] ?? '' ) ) { return $this->error( 'oauth_scope', 'WordPress.com returned an unexpected sign-in response.', 401 ); }
        $response = wp_remote_get( 'https://public-api.wordpress.com/rest/v1.1/me', array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] ) ) );
        unset( $token );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return $this->error( 'oauth_profile', 'Could not verify your WordPress.com identity.', 401 ); }
        $profile = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $profile ) || true !== ( $profile['verified'] ?? false ) || empty( $profile['ID'] ) || ! preg_match( '/^[1-9][0-9]{0,19}$/', (string) $profile['ID'] ) || ! is_email( $profile['email'] ?? '' ) ) { return $this->error( 'oauth_unverified', 'Verify your WordPress.com email before using it to sign in.', 403 ); }
        $subject = (string) $profile['ID']; $identities = $wpdb->prefix . 'riffit_identities';
        $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id FROM %i WHERE provider=%s AND subject=%s', $identities, 'wordpress.com', $subject ) );
        if ( $existing && $existing->user_id ) {
            if ( $row->link_user_id && (int) $existing->user_id !== (int) $row->link_user_id ) { return $this->error( 'oauth_linked', 'This WordPress.com account is already linked to another member.', 409 ); }
            $user = get_userdata( $existing->user_id );
            if ( ! $user ) { return $this->error( 'oauth_account', 'This member account is unavailable.', 403 ); }
        } else {
            if ( $existing ) { return $this->error( 'oauth_busy', 'This identity is being linked. Try again shortly.', 503 ); }
            // Reserve the immutable subject before provisioning. Email never silently links an existing account.
            $reserved = $wpdb->insert( $identities, array( 'provider' => 'wordpress.com', 'subject' => $subject, 'user_id' => 0, 'created_at' => current_time( 'mysql', true ) ) );
            if ( 1 !== $reserved ) { return $this->error( 'oauth_busy', 'This identity is being linked. Try again shortly.', 503 ); }
            $release = static function () use ( $wpdb, $identities, $subject ) { $wpdb->delete( $identities, array( 'provider' => 'wordpress.com', 'subject' => $subject, 'user_id' => 0 ) ); };
            if ( $row->link_user_id ) { $user = get_userdata( $row->link_user_id ); }
            else {
                if ( get_user_by( 'email', $profile['email'] ) ) { $release(); return $this->error( 'oauth_link_required', 'Sign in with your email first, then connect WordPress.com from your profile.', 409 ); }
                $handle = 'riffit-' . bin2hex( random_bytes( 6 ) );
                $id = wp_insert_user( array( 'user_login' => $handle, 'user_nicename' => $handle, 'display_name' => $handle, 'user_email' => strtolower( $profile['email'] ), 'user_pass' => wp_generate_password( 48 ), 'role' => 'subscriber' ) );
                if ( is_wp_error( $id ) ) { $release(); return $this->error( 'oauth_account', 'Could not prepare your account. Start again.', 503 ); }
                $user = get_userdata( $id );
            }
            if ( ! $user ) { $release(); return $this->error( 'oauth_account', 'This member account is unavailable.', 403 ); }
            $saved = $wpdb->update( $identities, array( 'user_id' => $user->ID ), array( 'provider' => 'wordpress.com', 'subject' => $subject, 'user_id' => 0 ) );
            if ( 1 !== $saved ) { $release(); return $this->error( 'oauth_busy', 'Could not link this identity. Start again.', 503 ); }
        }
        if ( $native ) { return array( 'linked' => true ); }
        $session = ( new Auth() )->session_for_user( $user, 'wordpress' );
        if ( is_wp_error( $session ) ) { return $session; }
        $session['linked'] = (bool) $row->link_user_id;
        return $session;
    }
    private function admin_proof_cookie( string $value, int $expires ): void {
        setcookie( 'riffit_admin_wp_proof', $value, array( 'expires' => $expires, 'path' => ADMIN_COOKIE_PATH, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
    }
    public function admin_link_start(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access is required.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'riffit_admin_wordpress_link' );
        $verifier = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        $request = new \WP_REST_Request( 'POST' );
        $request->set_body_params( array( 'mode' => 'link', 'proof' => hash( 'sha256', $verifier ) ) );
        $start = $this->start( $request, true );
        if ( is_wp_error( $start ) ) { wp_safe_redirect( admin_url( 'options-general.php?page=riffit&riffit_wordpress_linked=failed' ) ); exit; }
        $this->admin_proof_cookie( $verifier, time() + 10 * MINUTE_IN_SECONDS );
        nocache_headers();
        header( 'Referrer-Policy: no-referrer' );
        wp_redirect( $start['url'] ); exit;
    }
    public function admin_link_complete(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access is required.', '', array( 'response' => 403 ) ); }
        $state = wp_unslash( $_GET['state'] ?? '' ); $code = wp_unslash( $_GET['code'] ?? '' ); $verifier = wp_unslash( $_COOKIE['riffit_admin_wp_proof'] ?? '' );
        $valid = is_string( $state ) && preg_match( '/^admin-[a-f0-9]{64}$/', $state ) && is_string( $code ) && strlen( $code ) > 0 && strlen( $code ) <= 2000 && is_string( $verifier ) && preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $verifier );
        $result = null;
        if ( $valid ) {
            $request = new \WP_REST_Request( 'POST' ); $request->set_body_params( compact( 'state', 'code', 'verifier' ) );
            $result = $this->consume( $request, true );
        }
        if ( is_array( $result ) && ! empty( $result['linked'] ) ) { $this->admin_proof_cookie( '', time() - HOUR_IN_SECONDS ); }
        nocache_headers();
        header( 'Referrer-Policy: no-referrer' );
        wp_safe_redirect( admin_url( 'options-general.php?page=riffit&riffit_wordpress_linked=' . ( is_array( $result ) && ! empty( $result['linked'] ) ? '1' : 'failed' ) ) ); exit;
    }
    public function render_admin_link(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<h2>Your administrator sign-in</h2><p>Site administrators use WordPress.com sign-in in Riffit. Enable two-step authentication on your WordPress.com account before connecting it here. Normal WordPress admin access stays available.</p>';
        $notice = $_GET['riffit_wordpress_linked'] ?? '';
        if ( '1' === $notice ) { echo '<div class="notice notice-success"><p>WordPress.com connected to this administrator account. You can now use WordPress.com sign-in on Riffit.</p></div>'; }
        elseif ( 'failed' === $notice ) { echo '<div class="notice notice-error"><p>The connection did not finish. Stay signed in to WordPress here and try again.</p></div>'; }
        if ( ! $this->configured() ) { echo '<p>Configure and save the WordPress.com application above first.</p>'; return; }
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="riffit_wordpress_link">';
        wp_nonce_field( 'riffit_admin_wordpress_link' );
        submit_button( 'Connect my WordPress.com account', 'secondary' );
        echo '</form>';
    }

    public function cleanup(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at<%s', $wpdb->prefix . 'riffit_oauth_states', gmdate( 'Y-m-d H:i:s' ) ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id=0 AND created_at<%s', $wpdb->prefix . 'riffit_identities', gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ) );
    }
    public function settings(): void {
        register_setting( 'riffit', 'riffit_wpcom_enabled', array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ) );
        register_setting( 'riffit', 'riffit_wpcom_client_id', array( 'type' => 'string', 'sanitize_callback' => static fn( $value ) => preg_match( '/^[1-9][0-9]*$/', (string) $value ) ? (string) $value : '' ) );
        register_setting( 'riffit', 'riffit_wpcom_secret', array( 'type' => 'string', 'sanitize_callback' => static fn( $value ) => '' === trim( (string) $value ) ? get_option( 'riffit_wpcom_secret', '' ) : sanitize_text_field( $value ) ) );
        add_settings_section( 'riffit_wpcom', 'WordPress.com sign-in', static function () { echo '<p>Use a dedicated WordPress.com Connect application with identity-only access. Its secret stays on this backend and is never sent to the frontend.</p>'; }, 'riffit' );
        add_settings_field( 'riffit_wpcom_credentials', 'Connect application', array( $this, 'render_settings' ), 'riffit', 'riffit_wpcom' );
    }
    public function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<p><label>Client ID <input name="riffit_wpcom_client_id" inputmode="numeric" value="' . esc_attr( get_option( 'riffit_wpcom_client_id', '' ) ) . '"></label></p>';
        echo '<p><label>Client secret <input type="password" name="riffit_wpcom_secret" value="" autocomplete="new-password" placeholder="' . ( get_option( 'riffit_wpcom_secret' ) ? 'Configured; leave blank to keep' : 'Paste your application secret' ) . '"></label></p>';
        echo '<input type="hidden" name="riffit_wpcom_enabled" value="0"><p><label><input type="checkbox" name="riffit_wpcom_enabled" value="1" ' . checked( get_option( 'riffit_wpcom_enabled', false ), true, false ) . '> Enable WordPress.com sign-in</label></p>';
        echo '<p>Register this exact redirect URI: <code>' . esc_html( $this->redirect_uri() ) . '</code></p>';
    }
}

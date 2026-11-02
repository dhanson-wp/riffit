<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function riffit_admin_oauth_checks( int $admin_id, int $member_id, array &$provider_profile, int &$provider_calls ): void {
    global $wpdb;
    $connect = new Riffit\WordPressConnect();
    $verifier = str_repeat( 'n', 43 );
    $start_request = new WP_REST_Request( 'POST' );
    $start_request->set_body_params( array( 'mode' => 'link', 'proof' => hash( 'sha256', $verifier ) ) );
    riffit_user( 0 );
    riffit_check( is_wp_error( $connect->start( $start_request, true ) ), 'native administrator linking rejects anonymous initiation' );
    riffit_user( $member_id );
    riffit_check( is_wp_error( $connect->start( $start_request, true ) ), 'ordinary member cannot initiate native administrator linking' );
    riffit_user( $admin_id );
    $started = $connect->start( $start_request, true );
    parse_str( wp_parse_url( $started['url'], PHP_URL_QUERY ), $query );
    $state = $query['state'];
    riffit_check( (bool) preg_match( '/^admin-[a-f0-9]{64}$/', $state ) && 'auth' === $query['scope'], 'native administrator flow has a distinct random state and identity-only scope' );
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s', $wpdb->prefix . 'riffit_oauth_states', hash( 'sha256', $state ) ) );
    riffit_check( $row && $admin_id === (int) $row->link_user_id && $row->proof === hash( 'sha256', $verifier ), 'native state is bound to the initiating administrator and browser proof' );
    $consume = new WP_REST_Request( 'POST' );
    $consume->set_body_params( array( 'state' => $state, 'code' => 'native-test-code', 'verifier' => $verifier ) );
    $calls_before = $provider_calls;
    riffit_check( 400 === riffit_call( 'POST', '/auth/wordpress/consume', $consume->get_body_params() )->get_status(), 'public REST callback cannot consume native administrator states' );
    riffit_check( is_wp_error( $connect->consume( $consume ) ) && $calls_before === $provider_calls, 'ordinary callback mode rejects native state before contacting provider' );
    riffit_user( $member_id );
    riffit_check( is_wp_error( $connect->consume( $consume, true ) ) && $calls_before === $provider_calls, 'native completion requires the original WordPress administrator session' );
    riffit_user( $admin_id );
    $consume->set_body_params( array( 'state' => $state, 'code' => 'native-test-code', 'verifier' => str_repeat( 'x',43 ) ) );
    riffit_check( is_wp_error( $connect->consume( $consume, true ) ) && $calls_before === $provider_calls, 'native completion rejects a different browser proof before provider exchange' );
    $consume->set_body_params( array( 'state' => $state, 'code' => 'native-test-code', 'verifier' => $verifier ) );
    $provider_profile = array( 'ID' => 99009, 'email' => get_userdata( $admin_id )->user_email, 'verified' => true );
    $sessions_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id=%d', $wpdb->prefix . 'riffit_sessions', $admin_id ) );
    $linked = $connect->consume( $consume, true );
    riffit_check( is_array( $linked ) && true === $linked['linked'] && ! isset( $linked['token'] ), 'native linking succeeds without issuing a frontend session or exposing a token' );
    $identity = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM %i WHERE provider=%s AND subject=%s', $wpdb->prefix . 'riffit_identities', 'wordpress.com', '99009' ) );
    riffit_check( $admin_id === (int) $identity && $sessions_before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id=%d', $wpdb->prefix . 'riffit_sessions', $admin_id ) ), 'native link binds immutable provider subject to existing administrator without an orphan session' );
    riffit_check( is_wp_error( $connect->consume( $consume, true ) ), 'native administrator link state cannot be replayed' );
    riffit_user( 0 );
    $signin_state = riffit_oauth_state( hash( 'sha256', $verifier ) );
    $signed_in = riffit_call( 'POST', '/auth/wordpress/consume', array( 'state' => $signin_state, 'code' => 'native-linked-signin', 'verifier' => $verifier ) );
    riffit_check( 200 === $signed_in->get_status() && $admin_id === $signed_in->get_data()['user']['id'], 'administrator linked through WordPress can subsequently sign in through the branded provider flow' );
    require_once ABSPATH . 'wp-admin/includes/template.php';
    riffit_user( $admin_id ); ob_start(); $connect->render_admin_link(); $html = ob_get_clean();
    riffit_check( str_contains( $html, '_wpnonce' ) && str_contains( $html, 'riffit_wordpress_link' ) && ! str_contains( $html, 'test-only-client-secret' ), 'native administrator linking form uses a nonce and does not echo the provider secret' );
}

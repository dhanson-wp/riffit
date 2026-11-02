<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Runs inside the real WordPress integration fixture, restoring the member afterward. */
function riffit_profile_avatar_checks( int $member_id, int $topic_id, int $other_id ): void {
    $member = get_userdata( $member_id );
    $saved_name = $member->display_name;
    $saved_bio = get_user_meta( $member_id, 'riffit_bio', true );
    $had_avatar = metadata_exists( 'user', $member_id, 'riffit_avatar_id' );
    $saved_avatar = get_user_meta( $member_id, 'riffit_avatar_id', true );
    $other_avatar = get_user_meta( $other_id, 'riffit_avatar_id', true );
    riffit_user( 0 );
    riffit_check( 401 === riffit_call( 'PUT', '/profile', array( 'name' => 'Anonymous', 'avatar_id' => 'chef' ) )->get_status(), 'anonymous visitors cannot change a profile avatar' );
    riffit_user( $member_id );
    delete_user_meta( $member_id, 'riffit_avatar_id' );
    riffit_check( 'original' === riffit_call( 'GET', '/me' )->get_data()['avatar_id'], 'existing members without an avatar get the original gecko' );
    foreach ( Riffit\SocialApi::AVATAR_IDS as $avatar ) {
        $response = riffit_call( 'PUT', '/profile', array( 'name' => $saved_name, 'bio' => $saved_bio, 'avatar_id' => $avatar, 'id' => $other_id ) );
        riffit_check( 200 === $response->get_status() && $avatar === get_user_meta( $member_id, 'riffit_avatar_id', true ) && $avatar === $response->get_data()['avatar_id'], 'member can persist bundled gecko outfit: ' . $avatar );
    }
    riffit_call( 'PUT', '/profile', array( 'name' => $saved_name, 'bio' => $saved_bio, 'avatar_id' => 'chef' ) );
    riffit_check( $other_avatar === get_user_meta( $other_id, 'riffit_avatar_id', true ), 'profile mutation ignores a supplied target member ID' );
    riffit_check( 'chef' === riffit_call( 'GET', '/me' )->get_data()['avatar_id'], 'authenticated member data exposes the saved avatar' );
    $response = riffit_call( 'PUT', '/profile', array( 'name' => $saved_name, 'bio' => 'Updated public bio.' ) );
    riffit_check( 200 === $response->get_status() && 'chef' === $response->get_data()['avatar_id'] && 'Updated public bio.' === $response->get_data()['bio'], 'legacy profile updates preserve the avatar and return saved bio' );
    foreach ( array( 'https://example.test/avatar.png', '../secret', '<script>alert(1)</script>', '', array( 'chef' ) ) as $invalid ) {
        riffit_check( 400 === riffit_call( 'PUT', '/profile', array( 'name' => 'Rejected avatar', 'avatar_id' => $invalid ) )->get_status(), 'invalid avatar input rejected before profile mutation' );
    }
    riffit_check( $saved_name === get_userdata( $member_id )->display_name && 'chef' === get_user_meta( $member_id, 'riffit_avatar_id', true ), 'invalid avatar requests do not partially mutate the profile' );
    riffit_user( 0 );
    $public = riffit_call( 'GET', '/profiles/' . $member->user_nicename )->get_data();
    riffit_check( 'chef' === $public['avatar_id'] && ! isset( $public['email'] ) && ! isset( $public['user_email'] ), 'anonymous public profile read returns avatar without email' );
    riffit_check( 'chef' === riffit_call( 'GET', '/posts/' . $topic_id )->get_data()['author_avatar_id'], 'public post author avatar matches the selected member avatar' );
    update_user_meta( $member_id, 'riffit_avatar_id', 'unrecognized-legacy-value' );
    riffit_check( 'original' === riffit_call( 'GET', '/profiles/' . $member->user_nicename )->get_data()['avatar_id'], 'invalid legacy avatar meta falls back to the bundled original' );
    wp_update_user( array( 'ID' => $member_id, 'display_name' => $saved_name ) );
    update_user_meta( $member_id, 'riffit_bio', $saved_bio );
    if ( $had_avatar ) { update_user_meta( $member_id, 'riffit_avatar_id', $saved_avatar ); }
    else { delete_user_meta( $member_id, 'riffit_avatar_id' ); }
    riffit_user( $member_id );
}

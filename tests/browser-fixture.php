<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Disposable local data only. Outbound email is intercepted before any fixture action.
$mail = <<<'PHP'
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_filter( 'pre_wp_mail', static function ( $result, $attributes ) {
    file_put_contents( '/riffit-results/browser-test-email.txt', $attributes['message'] );
    return true;
}, 10, 2 );
PHP;
wp_mkdir_p( ABSPATH . 'wp-content/mu-plugins' );
file_put_contents( ABSPATH . 'wp-content/mu-plugins/riffit-local-mail.php', $mail );
add_filter( 'pre_wp_mail', '__return_true' );
update_option( 'riffit_frontend_url', 'http://localhost:3100' );
function fixture_call( $method, $path, $params = array() ) {
    $request = new WP_REST_Request( $method, '/riffit/v1' . $path );
    $request->set_body_params( $params );
    $response = rest_do_request( $request );
    if ( $response->get_status() >= 400 ) { throw new RuntimeException( wp_json_encode( $response->get_data() ) ); }
    return $response->get_data();
}
$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID ); buddypress()->loggedin_user->id = $admin->ID;
$group = fixture_call( 'POST', '/communities', array( 'name' => 'Everyday design', 'slug' => 'everyday-design', 'description' => 'Small details, thoughtful tools, and things worth noticing.' ) );
$titles = array( 'What small design detail made your day better?', 'Show us the tool you keep coming back to', 'A good empty state is an invitation', 'What makes a community feel like home?', 'One less click can make all the difference', 'The best interface is the one you understand', 'What are you making this weekend?' );
foreach ( $titles as $title ) {
    $post = fixture_call( 'POST', "/communities/{$group['id']}/posts", array( 'title' => $title, 'content' => 'I keep noticing the tools that quietly get out of the way. What is one detail you wish more products borrowed?' ) );
}
fixture_call( 'POST', "/posts/{$post['id']}/replies", array( 'content' => 'A keyboard shortcut that works everywhere, without making you hunt for it.' ) );
fixture_call( 'PUT', "/objects/{$post['id']}/vote", array( 'value' => 1 ) );
$member = wp_insert_user( array( 'user_login' => 'lens-friend', 'user_pass' => wp_generate_password( 40 ), 'user_email' => 'lens-friend@example.test', 'role' => 'subscriber', 'display_name' => 'Lens friend' ) );
update_user_meta( $member, 'riffit_email_verified', 1 );
update_user_meta( $member, 'riffit_bio', 'I notice small things.' );
$mailthread = fixture_call( 'POST', '/messages', array( 'recipient' => 'lens-friend', 'content' => 'Welcome to the local Riffit test community.' ) );
file_put_contents( '/riffit-results/browser-fixture.json', wp_json_encode( array( 'community' => $group['id'], 'post' => $post['id'], 'thread' => $mailthread['id'] ) ) );
wp_set_current_user( 0 );

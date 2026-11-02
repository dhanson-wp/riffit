<?php
namespace Riffit;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MessagesApi extends SocialApi {
    public function register(): void {
        parent::register();
        $member = array( $this, 'member_permission' );
        $id = array( 'type' => 'integer', 'minimum' => 1, 'required' => true );
        $page = array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 );
        $content = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 10000, 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' );
        $this->route( '/messages', 'GET', 'inbox', $member, array( 'page' => $page ) );
        $this->route( '/messages', 'POST', 'new_message', $member, array( 'recipient' => array( 'type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]+$', 'required' => true ), 'content' => $content ) );
        $this->route( '/messages/(?P<id>\d+)', 'GET', 'thread', $member, array( 'id' => $id, 'page' => $page ) );
        $this->route( '/messages/(?P<id>\d+)', 'POST', 'reply_message', $member, array( 'id' => $id, 'content' => $content ) );
        $this->route( '/messages/(?P<id>\d+)/read', 'PUT', 'read_thread', $member, array( 'id' => $id ) );
        $this->route( '/members/(?P<id>\d+)/block', 'PUT', 'block_member', $member, array( 'id' => $id, 'blocked' => array( 'type' => 'boolean', 'required' => true ) ) );
    }

    protected function blocked( int $other ): bool {
        global $wpdb;
        $me = get_current_user_id();
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM %i WHERE (user_id=%d AND blocked_id=%d) OR (user_id=%d AND blocked_id=%d) LIMIT 1', $wpdb->prefix . 'riffit_blocks', $me, $other, $other, $me ) );
    }

    public function block_member( \WP_REST_Request $request ) {
        global $wpdb;
        $id = (int) $request['id'];
        if ( $id === get_current_user_id() || ! get_userdata( $id ) ) { return $this->error( 'invalid_member', 'Choose another member.' ); }
        if ( $request['blocked'] ) {
            $result = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,blocked_id) VALUES (%d,%d) ON DUPLICATE KEY UPDATE blocked_id=VALUES(blocked_id)', $wpdb->prefix . 'riffit_blocks', get_current_user_id(), $id ) );
        } else { $result = $wpdb->delete( $wpdb->prefix . 'riffit_blocks', array( 'user_id' => get_current_user_id(), 'blocked_id' => $id ), array( '%d', '%d' ) ); }
        return false === $result ? $this->error( 'block_failed', 'Could not update this setting.', 500 ) : array( 'blocked' => (bool) $request['blocked'] );
    }

    protected function accessible_thread( int $id ) {
        if ( ! class_exists( '\BP_Messages_Thread' ) ) { return $this->error( 'dependency', 'Private messages are unavailable.', 503 ); }
        if ( ! \BP_Messages_Thread::check_access( $id, get_current_user_id() ) ) { return $this->error( 'not_found', 'Conversation not found.', 404 ); }
        $recipients = \BP_Messages_Thread::get_recipients_for_thread( $id );
        if ( 2 !== count( $recipients ) ) { return $this->error( 'not_found', 'Conversation not found.', 404 ); }
        return $recipients;
    }

    protected function thread_summary( $thread ): array {
        $people = array();
        foreach ( $thread->recipients as $recipient ) {
            $user = get_userdata( $recipient->user_id );
            if ( $user ) { $people[] = array( 'id' => $user->ID, 'handle' => $user->user_nicename, 'name' => $user->display_name ); }
        }
        return array( 'id' => (int) $thread->thread_id, 'people' => $people, 'unread' => (int) $thread->unread_count, 'preview' => wp_strip_all_tags( $thread->last_message_content ), 'updated_at' => mysql2date( 'c', $thread->last_message_date, false ) );
    }

    public function inbox( \WP_REST_Request $request ) {
        if ( ! class_exists( '\BP_Messages_Thread' ) ) { return $this->error( 'dependency', 'Private messages are unavailable.', 503 ); }
        $data = \BP_Messages_Thread::get_current_threads_for_user( array( 'user_id' => get_current_user_id(), 'box' => 'all', 'limit' => 20, 'page' => $request['page'], 'messages_page' => 1, 'messages_per_page' => 1 ) );
        if ( ! $data ) { $data = array( 'threads' => array(), 'total' => 0 ); }
        $items = array();
        foreach ( $data['threads'] as $thread ) {
            if ( ! is_wp_error( $this->accessible_thread( (int) $thread->thread_id ) ) ) { $items[] = $this->thread_summary( $thread ); }
        }
        return array( 'items' => $items, 'page' => (int) $request['page'], 'pages' => (int) ceil( $data['total'] / 20 ), 'unread' => messages_get_unread_count( get_current_user_id() ) );
    }

    public function thread( \WP_REST_Request $request ) {
        $allowed = $this->accessible_thread( (int) $request['id'] );
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        $thread = new \BP_Messages_Thread( (int) $request['id'], 'DESC', array( 'user_id' => get_current_user_id(), 'page' => $request['page'], 'per_page' => 30 ) );
        $data = $this->thread_summary( $thread );
        $data['messages'] = array();
        foreach ( array_reverse( $thread->messages ) as $message ) {
            $user = get_userdata( $message->sender_id );
            $data['messages'][] = array( 'id' => (int) $message->id, 'sender_id' => (int) $message->sender_id, 'handle' => $user ? $user->user_nicename : 'deleted', 'content' => wp_strip_all_tags( $message->message ), 'created_at' => mysql2date( 'c', $message->date_sent, false ) );
        }
        global $wpdb;
        $data['blocked_by_me'] = false;
        foreach ( $thread->recipients as $recipient ) {
            if ( (int) $recipient->user_id !== get_current_user_id() ) { $data['blocked_by_me'] = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT blocked_id FROM %i WHERE user_id=%d AND blocked_id=%d', $wpdb->prefix . 'riffit_blocks', get_current_user_id(), $recipient->user_id ) ); }
        }
        $data['page'] = (int) $request['page'];
        $data['pages'] = (int) ceil( $thread->messages_total_count / 30 );
        return $data;
    }

    public function new_message( \WP_REST_Request $request ) {
        $user = get_user_by( 'slug', $request['recipient'] );
        if ( ! $user || get_current_user_id() === $user->ID || ( ! get_user_meta( $user->ID, 'riffit_email_verified', true ) && ! user_can( $user, 'manage_options' ) ) || ! bp_is_user_active( $user->ID ) ) { return $this->error( 'invalid_recipient', 'This member cannot receive messages.' ); }
        if ( $this->blocked( $user->ID ) ) { return $this->error( 'message_unavailable', 'Messaging is unavailable between these accounts.', 403 ); }
        return $this->send_message( $request, 0, $user->ID );
    }

    public function reply_message( \WP_REST_Request $request ) {
        $recipients = $this->accessible_thread( (int) $request['id'] );
        if ( is_wp_error( $recipients ) ) { return $recipients; }
        foreach ( $recipients as $recipient ) { if ( $this->blocked( (int) $recipient->user_id ) ) { return $this->error( 'message_unavailable', 'Messaging is unavailable between these accounts.', 403 ); } }
        return $this->send_message( $request, (int) $request['id'], 0 );
    }

    protected function send_message( \WP_REST_Request $request, int $thread, int $recipient ) {
        if ( ! trim( $request['content'] ) ) { return $this->error( 'empty_message', 'Write a message before sending.' ); }
        if ( ( new Auth() )->limited( 'messages:' . get_current_user_id(), 60, HOUR_IN_SECONDS ) ) { return $this->error( 'rate_limited', 'You are sending messages too quickly. Try again later.', 429 ); }
        $id = messages_new_message( array( 'sender_id' => get_current_user_id(), 'thread_id' => $thread ?: false, 'recipients' => $recipient ? array( $recipient ) : array(), 'subject' => 'Riffit conversation', 'content' => $request['content'], 'error_type' => 'wp_error' ) );
        return ! $id || is_wp_error( $id ) ? $this->error( 'send_failed', 'Could not send this message.', 500 ) : new \WP_REST_Response( array( 'id' => (int) $id ), 201 );
    }

    public function read_thread( \WP_REST_Request $request ) {
        $allowed = $this->accessible_thread( (int) $request['id'] );
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        \BP_Messages_Thread::mark_as_read( (int) $request['id'], get_current_user_id() );
        return array( 'read' => true );
    }
}

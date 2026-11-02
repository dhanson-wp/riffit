<?php
namespace Riffit;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class NotificationsApi extends ContentApi {
    public function register(): void {
        parent::register();
        $member = array( $this, 'member_permission' );
        $this->route( '/notifications', 'GET', 'notifications', $member, array( 'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ) ) );
        $this->route( '/notifications/(?P<id>\d+)/read', 'PUT', 'read_notification', $member, array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ) ) );
        $this->route( '/notifications/count', 'GET', 'notification_count', $member );
        $this->route( '/notifications/read', 'PUT', 'read_notifications', $member );
    }

    public function create_post( \WP_REST_Request $request ) {
        $response = parent::create_post( $request );
        if ( $response instanceof \WP_REST_Response && 201 === $response->get_status() ) {
            $post = get_post( $response->get_data()['id'] );
            $this->notify_people( $post, array() );
        }
        return $response;
    }

    public function create_reply( \WP_REST_Request $request ) {
        if ( ( new Auth() )->limited( 'reply:' . get_current_user_id(), 200, HOUR_IN_SECONDS ) ) { return $this->error( 'rate_limited', 'You are replying too quickly. Try again later.', 429 ); }
        $response = parent::create_reply( $request );
        if ( $response instanceof \WP_REST_Response && 201 === $response->get_status() ) {
            $people = array(); $topic = get_post( (int) $request['id'] );
            if ( $topic ) { $people[(int) $topic->post_author] = 'reply'; }
            if ( $request['parent'] ) { $parent = get_post( (int) $request['parent'] ); if ( $parent ) { $people[(int) $parent->post_author] = 'reply'; } }
            $this->notify_people( get_post( $response->get_data()['id'] ), $people );
        }
        return $response;
    }

    protected function notify_people( $post, array $people ): void {
        if ( ! function_exists( 'bp_notifications_add_notification' ) ) { return; }
        preg_match_all( '/(?<![a-zA-Z0-9_@])@([a-zA-Z0-9_-]{1,60})\b/', wp_strip_all_tags( $post->post_title . ' ' . $post->post_content ), $matches );
        foreach ( array_slice( array_unique( $matches[1] ), 0, 10 ) as $handle ) {
            $user = get_user_by( 'slug', $handle );
            if ( $user && ! isset( $people[$user->ID] ) ) { $people[$user->ID] = 'mention'; }
        }
        $topic = bbp_get_reply_post_type() === $post->post_type ? bbp_get_reply_topic_id( $post->ID ) : $post->ID;
        $group = (int) get_post_meta( bbp_get_topic_forum_id( $topic ), '_riffit_group_id', true );
        foreach ( $people as $id => $action ) {
            $id = (int) $id;
            if ( $id === get_current_user_id() || ! get_userdata( $id ) || $this->blocked( $id ) || groups_is_user_banned( $id, $group ) || ! bp_is_user_active( $id ) || ( ! get_user_meta( $id, 'riffit_email_verified', true ) && ! user_can( $id, 'manage_options' ) ) ) { continue; }
            bp_notifications_add_notification( array( 'user_id' => $id, 'item_id' => $post->ID, 'secondary_item_id' => get_current_user_id(), 'component_name' => 'riffit', 'component_action' => $action, 'is_new' => true, 'allow_duplicate' => false ) );
        }
    }

    public function notifications( \WP_REST_Request $request, bool $count_only = false ) {
        global $wpdb;
        $bp = buddypress(); $user = get_current_user_id();
        // Visibility and blocking are checked before count/pagination, not after returning a page.
        $from = $wpdb->prepare( ' FROM %i n INNER JOIN %i o ON o.ID=n.item_id INNER JOIN %i t ON t.ID=CASE WHEN o.post_type=%s THEN o.post_parent ELSE o.ID END INNER JOIN %i f ON f.ID=t.post_parent INNER JOIN %i fm ON fm.post_id=f.ID AND fm.meta_key=%s INNER JOIN %i g ON g.id=CAST(fm.meta_value AS UNSIGNED) INNER JOIN %i gm ON gm.group_id=g.id AND gm.meta_key=%s AND CAST(gm.meta_value AS UNSIGNED)=f.ID', $bp->notifications->table_name, $wpdb->posts, $wpdb->posts, bbp_get_reply_post_type(), $wpdb->posts, $wpdb->postmeta, '_riffit_group_id', $bp->groups->table_name, $bp->groups->table_name_groupmeta, 'riffit_forum_id' );
        $where = $wpdb->prepare( ' WHERE n.user_id=%d AND n.component_name=%s AND n.component_action IN (%s,%s) AND o.post_type IN (%s,%s) AND o.post_status IN (%s,%s) AND t.post_type=%s AND t.post_status IN (%s,%s) AND f.post_type=%s AND f.post_status=%s AND g.status=%s AND NOT EXISTS (SELECT 1 FROM %i b WHERE (b.user_id=%d AND b.blocked_id=n.secondary_item_id) OR (b.blocked_id=%d AND b.user_id=n.secondary_item_id))', $user, 'riffit', 'reply', 'mention', bbp_get_topic_post_type(), bbp_get_reply_post_type(), 'publish', bbp_get_closed_status_id(), bbp_get_topic_post_type(), 'publish', bbp_get_closed_status_id(), bbp_get_forum_post_type(), 'publish', 'public', $wpdb->prefix . 'riffit_blocks', $user, $user );
        $total = $count_only ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT n.id)' . $from . $where );
        $unread = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT n.id)' . $from . $where . ' AND n.is_new=1' );
        if ( $count_only ) { return array( 'unread' => $unread ); }
        $rows = $wpdb->get_results( 'SELECT DISTINCT n.*,t.ID AS topic_id,t.post_title AS title,g.id AS community_id,o.post_type AS object_type' . $from . $where . $wpdb->prepare( ' ORDER BY n.id DESC LIMIT %d OFFSET %d', 30, ( (int) $request['page'] - 1 ) * 30 ) );
        cache_users( array_unique( wp_list_pluck( $rows, 'secondary_item_id' ) ) );
        $items = array();
        foreach ( $rows as $row ) {
            $actor = get_userdata( $row->secondary_item_id );
            $items[] = array( 'id' => (int) $row->id, 'action' => $row->component_action, 'actor' => $actor ? $actor->user_nicename : 'deleted', 'post_id' => (int) $row->topic_id, 'comment_id' => bbp_get_reply_post_type() === $row->object_type ? (int) $row->item_id : 0, 'title' => wp_strip_all_tags( $row->title ), 'community_id' => (int) $row->community_id, 'read' => ! (bool) $row->is_new, 'created_at' => mysql2date( 'c', $row->date_notified, false ) );
        }
        return array( 'items' => $items, 'unread' => $unread, 'page' => (int) $request['page'], 'pages' => (int) ceil( $total / 30 ) );
    }

    public function notification_count( \WP_REST_Request $request ) { return $this->notifications( $request, true ); }

    public function read_notification( \WP_REST_Request $request ) {
        $item = bp_notifications_get_notification( (int) $request['id'] );
        if ( ! $item->id || (int) $item->user_id !== get_current_user_id() || 'riffit' !== $item->component_name ) { return $this->error( 'not_found', 'Notification not found.', 404 ); }
        \BP_Notifications_Notification::update( array( 'is_new' => false ), array( 'id' => $item->id, 'user_id' => get_current_user_id(), 'component_name' => 'riffit' ) );
        return array( 'read' => true );
    }

    public function read_notifications() {
        \BP_Notifications_Notification::update( array( 'is_new' => false ), array( 'user_id' => get_current_user_id(), 'component_name' => 'riffit' ) );
        return array( 'read' => true );
    }
}

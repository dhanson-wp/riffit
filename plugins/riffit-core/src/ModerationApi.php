<?php
namespace Riffit;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ModerationApi extends NotificationsApi {
    public function register(): void {
        parent::register();
        $member = array( $this, 'member_permission' );
        $id = array( 'type' => 'integer', 'minimum' => 1, 'required' => true );
        $page = array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 );
        $this->route( '/objects/(?P<id>\d+)/report', 'POST', 'report_object', $member, array( 'id' => $id, 'reason' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 1000, 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ) ) );
        $this->route( '/objects/(?P<id>\d+)', 'PUT', 'edit_object', $member, array( 'id' => $id, 'title' => array( 'type' => 'string', 'maxLength' => 300, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ), 'content' => array( 'type' => 'string', 'maxLength' => 40000, 'required' => true, 'sanitize_callback' => 'wp_kses_post' ), 'flair' => array( 'type' => 'string', 'maxLength' => 30, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ) ) );
        $this->route( '/objects/(?P<id>\d+)/moderation', 'PUT', 'moderate_object', $member, array( 'id' => $id, 'action' => array( 'type' => 'string', 'enum' => array( 'trash','restore','close','open' ), 'required' => true ) ) );
        $this->route( '/communities/(?P<id>\d+)/moderation', 'GET', 'moderation_queue', $member, array( 'id' => $id, 'page' => $page ) );
        $this->route( '/communities/(?P<id>\d+)/trash', 'GET', 'community_trash', $member, array( 'id' => $id, 'page' => $page ) );
        $this->route( '/communities/(?P<id>\d+)/members', 'GET', 'community_members', $member, array( 'id' => $id, 'page' => $page, 'q' => array( 'type' => 'string', 'maxLength' => 60, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ) ) );
        $this->route( '/communities/(?P<id>\d+)/members/(?P<user>\d+)', 'PUT', 'manage_member', $member, array( 'id' => $id, 'user' => $id, 'action' => array( 'type' => 'string', 'enum' => array( 'ban','unban','promote_mod','promote_admin','demote' ), 'required' => true ) ) );
        $this->route( '/communities/(?P<id>\d+)/settings', 'PUT', 'community_settings', $member, array( 'id' => $id, 'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ), 'description' => array( 'type' => 'string', 'maxLength' => 3000, 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ), 'rules' => array( 'type' => 'string', 'maxLength' => 4000, 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ) ) );
        $this->route( '/communities/(?P<id>\d+)/branding', 'POST', 'community_branding', $member, array( 'id' => $id, 'slot' => array( 'type' => 'string', 'enum' => array( 'icon', 'banner' ), 'required' => true ), 'name' => array( 'type' => 'string', 'maxLength' => 150, 'required' => true, 'sanitize_callback' => 'sanitize_file_name' ), 'data' => array( 'type' => 'string', 'maxLength' => 6990510, 'required' => true ), 'alt' => array( 'type' => 'string', 'maxLength' => 300, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ) ) );
        $this->route( '/communities/(?P<id>\d+)/branding', 'DELETE', 'clear_branding', $member, array( 'id' => $id, 'slot' => array( 'type' => 'string', 'enum' => array( 'icon', 'banner' ), 'required' => true ) ) );
        $this->route( '/reports/(?P<id>\d+)', 'PUT', 'resolve_report', $member, array( 'id' => $id ) );
    }

    protected function can_admin( int $group ): bool { return current_user_can( 'manage_options' ) || (bool) groups_is_user_admin( get_current_user_id(), $group ); }
    protected function can_moderate( int $group ): bool { return $this->can_admin( $group ) || (bool) groups_is_user_mod( get_current_user_id(), $group ); }
    protected function group_data( $group ): array {
        return array_merge( parent::group_data( $group ), array( 'joined' => get_current_user_id() ? (bool) groups_is_user_member( get_current_user_id(), $group->id ) : false, 'can_admin' => $this->can_admin( (int) $group->id ), 'can_moderate' => $this->can_moderate( (int) $group->id ), 'rules' => (string) groups_get_groupmeta( $group->id, 'riffit_rules', true ), 'icon' => $this->branding_image( (int) $group->id, 'icon' ), 'banner' => $this->branding_image( (int) $group->id, 'banner' ), 'created_at' => mysql2date( 'c', $group->date_created, false ) ) );
    }
    protected function branding_image( int $group, string $slot ): ?array {
        $id = (int) groups_get_groupmeta( $group, 'riffit_' . $slot, true );
        if ( ! $id || ! wp_attachment_is_image( $id ) || (int) get_post_meta( $id, '_riffit_community', true ) !== $group || get_post_meta( $id, '_riffit_branding', true ) !== $slot ) { return null; }
        $size = 'banner' === $slot ? 'large' : 'thumbnail';
        $image = wp_get_attachment_image_src( $id, $size );
        return $image ? array( 'id' => $id, 'url' => $image[0], 'width' => (int) $image[1], 'height' => (int) $image[2], 'srcset' => wp_get_attachment_image_srcset( $id, $size ) ?: '', 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : null;
    }
    public function community_branding( \WP_REST_Request $request ) {
        $group = $this->public_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( ! $this->can_admin( (int) $group->id ) ) { return $this->error( 'forbidden', 'Only community admins can change its appearance.', 403 ); }
        $upload = $this->upload_image( $request );
        if ( is_wp_error( $upload ) ) { return $upload; }
        $image = $upload->get_data();
        update_post_meta( $image['id'], '_riffit_branding', $request['slot'] );
        groups_update_groupmeta( $group->id, 'riffit_' . $request['slot'], $image['id'] );
        return new \WP_REST_Response( $this->group_data( $group ), 201 );
    }
    public function clear_branding( \WP_REST_Request $request ) {
        $group = $this->public_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( ! $this->can_admin( (int) $group->id ) ) { return $this->error( 'forbidden', 'Only community admins can change its appearance.', 403 ); }
        groups_delete_groupmeta( $group->id, 'riffit_' . $request['slot'] );
        return $this->group_data( $group );
    }

    protected function post_data( $post ): array {
        $data = parent::post_data( $post );
        $group = (int) $data['community_id'];
        $data['can_edit'] = (int) $post->post_author === get_current_user_id() && ! groups_is_user_banned( get_current_user_id(), $group );
        $data['can_moderate'] = $this->can_moderate( $group );
        $data['can_remove'] = $data['can_edit'] || $data['can_moderate'];
        $topic = bbp_get_reply_post_type() === $post->post_type ? bbp_get_reply_topic_id( $post->ID ) : $post->ID;
        $data['closed'] = bbp_is_topic_closed( $topic );
        $data['can_edit'] = $data['can_edit'] && ! $data['closed'];
        return $data;
    }

    public function join_community( \WP_REST_Request $request ) {
        if ( $request['joined'] ) { return parent::join_community( $request ); }
        $group = $this->public_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( groups_is_user_admin( get_current_user_id(), $group->id ) && count( groups_get_group_admins( $group->id ) ) < 2 ) { return $this->error( 'last_admin', 'Add another community admin before leaving.', 409 ); }
        if ( groups_is_user_member( get_current_user_id(), $group->id ) && ! groups_leave_group( $group->id, get_current_user_id() ) ) { return $this->error( 'leave_failed', 'Could not leave this community.', 500 ); }
        return array( 'joined' => false, 'community_id' => (int) $group->id );
    }

    protected function managed_object( int $id, bool $trashed = false ) {
        $post = get_post( $id );
        if ( ! $post || ! in_array( $post->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) || ! in_array( $post->post_status, $trashed ? array( 'publish', bbp_get_closed_status_id(), 'trash' ) : array( 'publish', bbp_get_closed_status_id() ), true ) ) { return $this->error( 'not_found', 'Post or comment not found.', 404 ); }
        $topic = bbp_get_reply_post_type() === $post->post_type ? (int) bbp_get_reply_topic_id( $id ) : $id;
        $group = (int) get_post_meta( bbp_get_topic_forum_id( $topic ), '_riffit_group_id', true );
        $allowed = $this->public_group( $group );
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        if ( $topic !== $id && is_wp_error( $this->public_topic( $topic ) ) ) { return $this->error( 'not_found', 'Discussion not found.', 404 ); }
        return array( 'post' => $post, 'topic' => $topic, 'group' => $group );
    }

    public function edit_object( \WP_REST_Request $request ) {
        $object = $this->managed_object( (int) $request['id'] );
        if ( is_wp_error( $object ) ) { return $object; }
        $post = $object['post'];
        if ( (int) $post->post_author !== get_current_user_id() || groups_is_user_banned( get_current_user_id(), $object['group'] ) ) { return $this->error( 'forbidden', 'You can only edit your own posts and comments.', 403 ); }
        if ( bbp_is_topic_closed( $object['topic'] ) ) { return $this->error( 'closed', 'This discussion is closed.', 403 ); }
        $is_topic = bbp_get_topic_post_type() === $post->post_type;
        $meta = get_post_meta( $post->ID, '_riffit_post', true );
        if ( ( ! $is_topic || 'text' === ( $meta['kind'] ?? 'text' ) ) && ! trim( wp_strip_all_tags( $request['content'] ) ) ) { return $this->error( 'empty_post', 'Write something before saving.' ); }
        if ( $is_topic && ! trim( $request['title'] ) ) { return $this->error( 'empty_title', 'Give your post a title.' ); }
        $update = array( 'ID' => $post->ID, 'post_content' => $request['content'] );
        if ( $is_topic ) { $update['post_title'] = $request['title']; }
        $saved = wp_update_post( $update, true );
        if ( is_wp_error( $saved ) ) { return $this->error( 'edit_failed', 'Could not save your changes.', 500 ); }
        if ( $is_topic ) { if ( ! is_array( $meta ) ) { $meta = array( 'kind' => 'text' ); } $meta['flair'] = $request['flair']; update_post_meta( $post->ID, '_riffit_post', $meta ); }
        return $this->post_data( get_post( $post->ID ) );
    }

    public function moderate_object( \WP_REST_Request $request ) {
        $object = $this->managed_object( (int) $request['id'], true );
        if ( is_wp_error( $object ) ) { return $object; }
        $post = $object['post']; $admin = $this->can_moderate( $object['group'] ); $owner = (int) $post->post_author === get_current_user_id();
        if ( ! $admin && ( ! $owner || groups_is_user_banned( get_current_user_id(), $object['group'] ) ) ) { return $this->error( 'forbidden', 'You cannot change this discussion.', 403 ); }
        $action = $request['action']; $topic = bbp_get_topic_post_type() === $post->post_type;
        if ( in_array( $action, array( 'close','open' ), true ) && ( ! $admin || ! $topic || 'trash' === $post->post_status ) ) { return $this->error( 'forbidden', 'Only community moderators can open or close discussions.', 403 ); }
        if ( 'restore' === $action && ! $admin && get_post_meta( $post->ID, '_riffit_removed_by_mod', true ) ) { return $this->error( 'forbidden', 'A moderator must restore this item.', 403 ); }
        if ( 'trash' === $action && defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) { return $this->error( 'trash_disabled', 'Recoverable trash is disabled on this backend. Ask the site owner to enable it.', 503 ); }
        if ( 'trash' === $action ) {
            if ( 'trash' !== $post->post_status ) { update_post_meta( $post->ID, '_riffit_removed_by_mod', $admin && ! $owner ? 1 : 0 ); $result = wp_trash_post( $post->ID ); } else { $result = true; }
        } elseif ( 'restore' === $action ) {
            $restore_status = static function ( $status, $id, $previous ) use ( $post, $topic ) {
                if ( ( (int) $id === (int) $post->ID || ( $topic && (int) bbp_get_reply_topic_id( $id ) === (int) $post->ID ) ) && in_array( $previous, array( 'publish', bbp_get_closed_status_id() ), true ) ) { return $previous; }
                return $status;
            };
            add_filter( 'wp_untrash_post_status', $restore_status, 10, 3 );
            try { $result = 'trash' === $post->post_status ? wp_untrash_post( $post->ID ) : true; }
            finally { remove_filter( 'wp_untrash_post_status', $restore_status, 10 ); }
            if ( $result ) { delete_post_meta( $post->ID, '_riffit_removed_by_mod' ); }
        } elseif ( 'close' === $action ) { $result = bbp_is_topic_closed( $post->ID ) ? true : bbp_close_topic( $post->ID ); }
        else { $result = bbp_is_topic_open( $post->ID ) ? true : bbp_open_topic( $post->ID ); }
        if ( ! $result ) { return $this->error( 'moderation_failed', 'Could not update this item.', 500 ); }
        return array( 'id' => (int) $post->ID, 'status' => get_post_status( $post->ID ) );
    }

    public function report_object( \WP_REST_Request $request ) {
        global $wpdb;
        $object = $this->managed_object( (int) $request['id'] );
        if ( is_wp_error( $object ) ) { return $object; }
        if ( groups_is_user_banned( get_current_user_id(), $object['group'] ) ) { return $this->error( 'banned', 'You cannot participate in this community.', 403 ); }
        if ( ! trim( $request['reason'] ) ) { return $this->error( 'empty_reason', 'Tell the moderators what needs attention.' ); }
        if ( ( new Auth() )->limited( 'reports:' . get_current_user_id(), 20, HOUR_IN_SECONDS ) ) { return $this->error( 'rate_limited', 'You are reporting too quickly. Try again later.', 429 ); }
        $saved = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (object_id,user_id,community_id,reason,status,created_at) VALUES (%d,%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE reason=VALUES(reason),status=VALUES(status),created_at=VALUES(created_at),resolved_by=0', $wpdb->prefix . 'riffit_reports', $object['post']->ID, get_current_user_id(), $object['group'], $request['reason'], 'open', current_time( 'mysql', true ) ) );
        return false === $saved ? $this->error( 'report_failed', 'Could not send this report.', 500 ) : array( 'reported' => true );
    }

    public function moderation_queue( \WP_REST_Request $request ) {
        global $wpdb;
        $group = $this->public_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( ! $this->can_moderate( (int) $group->id ) ) { return $this->error( 'forbidden', 'Only community moderators can read reports.', 403 ); }
        $table = $wpdb->prefix . 'riffit_reports';
        $total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE community_id=%d AND status=%s', $table, $group->id, 'open' ) );
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE community_id=%d AND status=%s ORDER BY id DESC LIMIT 30 OFFSET %d', $table, $group->id, 'open', ( (int) $request['page'] - 1 ) * 30 ), ARRAY_A );
        foreach ( $rows as &$row ) { $post = get_post( $row['object_id'] ); $row['id'] = (int) $row['id']; $row['object_id'] = (int) $row['object_id']; $row['title'] = $post ? wp_strip_all_tags( $post->post_title ?: $post->post_content ) : 'Deleted item'; $row['object_status'] = $post ? $post->post_status : 'deleted'; $row['post_id'] = $post ? ( bbp_get_reply_post_type() === $post->post_type ? (int) bbp_get_reply_topic_id( $post->ID ) : (int) $post->ID ) : 0; }
        return array( 'items' => $rows, 'page' => (int) $request['page'], 'pages' => (int) ceil( $total / 30 ) );
    }

    public function community_trash( \WP_REST_Request $request ) {
        global $wpdb;
        $group = $this->public_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( ! $this->can_moderate( (int) $group->id ) ) { return $this->error( 'forbidden', 'Only community moderators can restore removed items.', 403 ); }
        $forum = (int) groups_get_groupmeta( $group->id, 'riffit_forum_id', true );
        $from = $wpdb->prepare( ' FROM %i p INNER JOIN %i pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_status=%s AND CAST(pm.meta_value AS UNSIGNED)=%d AND (p.post_type=%s OR (p.post_type=%s AND EXISTS (SELECT 1 FROM %i t WHERE t.ID=p.post_parent AND t.post_status IN (%s,%s))))', $wpdb->posts, $wpdb->postmeta, '_bbp_forum_id', 'trash', $forum, bbp_get_topic_post_type(), bbp_get_reply_post_type(), $wpdb->posts, 'publish', bbp_get_closed_status_id() );
        $total = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT p.ID)' . $from );
        $posts = $wpdb->get_results( 'SELECT DISTINCT p.ID,p.post_title,p.post_content,p.post_type' . $from . $wpdb->prepare( ' ORDER BY p.ID DESC LIMIT 30 OFFSET %d', ( (int) $request['page'] - 1 ) * 30 ) );
        $items = array();
        foreach ( $posts as $post ) { $items[] = array( 'id' => (int) $post->ID, 'title' => wp_trim_words( wp_strip_all_tags( $post->post_title ?: $post->post_content ), 30 ), 'kind' => bbp_get_topic_post_type() === $post->post_type ? 'post' : 'comment' ); }
        return array( 'items' => $items, 'page' => (int) $request['page'], 'pages' => (int) ceil( $total / 30 ) );
    }

    public function resolve_report( \WP_REST_Request $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'riffit_reports';
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $request['id'] ) );
        if ( ! $row ) { return $this->error( 'not_found', 'Report not found.', 404 ); }
        if ( is_wp_error( $this->public_group( (int) $row->community_id ) ) || ! $this->can_moderate( (int) $row->community_id ) ) { return $this->error( 'forbidden', 'Only this community’s moderators can resolve reports.', 403 ); }
        $saved = $wpdb->update( $table, array( 'status' => 'resolved', 'resolved_by' => get_current_user_id() ), array( 'id' => $row->id ) );
        return false === $saved ? $this->error( 'resolve_failed', 'Could not resolve this report.', 500 ) : array( 'resolved' => true );
    }

    public function community_members( \WP_REST_Request $request ) {
        global $wpdb;
        $group = $this->public_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( ! $this->can_moderate( (int) $group->id ) ) { return $this->error( 'forbidden', 'Only community staff can manage members.', 403 ); }
        $where = $wpdb->prepare( ' WHERE m.group_id=%d AND m.is_confirmed=1', $group->id );
        if ( $request['q'] ) { $where .= $wpdb->prepare( ' AND u.user_nicename LIKE %s', '%' . $wpdb->esc_like( $request['q'] ) . '%' ); }
        $from = $wpdb->prepare( ' FROM %i m INNER JOIN %i u ON u.ID=m.user_id', buddypress()->groups->table_name_members, $wpdb->users );
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*)' . $from . $where );
        $rows = $wpdb->get_results( 'SELECT u.ID AS id,u.user_nicename AS handle,u.display_name AS name,m.is_admin,m.is_mod,m.is_banned' . $from . $where . $wpdb->prepare( ' ORDER BY u.user_nicename ASC LIMIT 30 OFFSET %d', ( (int) $request['page'] - 1 ) * 30 ), ARRAY_A );
        foreach ( $rows as &$row ) { $row['id'] = (int) $row['id']; $row['is_admin'] = (bool) $row['is_admin']; $row['is_mod'] = (bool) $row['is_mod']; $row['is_banned'] = (bool) $row['is_banned']; }
        return array( 'items' => $rows, 'page' => (int) $request['page'], 'pages' => (int) ceil( $total / 30 ) );
    }

    public function manage_member( \WP_REST_Request $request ) {
        $group = $this->public_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( ! $this->can_admin( (int) $group->id ) ) { return $this->error( 'forbidden', 'Only community admins can change member roles.', 403 ); }
        $user = (int) $request['user']; $action = $request['action'];
        if ( ! get_userdata( $user ) || ( ! groups_is_user_member( $user, $group->id ) && ! groups_is_user_banned( $user, $group->id ) ) ) { return $this->error( 'not_found', 'Community member not found.', 404 ); }
        $admin = groups_is_user_admin( $user, $group->id );
        if ( in_array( $action, array( 'ban','demote' ), true ) && $admin && count( groups_get_group_admins( $group->id ) ) < 2 ) { return $this->error( 'last_admin', 'Keep at least one community admin.', 409 ); }
        if ( 'ban' === $action && ( $admin || groups_is_user_mod( $user, $group->id ) || user_can( $user, 'manage_options' ) ) ) { return $this->error( 'protected_member', 'Remove the community staff role before banning this member. Site admins cannot be banned.', 403 ); }
        if ( str_starts_with( $action, 'promote_' ) && groups_is_user_banned( $user, $group->id ) ) { return $this->error( 'banned', 'Unban this member before changing their role.', 403 ); }
        $actor = get_current_user_id();
        if ( 'ban' === $action ) { $result = groups_ban_member( $user, $group->id, $actor ); }
        elseif ( 'unban' === $action ) { $result = groups_unban_member( $user, $group->id, $actor ); }
        elseif ( 'demote' === $action ) { $result = groups_demote_member( $user, $group->id, $actor ); }
        else { $result = groups_promote_member( $user, $group->id, 'promote_admin' === $action ? 'admin' : 'mod', $actor ); }
        return $result ? array( 'updated' => true ) : $this->error( 'member_failed', 'Could not update this member.', 500 );
    }

    public function community_settings( \WP_REST_Request $request ) {
        $group = $this->public_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( ! $this->can_admin( (int) $group->id ) ) { return $this->error( 'forbidden', 'Only community admins can edit settings.', 403 ); }
        if ( ! trim( $request['name'] ) ) { return $this->error( 'empty_name', 'Give your community a name.' ); }
        $old_name = $group->name; $old_description = $group->description;
        $group->name = $request['name']; $group->description = $request['description'];
        if ( ! $group->save() ) { return $this->error( 'settings_failed', 'Could not save community settings.', 500 ); }
        $forum = (int) groups_get_groupmeta( $group->id, 'riffit_forum_id', true );
        $saved = wp_update_post( array( 'ID' => $forum, 'post_title' => $group->name, 'post_content' => $group->description ), true );
        if ( is_wp_error( $saved ) ) { $group->name = $old_name; $group->description = $old_description; $group->save(); return $this->error( 'settings_failed', 'Could not save community settings.', 500 ); }
        groups_update_groupmeta( $group->id, 'riffit_rules', $request['rules'] );
        return $this->group_data( $group );
    }
}

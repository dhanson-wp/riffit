<?php
namespace Riffit;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Api {
    protected array $vote_cache = array();
    public function register(): void {
        $public = '__return_true';
        $member = array( $this, 'member_permission' );
        $id = array( 'type' => 'integer', 'minimum' => 1, 'required' => true );
        $title = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 300, 'required' => true, 'sanitize_callback' => 'sanitize_text_field' );
        $content = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 40000, 'required' => true, 'sanitize_callback' => 'wp_kses_post' );
        $this->route( '/health', 'GET', 'health', $public );
        $this->route( '/me', 'GET', 'me', $member );
        $this->route( '/communities', 'GET', 'communities', $public, array(
            'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
        ) );
        $this->route( '/communities/by-slug/(?P<slug>[a-z0-9-]+)', 'GET', 'community_by_slug', $public, array( 'slug' => array( 'type' => 'string', 'required' => true ) ) );
        $this->route( '/communities/(?P<id>\d+)', 'GET', 'single_community', $public, array( 'id' => $id ) );
        $this->route( '/communities', 'POST', 'create_community', $member, array(
            'name' => $title,
            'slug' => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{1,39}$', 'required' => true ),
            'description' => array( 'type' => 'string', 'maxLength' => 3000, 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
        ) );
        $this->route( '/communities/(?P<id>\d+)/membership', 'PUT', 'join_community', $member, array( 'id' => $id, 'joined' => array( 'type' => 'boolean', 'default' => true ) ) );
        $this->route( '/communities/(?P<id>\d+)/posts', 'GET', 'posts', $public, array(
            'id' => $id,
            'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
        ) );
        $this->route( '/communities/(?P<id>\d+)/posts', 'POST', 'create_post', $member, array( 'id' => $id, 'title' => $title, 'content' => array( 'type' => 'string', 'maxLength' => 40000, 'default' => '', 'sanitize_callback' => 'wp_kses_post' ),
            'kind' => array( 'type' => 'string', 'enum' => array( 'text', 'link', 'image', 'crosspost', 'poll' ), 'default' => 'text' ),
            'url' => array( 'type' => 'string', 'maxLength' => 2000, 'default' => '' ),
            'attachment' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
            'source' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
            'flair' => array( 'type' => 'string', 'maxLength' => 30, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            'options' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ), 'maxItems' => 6, 'default' => array() ),
            'hours' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 168, 'default' => 24 ),
        ) );
        $this->route( '/posts/(?P<id>\d+)/replies', 'GET', 'replies', $public, array(
            'id' => $id, 'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
            'focus' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
        ) );
        $this->route( '/posts/(?P<id>\d+)/replies', 'POST', 'create_reply', $member, array(
            'id' => $id, 'content' => $content,
            'parent' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
        ) );
        $this->route( '/objects/(?P<id>\d+)/vote', 'PUT', 'vote', $member, array(
            'id' => $id, 'value' => array( 'type' => 'integer', 'enum' => array( -1, 0, 1 ), 'required' => true ),
        ) );
    }

    protected function route( string $path, string $methods, string $callback, $permission, array $args = array() ): void {
        register_rest_route( 'riffit/v1', $path, array(
            'methods' => $methods, 'callback' => function ( $request ) use ( $callback ) {
                if ( 'health' !== $callback && in_array( false, $this->health()['dependencies'], true ) ) {
                    return $this->error( 'dependency', 'The required community plugins are unavailable.', 503 );
                }
                return $this->{$callback}( $request );
            },
            'permission_callback' => $permission, 'args' => $args,
        ) );
    }

    protected function error( string $code, string $message, int $status = 400 ): \WP_Error {
        return new \WP_Error( 'riffit_' . $code, $message, array( 'status' => $status ) );
    }

    public function health(): array {
        return array( 'version' => VERSION, 'dependencies' => array(
            'bbpress' => function_exists( 'bbp_insert_topic' ),
            'buddypress' => function_exists( 'buddypress' ),
            'groups' => function_exists( 'groups_create_group' ),
        ), 'authentication' => 'Email magic links and scoped member sessions; WordPress.com OAuth pending' );
    }

    public function member_permission() {
        if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
            return $this->error( 'unauthenticated', 'Sign in to participate.', 401 );
        }
        $user = get_current_user_id();
        if ( function_exists( 'bp_is_user_active' ) && ! bp_is_user_active( $user ) ) {
            return $this->error( 'inactive', 'This account cannot participate.', 403 );
        }
        if ( function_exists( 'bbp_get_user_role' ) && bbp_get_user_role( $user ) === bbp_get_blocked_role() ) {
            return $this->error( 'blocked', 'This account cannot participate.', 403 );
        }
        if ( ! current_user_can( 'manage_options' ) && ! get_user_meta( $user, 'riffit_email_verified', true ) ) {
            return $this->error( 'unverified', 'Verify your email before participating.', 403 );
        }
        return true;
    }

    public function me(): array {
        $user = wp_get_current_user();
        return array( 'id' => $user->ID, 'handle' => $user->user_nicename, 'name' => $user->display_name );
    }

    protected function public_group( int $id ) {
        if ( ! function_exists( 'groups_get_group' ) ) {
            return $this->error( 'dependency', 'BuddyPress groups are unavailable.', 503 );
        }
        $group = groups_get_group( $id );
        $forum = (int) groups_get_groupmeta( $id, 'riffit_forum_id', true );
        if ( ! $group->id || 'public' !== $group->status || ! $forum || $id !== (int) get_post_meta( $forum, '_riffit_group_id', true ) || ! bbp_is_forum_public( $forum ) || 'publish' !== get_post_status( $forum ) ) {
            return $this->error( 'not_found', 'Community not found.', 404 );
        }
        return $group;
    }

    protected function writable_group( int $id ) {
        $group = $this->public_group( $id );
        if ( is_wp_error( $group ) ) { return $group; }
        $user = get_current_user_id();
        if ( groups_is_user_banned( $user, $id ) || ! groups_is_user_member( $user, $id ) ) {
            return $this->error( 'membership_required', 'Join this community before posting.', 403 );
        }
        $forum = (int) groups_get_groupmeta( $id, 'riffit_forum_id', true );
        if ( bbp_is_forum_closed( $forum ) ) {
            return $this->error( 'closed', 'This community is closed to new posts.', 403 );
        }
        return $group;
    }

    protected function group_data( $group ): array {
        return array( 'id' => (int) $group->id, 'name' => $group->name, 'slug' => $group->slug,
            'description' => wp_strip_all_tags( $group->description ) );
    }

    public function community_by_slug( \WP_REST_Request $request ) {
        $group = $this->public_group( (int) groups_get_id( $request['slug'] ) );
        return is_wp_error( $group ) ? $group : $this->group_data( $group );
    }

    public function single_community( \WP_REST_Request $request ) {
        $group = $this->public_group( (int) $request['id'] );
        return is_wp_error( $group ) ? $group : $this->group_data( $group );
    }

    public function communities( \WP_REST_Request $request ) {
        if ( ! function_exists( 'groups_get_groups' ) ) { return $this->error( 'dependency', 'Groups are unavailable.', 503 ); }
        $result = groups_get_groups( array( 'type' => 'alphabetical', 'show_hidden' => false, 'status' => 'public',
            'page' => $request['page'], 'per_page' => 30, 'meta_query' => array(
                array( 'key' => 'riffit_forum_id', 'compare' => 'EXISTS' ),
            ),
        ) );
        $groups = array();
        foreach ( $result['groups'] as $group ) {
            if ( ! is_wp_error( $this->public_group( (int) $group->id ) ) ) { $groups[] = $this->group_data( $group ); }
        }
        return array( 'items' => $groups, 'page' => $request['page'] );
    }

    public function create_community( \WP_REST_Request $request ) {
        if ( ! function_exists( 'groups_create_group' ) || ! function_exists( 'bbp_insert_forum' ) ) {
            return $this->error( 'dependency', 'The required community plugins are unavailable.', 503 );
        }
        if ( '' === trim( $request['name'] ) ) { return $this->error( 'empty_name', 'A community name is required.' ); }
        if ( groups_get_id( $request['slug'] ) ) { return $this->error( 'slug_exists', 'This community address is taken.', 409 ); }
        $id = groups_create_group( array( 'creator_id' => get_current_user_id(), 'name' => $request['name'],
            'slug' => $request['slug'], 'description' => $request['description'], 'status' => 'public' ) );
        if ( ! $id || is_wp_error( $id ) ) { return $this->error( 'create_failed', 'Could not create the community.', 500 ); }
        $forum = bbp_insert_forum( array( 'post_title' => $request['name'], 'post_content' => $request['description'],
            'post_author' => get_current_user_id(), 'post_status' => bbp_get_public_status_id() ) );
        if ( ! $forum || is_wp_error( $forum ) ) {
            groups_delete_group( $id );
            return $this->error( 'forum_failed', 'Could not create the community forum.', 500 );
        }
        groups_update_groupmeta( $id, 'riffit_forum_id', $forum );
        update_post_meta( $forum, '_riffit_group_id', $id );
        groups_join_group( $id, get_current_user_id() );
        return new \WP_REST_Response( $this->group_data( groups_get_group( $id ) ), 201 );
    }

    public function join_community( \WP_REST_Request $request ) {
        $id = (int) $request['id'];
        $group = $this->public_group( $id );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( groups_is_user_banned( get_current_user_id(), $id ) ) { return $this->error( 'banned', 'You cannot join this community.', 403 ); }
        if ( ! groups_is_user_member( get_current_user_id(), $id ) && ! groups_join_group( $id, get_current_user_id() ) ) {
            return $this->error( 'join_failed', 'Could not join the community.', 500 );
        }
        return array( 'joined' => true, 'community_id' => $id );
    }

    protected function public_topic( int $id ) {
        $post = get_post( $id );
        if ( ! $post || bbp_get_topic_post_type() !== $post->post_type || ! in_array( $post->post_status, array( 'publish', bbp_get_closed_status_id() ), true ) ) {
            return $this->error( 'not_found', 'Post not found.', 404 );
        }
        $group = (int) get_post_meta( bbp_get_topic_forum_id( $id ), '_riffit_group_id', true );
        $result = $this->public_group( $group );
        return is_wp_error( $result ) ? $result : $post;
    }

    protected function prime_votes( array $ids ): void {
        global $wpdb;
        $user = get_current_user_id();
        $missing = array();
        foreach ( $ids as $id ) { if ( ! isset( $this->vote_cache[$user][$id] ) ) { $missing[] = absint( $id ); $this->vote_cache[$user][$id] = array( 'score' => 0, 'vote' => 0 ); } }
        if ( ! $missing ) { return; }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id,SUM(value) AS score,SUM(CASE WHEN user_id=%d THEN value ELSE 0 END) AS vote FROM %i WHERE object_id IN (' . implode( ',', $missing ) . ') GROUP BY object_id', $user, $wpdb->prefix . 'riffit_votes' ) );
        foreach ( $rows as $row ) { $this->vote_cache[$user][$row->object_id] = array( 'score' => (int) $row->score, 'vote' => (int) $row->vote ); }
    }

    protected function hydrate_posts( array $posts ): array {
        $this->prime_votes( wp_list_pluck( $posts, 'ID' ) );
        update_meta_cache( 'post', array_merge( wp_list_pluck( $posts, 'ID' ), wp_list_pluck( $posts, 'post_parent' ) ) );
        cache_users( array_unique( wp_list_pluck( $posts, 'post_author' ) ) );
        return array_map( array( $this, 'post_data' ), $posts );
    }

    protected function render_member_content( string $content ): string {
        $html = preg_replace( '/<!--[\s\S]*?-->/', '', $content );
        if ( ! has_blocks( $content ) ) { $html = wpautop( $html ); }
        $tags = array_fill_keys( array( 'p','br','strong','b','em','i','s','del','h2','h3','h4','h5','h6','ul','ol','li','blockquote','cite','pre','code','hr' ), array() );
        $tags['a'] = array( 'href' => true, 'title' => true );
        $html = wp_kses( $html, $tags, array( 'http', 'https', 'mailto' ) );
        $parts = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        $protected = 0;
        foreach ( $parts as &$part ) {
            if ( preg_match( '/^<(\/?)(?:a|pre|code)\b/i', $part, $match ) ) { $protected = max( 0, $protected + ( $match[1] ? -1 : 1 ) ); }
            elseif ( $part && '<' !== $part[0] && ! $protected ) {
                $part = preg_replace_callback( '/(?<![a-zA-Z0-9_@])@([a-zA-Z0-9_-]{1,60})\b/', static function ( $match ) { return '<a href="' . esc_url( '/?view=profile&handle=' . rawurlencode( $match[1] ) ) . '">' . esc_html( $match[0] ) . '</a>'; }, $part );
            }
        }
        unset( $part );
        return implode( '', $parts );
    }

    protected function post_data( $post ): array {
        global $wpdb;
        $this->prime_votes( array( $post->ID ) );
        $values = $this->vote_cache[get_current_user_id()][$post->ID];
        $score = $values['score']; $vote = $values['vote'];
        $author = get_userdata( $post->post_author );
        return array( 'score' => $score, 'vote' => $vote, 'author_handle' => $author ? $author->user_nicename : 'deleted', 'id' => (int) $post->ID, 'title' => $post->post_title, 'content' => wp_kses_post( $post->post_content ), 'rendered' => $this->render_member_content( $post->post_content ),
            'author' => (int) $post->post_author, 'created_at' => mysql2date( 'c', $post->post_date_gmt, false ),
            'parent' => (int) $post->post_parent, 'community_id' => (int) get_post_meta( bbp_get_topic_forum_id( bbp_get_reply_post_type() === $post->post_type ? bbp_get_reply_topic_id( $post->ID ) : $post->ID ), '_riffit_group_id', true ) );
    }

    public function posts( \WP_REST_Request $request ) {
        $group = $this->public_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        $forum = (int) groups_get_groupmeta( $group->id, 'riffit_forum_id', true );
        $query = new \WP_Query( array( 'post_type' => bbp_get_topic_post_type(), 'post_status' => array( 'publish', bbp_get_closed_status_id() ),
            'post_parent' => $forum, 'posts_per_page' => 30, 'paged' => $request['page'],
            'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true,
        ) );
        return array( 'items' => $this->hydrate_posts( $query->posts ), 'total' => $query->found_posts,
            'page' => $request['page'], 'pages' => $query->max_num_pages );
    }

    public function create_post( \WP_REST_Request $request ) {
        $group = $this->writable_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( '' === trim( $request['title'] ) || '' === trim( wp_strip_all_tags( $request['content'] ) ) ) {
            return $this->error( 'empty_post', 'A title and post body are required.' );
        }
        $forum = (int) groups_get_groupmeta( $group->id, 'riffit_forum_id', true );
        $id = bbp_insert_topic( array( 'post_title' => $request['title'], 'post_content' => $request['content'],
            'post_author' => get_current_user_id(), 'post_parent' => $forum, 'post_status' => 'publish' ),
            array( 'forum_id' => $forum ) );
        if ( ! $id || is_wp_error( $id ) ) { return $this->error( 'post_failed', 'Could not create the post.', 500 ); }
        return new \WP_REST_Response( $this->post_data( get_post( $id ) ), 201 );
    }

    public function replies( \WP_REST_Request $request ) {
        $post = $this->public_topic( (int) $request['id'] );
        if ( is_wp_error( $post ) ) { return $post; }
        if ( $request['focus'] ) {
            global $wpdb;
            $focus = get_post( (int) $request['focus'] );
            if ( ! $focus || bbp_get_reply_post_type() !== $focus->post_type || 'publish' !== $focus->post_status || (int) $focus->post_parent !== (int) $post->ID ) { return $this->error( 'not_found', 'Comment not found.', 404 ); }
            $before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type=%s AND post_status=%s AND post_parent=%d AND (post_date < %s OR (post_date=%s AND ID < %d))', $wpdb->posts, bbp_get_reply_post_type(), 'publish', $post->ID, $focus->post_date, $focus->post_date, $focus->ID ) );
            $request['page'] = (int) floor( $before / 50 ) + 1;
        }
        $query = new \WP_Query( array( 'post_type' => bbp_get_reply_post_type(), 'post_status' => 'publish',
            'post_parent' => $post->ID, 'posts_per_page' => 50, 'paged' => $request['page'], 'orderby' => array( 'date' => 'ASC', 'ID' => 'ASC' ),
        ) );
        $items = array();
        $this->prime_votes( wp_list_pluck( $query->posts, 'ID' ) );
        foreach ( $query->posts as $reply ) {
            $items[] = array_merge( $this->post_data( $reply ), array( 'reply_to' => (int) bbp_get_reply_to( $reply->ID ) ) );
        }
        return array( 'items' => $items, 'total' => $query->found_posts, 'page' => $request['page'], 'pages' => $query->max_num_pages );
    }

    public function create_reply( \WP_REST_Request $request ) {
        $topic = $this->public_topic( (int) $request['id'] );
        if ( is_wp_error( $topic ) ) { return $topic; }
        $forum = bbp_get_topic_forum_id( $topic->ID );
        $group = $this->writable_group( (int) get_post_meta( $forum, '_riffit_group_id', true ) );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( bbp_is_topic_closed( $topic->ID ) ) { return $this->error( 'closed', 'This discussion is closed.', 403 ); }
        if ( '' === trim( wp_strip_all_tags( $request['content'] ) ) ) { return $this->error( 'empty_reply', 'A reply body is required.' ); }
        $parent = (int) $request['parent'];
        if ( $parent && ( bbp_get_reply_post_type() !== get_post_type( $parent ) || 'publish' !== get_post_status( $parent ) || $topic->ID !== (int) bbp_get_reply_topic_id( $parent ) ) ) {
            return $this->error( 'invalid_parent', 'The parent reply must belong to this discussion.' );
        }
        $id = bbp_insert_reply( array( 'post_content' => $request['content'], 'post_author' => get_current_user_id(),
            'post_parent' => $topic->ID, 'post_status' => 'publish' ),
            array( 'forum_id' => $forum, 'topic_id' => $topic->ID, 'reply_to' => $parent ) );
        if ( ! $id || is_wp_error( $id ) ) { return $this->error( 'reply_failed', 'Could not create the reply.', 500 ); }
        return new \WP_REST_Response( array_merge( $this->post_data( get_post( $id ) ), array( 'reply_to' => $parent ) ), 201 );
    }

    public function vote( \WP_REST_Request $request ) {
        global $wpdb;
        $id = (int) $request['id'];
        $object = get_post( $id );
        if ( ! $object || 'publish' !== $object->post_status || ! in_array( $object->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) ) {
            return $this->error( 'not_found', 'Post or reply not found.', 404 );
        }
        $topic = bbp_get_reply_post_type() === $object->post_type ? (int) bbp_get_reply_topic_id( $id ) : $id;
        $allowed = $this->public_topic( $topic );
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        $group_id = (int) get_post_meta( bbp_get_topic_forum_id( $topic ), '_riffit_group_id', true );
        if ( groups_is_user_banned( get_current_user_id(), $group_id ) ) { return $this->error( 'banned', 'You cannot vote in this community.', 403 ); }
        $table = $wpdb->prefix . 'riffit_votes';
        $value = (int) $request['value'];
        if ( 0 === $value ) {
            $saved = $wpdb->delete( $table, array( 'object_id' => $id, 'user_id' => get_current_user_id() ), array( '%d', '%d' ) );
        } else {
            $saved = $wpdb->query( $wpdb->prepare(
                'INSERT INTO %i (object_id,user_id,value,updated_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=VALUES(updated_at)',
                $table, $id, get_current_user_id(), $value, current_time( 'mysql', true )
            ) );
        }
        if ( false === $saved ) { return $this->error( 'vote_failed', 'Could not save your vote.', 500 ); }
        $score = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(value),0) FROM %i WHERE object_id=%d', $table, $id ) );
        $this->vote_cache = array();
        return array( 'object_id' => $id, 'vote' => $value, 'score' => $score );
    }
}

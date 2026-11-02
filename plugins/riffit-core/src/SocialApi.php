<?php
namespace Riffit;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SocialApi extends Api {
    protected array $saved_cache = array();
    // Stable, bundled choices only. Never store a client-supplied image URL.
    public const AVATAR_IDS = array( 'original', 'gardener', 'astronaut', 'artist', 'baker', 'explorer', 'gamer', 'musician', 'reader', 'scientist', 'cyclist', 'detective', 'wizard', 'raincoat', 'cozy', 'sailor', 'photographer', 'builder', 'barista', 'chef', 'skater' );

    protected function avatar_id( int $user_id ): string {
        $id = (string) get_user_meta( $user_id, 'riffit_avatar_id', true );
        return in_array( $id, self::AVATAR_IDS, true ) ? $id : 'original';
    }

    public function me(): array {
        return array_merge( parent::me(), array( 'avatar_id' => $this->avatar_id( get_current_user_id() ) ) );
    }
    public function register(): void {
        parent::register();
        $member = array( $this, 'member_permission' );
        $id = array( 'type' => 'integer', 'minimum' => 1, 'required' => true );
        $paging = array(
            'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
            'sort' => array( 'type' => 'string', 'enum' => array( 'hot', 'new', 'top' ), 'default' => 'hot' ),
            'period' => array( 'type' => 'string', 'enum' => array( 'day', 'week', 'month', 'year', 'all' ), 'default' => 'all' ),
            'q' => array( 'type' => 'string', 'maxLength' => 200, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
            'community' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
        );
        $this->route( '/posts', 'GET', 'feed', '__return_true', $paging );
        $this->route( '/posts/(?P<id>\d+)', 'GET', 'single_post', '__return_true', array( 'id' => $id ) );
        $this->route( '/saved', 'GET', 'saved', $member, $paging );
        $this->route( '/posts/(?P<id>\d+)/saved', 'PUT', 'save_post', $member, array( 'id' => $id, 'saved' => array( 'type' => 'boolean', 'required' => true ) ) );
        $this->route( '/profiles/(?P<handle>[a-zA-Z0-9_-]+)', 'GET', 'profile', '__return_true', array( 'page' => $paging['page'] ) );
        $this->route( '/profile', 'PUT', 'update_profile', $member, array(
            'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 60, 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
            'bio' => array( 'type' => 'string', 'maxLength' => 500, 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
            'avatar_id' => array( 'type' => 'string', 'enum' => self::AVATAR_IDS ),
        ) );
        // Member-specific votes and saved state must never enter a shared HTTP cache.
        add_filter( 'rest_post_dispatch', static function ( $response, $server, $request ) {
            if ( str_starts_with( $request->get_route(), '/riffit/v1/' ) ) { $response->header( 'Cache-Control', 'private, no-store' ); }
            return $response;
        }, 10, 3 );
    }

    protected function hydrate_posts( array $posts ): array {
        global $wpdb;
        $ids = array_map( 'absint', wp_list_pluck( $posts, 'ID' ) );
        $user = get_current_user_id();
        foreach ( $ids as $id ) { $this->saved_cache[$user][$id] = false; }
        if ( $user && $ids ) {
            $saved = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM %i WHERE user_id=%d AND object_id IN (' . implode( ',', $ids ) . ')', $wpdb->prefix . 'riffit_saved', $user ) );
            foreach ( $saved as $id ) { $this->saved_cache[$user][$id] = true; }
        }
        return parent::hydrate_posts( $posts );
    }

    protected function post_data( $post ): array {
        global $wpdb;
        $data = parent::post_data( $post );
        $author = get_userdata( $post->post_author );
        $data['author_name'] = $author ? $author->display_name : 'Deleted member';
        $data['author_avatar_id'] = $this->avatar_id( (int) $post->post_author );
        $data['saved'] = $this->saved_cache[get_current_user_id()][$post->ID] ?? ( get_current_user_id() ? (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT object_id FROM %i WHERE user_id=%d AND object_id=%d', $wpdb->prefix . 'riffit_saved', get_current_user_id(), $post->ID ) ) : false );
        $data['reply_count'] = bbp_get_topic_post_type() === $post->post_type ? (int) bbp_get_topic_reply_count( $post->ID, true ) : 0;
        return $data;
    }

    public function single_post( \WP_REST_Request $request ) {
        $post = $this->public_topic( (int) $request['id'] );
        return is_wp_error( $post ) ? $post : $this->post_data( $post );
    }

    public function feed( \WP_REST_Request $request ) { return $this->query_feed( $request ); }
    public function saved( \WP_REST_Request $request ) { return $this->query_feed( $request, true ); }

    protected function query_feed( \WP_REST_Request $request, bool $saved = false, int $author = 0 ) {
        global $wpdb;
        $page = (int) ( $request['page'] ?: 1 );
        $offset = ( $page - 1 ) * 30;
        if ( $request['community'] && is_wp_error( $this->public_group( (int) $request['community'] ) ) ) { return $this->error( 'not_found', 'Community not found.', 404 ); }
        // Check both sides of the group/forum relationship in the query, before pagination.
        $joins = $wpdb->prepare( ' FROM %i p INNER JOIN %i f ON f.ID=p.post_parent INNER JOIN %i fm ON fm.post_id=f.ID AND fm.meta_key=%s INNER JOIN %i g ON g.id=CAST(fm.meta_value AS UNSIGNED) INNER JOIN %i gm ON gm.group_id=g.id AND gm.meta_key=%s AND CAST(gm.meta_value AS UNSIGNED)=f.ID',
            $wpdb->posts, $wpdb->posts, $wpdb->postmeta, '_riffit_group_id', buddypress()->groups->table_name, buddypress()->groups->table_name_groupmeta, 'riffit_forum_id' );
        $where = $wpdb->prepare( ' WHERE p.post_type=%s AND p.post_status IN (%s,%s) AND f.post_type=%s AND f.post_status=%s AND g.status=%s', bbp_get_topic_post_type(), 'publish', bbp_get_closed_status_id(), bbp_get_forum_post_type(), 'publish', 'public' );
        // bbPress stores visibility as post status; a linked private/hidden forum is excluded above.
        if ( $request['community'] ) { $where .= $wpdb->prepare( ' AND g.id=%d', $request['community'] ); }
        if ( $author ) { $where .= $wpdb->prepare( ' AND p.post_author=%d', $author ); }
        if ( $saved ) { $where .= $wpdb->prepare( ' AND EXISTS (SELECT 1 FROM %i s WHERE s.object_id=p.ID AND s.user_id=%d)', $wpdb->prefix . 'riffit_saved', get_current_user_id() ); }
        if ( $request['q'] ) {
            $like = '%' . $wpdb->esc_like( $request['q'] ) . '%';
            $where .= $wpdb->prepare( ' AND (p.post_title LIKE %s OR p.post_content LIKE %s)', $like, $like );
        }
        $periods = array( 'day' => DAY_IN_SECONDS, 'week' => WEEK_IN_SECONDS, 'month' => 30 * DAY_IN_SECONDS, 'year' => YEAR_IN_SECONDS );
        if ( isset( $periods[$request['period']] ) ) { $where .= $wpdb->prepare( ' AND p.post_date_gmt >= %s', gmdate( 'Y-m-d H:i:s', time() - $periods[$request['period']] ) ); }
        $total = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT p.ID)' . $joins . $where );
        $score = $wpdb->prepare( '(SELECT COALESCE(SUM(v.value),0) FROM %i v WHERE v.object_id=p.ID)', $wpdb->prefix . 'riffit_votes' );
        $order = 'p.post_date_gmt DESC,p.ID DESC';
        if ( 'top' === $request['sort'] ) { $order = "$score DESC,p.post_date_gmt DESC,p.ID DESC"; }
        if ( 'hot' === $request['sort'] ) { $order = "(SIGN($score)*LOG10(GREATEST(ABS($score),1)) + UNIX_TIMESTAMP(p.post_date_gmt)/45000) DESC,p.ID DESC"; }
        $items = $wpdb->get_results( 'SELECT DISTINCT p.*' . $joins . $where . ' ORDER BY ' . $order . $wpdb->prepare( ' LIMIT %d OFFSET %d', 30, $offset ) );
        return array( 'items' => $this->hydrate_posts( $items ), 'total' => $total, 'page' => $page, 'pages' => (int) ceil( $total / 30 ) );
    }

    public function save_post( \WP_REST_Request $request ) {
        global $wpdb;
        $post = $this->public_topic( (int) $request['id'] );
        if ( is_wp_error( $post ) ) { return $post; }
        $table = $wpdb->prefix . 'riffit_saved';
        if ( $request['saved'] ) {
            $result = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (user_id,object_id,created_at) VALUES (%d,%d,%s) ON DUPLICATE KEY UPDATE object_id=VALUES(object_id)', $table, get_current_user_id(), $post->ID, current_time( 'mysql', true ) ) );
        } else { $result = $wpdb->delete( $table, array( 'user_id' => get_current_user_id(), 'object_id' => $post->ID ), array( '%d','%d' ) ); }
        unset( $this->saved_cache[get_current_user_id()][$post->ID] );
        return false === $result ? $this->error( 'save_failed', 'Could not save this post.', 500 ) : array( 'saved' => (bool) $request['saved'], 'object_id' => $post->ID );
    }

    public function profile( \WP_REST_Request $request ) {
        global $wpdb;
        $user = get_user_by( 'slug', $request['handle'] );
        if ( ! $user || ( ! get_user_meta( $user->ID, 'riffit_email_verified', true ) && ! user_can( $user, 'manage_options' ) ) ) { return $this->error( 'not_found', 'Member not found.', 404 ); }
        // Aggregate public topic and reply votes in SQL; never load a member's entire history.
        $stats = $wpdb->get_row( $wpdb->prepare(
            'SELECT COALESCE(SUM(v.value),0) AS score,COUNT(DISTINCT CASE WHEN p.post_type=%s THEN p.ID ELSE NULL END) AS topics FROM %i p INNER JOIN %i t ON t.ID=CASE WHEN p.post_type=%s THEN p.post_parent ELSE p.ID END INNER JOIN %i f ON f.ID=t.post_parent INNER JOIN %i fm ON fm.post_id=f.ID AND fm.meta_key=%s INNER JOIN %i g ON g.id=CAST(fm.meta_value AS UNSIGNED) INNER JOIN %i gm ON gm.group_id=g.id AND gm.meta_key=%s AND CAST(gm.meta_value AS UNSIGNED)=f.ID LEFT JOIN %i v ON v.object_id=p.ID WHERE p.post_author=%d AND p.post_type IN (%s,%s) AND p.post_status IN (%s,%s) AND t.post_status IN (%s,%s) AND f.post_type=%s AND f.post_status=%s AND g.status=%s',
            bbp_get_topic_post_type(), $wpdb->posts, $wpdb->posts, bbp_get_reply_post_type(), $wpdb->posts, $wpdb->postmeta, '_riffit_group_id', buddypress()->groups->table_name, buddypress()->groups->table_name_groupmeta, 'riffit_forum_id', $wpdb->prefix . 'riffit_votes', $user->ID, bbp_get_topic_post_type(), bbp_get_reply_post_type(), 'publish', bbp_get_closed_status_id(), 'publish', bbp_get_closed_status_id(), bbp_get_forum_post_type(), 'publish', 'public'
        ) );
        $score = (int) $stats->score; $public_count = (int) $stats->topics;
        $badges = array( 'Verified member' );
        if ( $public_count ) { $badges[] = 'Conversation starter'; }
        if ( $score >= 10 ) { $badges[] = 'Good company'; }
        return array( 'id' => $user->ID, 'handle' => $user->user_nicename, 'name' => $user->display_name, 'avatar_id' => $this->avatar_id( (int) $user->ID ), 'bio' => (string) get_user_meta( $user->ID, 'riffit_bio', true ), 'joined_at' => mysql2date( 'c', $user->user_registered, false ), 'reputation' => $score, 'badges' => $badges, 'posts' => $this->query_feed( $request, false, $user->ID ) );
    }

    public function update_profile( \WP_REST_Request $request ) {
        $name = trim( $request['name'] );
        if ( ! $name ) { return $this->error( 'empty_name', 'Choose a public display name.' ); }
        $result = wp_update_user( array( 'ID' => get_current_user_id(), 'display_name' => $name ) );
        if ( is_wp_error( $result ) ) { return $this->error( 'profile_failed', 'Could not update your profile.', 500 ); }
        update_user_meta( get_current_user_id(), 'riffit_bio', $request['bio'] );
        if ( $request->has_param( 'avatar_id' ) ) { update_user_meta( get_current_user_id(), 'riffit_avatar_id', $request['avatar_id'] ); }
        $user = get_userdata( get_current_user_id() );
        return array( 'id' => $user->ID, 'handle' => $user->user_nicename, 'name' => $user->display_name, 'bio' => (string) get_user_meta( $user->ID, 'riffit_bio', true ), 'avatar_id' => $this->avatar_id( (int) $user->ID ) );
    }
}

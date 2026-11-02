<?php
namespace Riffit;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ContentApi extends MessagesApi {
    protected array $poll_cache = array();
    public function register(): void {
        parent::register();
        $member = array( $this, 'member_permission' );
        $id = array( 'type' => 'integer', 'minimum' => 1, 'required' => true );
        $this->route( '/communities/(?P<id>\d+)/media', 'POST', 'upload_image', $member, array(
            'id' => $id,
            'name' => array( 'type' => 'string', 'maxLength' => 150, 'required' => true, 'sanitize_callback' => 'sanitize_file_name' ),
            'data' => array( 'type' => 'string', 'maxLength' => 6990510, 'required' => true ),
            'alt' => array( 'type' => 'string', 'maxLength' => 300, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
        ) );
        $this->route( '/posts/(?P<id>\d+)/poll', 'PUT', 'poll_vote', $member, array( 'id' => $id, 'choice' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5, 'required' => true ) ) );
    }

    public function create_post( \WP_REST_Request $request ) {
        $group = $this->writable_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        $title = trim( $request['title'] ); $kind = $request['kind'] ?: 'text';
        if ( ! $title ) { return $this->error( 'empty_post', 'Give your post a title.' ); }
        $content = $request['content']; $meta = array( 'kind' => $kind, 'flair' => $request['flair'] ?: '' );
        if ( 'text' === $kind && ! trim( wp_strip_all_tags( $content ) ) ) { return $this->error( 'empty_post', 'Write a post before sharing.' ); }
        if ( 'link' === $kind ) {
            $url = trim( $request['url'] );
            if ( ! wp_http_validate_url( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) { return $this->error( 'invalid_url', 'Use a public HTTP or HTTPS link.' ); }
            // Store the URL only. No server fetch, embed, or metadata scraper follows it.
            $meta['url'] = esc_url_raw( $url, array( 'http', 'https' ) );
        }
        if ( 'image' === $kind ) {
            $attachment = (int) $request['attachment'];
            $image = get_post( $attachment );
            if ( ! $image || 'attachment' !== $image->post_type || (int) $image->post_author !== get_current_user_id() || ! get_post_meta( $attachment, '_riffit_upload', true ) || get_post_meta( $attachment, '_riffit_branding', true ) || (int) get_post_meta( $attachment, '_riffit_community', true ) !== (int) $group->id || ! wp_attachment_is_image( $attachment ) || $image->post_parent ) { return $this->error( 'invalid_image', 'Upload your own image to this community before posting.' ); }
            $meta['attachment'] = $attachment;
        }
        if ( 'crosspost' === $kind ) {
            $source = $this->public_topic( (int) $request['source'] );
            if ( is_wp_error( $source ) ) { return $source; }
            $meta['source'] = (int) $source->ID;
        }
        if ( 'poll' === $kind ) {
            $options = array_map( 'sanitize_text_field', $request['options'] );
            if ( count( $options ) < 2 || count( $options ) > 6 || count( array_unique( array_map( 'strtolower', $options ) ) ) !== count( $options ) || in_array( '', array_map( 'trim', $options ), true ) ) { return $this->error( 'invalid_poll', 'Choose two to six different, nonempty poll options.' ); }
            $meta['options'] = array_values( $options );
            $meta['closes_at'] = gmdate( 'Y-m-d H:i:s', time() + (int) $request['hours'] * HOUR_IN_SECONDS );
        }
        if ( ( new Auth() )->limited( 'create-post:' . get_current_user_id(), 100, HOUR_IN_SECONDS ) ) { return $this->error( 'rate_limited', 'You are posting too quickly. Try again later.', 429 ); }
        $forum = (int) groups_get_groupmeta( $group->id, 'riffit_forum_id', true );
        $id = bbp_insert_topic( array( 'post_title' => $title, 'post_content' => $content, 'post_author' => get_current_user_id(), 'post_parent' => $forum, 'post_status' => 'publish' ), array( 'forum_id' => $forum ) );
        if ( ! $id || is_wp_error( $id ) ) { return $this->error( 'post_failed', 'Could not create this post.', 500 ); }
        update_post_meta( $id, '_riffit_post', $meta );
        if ( 'image' === $kind ) { wp_update_post( array( 'ID' => $meta['attachment'], 'post_parent' => $id ) ); }
        return new \WP_REST_Response( $this->post_data( get_post( $id ) ), 201 );
    }

    protected function hydrate_posts( array $posts ): array {
        global $wpdb;
        update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );
        $ids = array(); $user = get_current_user_id();
        foreach ( $posts as $post ) {
            $meta = get_post_meta( $post->ID, '_riffit_post', true );
            if ( is_array( $meta ) && 'poll' === ( $meta['kind'] ?? '' ) ) { $ids[] = absint( $post->ID ); $this->poll_cache[$user][$post->ID] = array( 'counts' => array_fill( 0, count( $meta['options'] ), 0 ), 'choice' => null ); }
        }
        if ( $ids ) {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id,choice,COUNT(*) AS votes,SUM(CASE WHEN user_id=%d THEN 1 ELSE 0 END) AS mine FROM %i WHERE object_id IN (' . implode( ',', $ids ) . ') GROUP BY object_id,choice', $user, $wpdb->prefix . 'riffit_poll_votes' ) );
            foreach ( $rows as $row ) {
                $this->poll_cache[$user][$row->object_id]['counts'][$row->choice] = (int) $row->votes;
                if ( $user && $row->mine ) { $this->poll_cache[$user][$row->object_id]['choice'] = (int) $row->choice; }
            }
        }
        return parent::hydrate_posts( $posts );
    }

    protected function post_data( $post ): array {
        $data = parent::post_data( $post );
        $meta = get_post_meta( $post->ID, '_riffit_post', true );
        if ( ! is_array( $meta ) ) { $meta = array(); }
        $data['kind'] = $meta['kind'] ?? 'text'; $data['flair'] = $meta['flair'] ?? '';
        if ( 'link' === $data['kind'] ) { $data['url'] = $meta['url']; }
        if ( 'image' === $data['kind'] ) {
            $attachment = (int) ( $meta['attachment'] ?? 0 );
            $data['image'] = array( 'url' => wp_get_attachment_image_url( $attachment, 'large' ), 'alt' => (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) );
        }
        if ( 'crosspost' === $data['kind'] ) {
            // Re-check the source every time. A later private, removed, or deleted source stays hidden.
            $source = $this->public_topic( (int) ( $meta['source'] ?? 0 ) );
            $data['source'] = is_wp_error( $source ) ? null : array( 'id' => (int) $source->ID, 'title' => $source->post_title, 'community_id' => (int) get_post_meta( bbp_get_topic_forum_id( $source->ID ), '_riffit_group_id', true ) );
        }
        if ( 'poll' === $data['kind'] ) { $data['poll'] = $this->poll_data( $post->ID, $meta ); }
        return $data;
    }

    protected function poll_data( int $id, array $meta ): array {
        global $wpdb;
        $cached = $this->poll_cache[get_current_user_id()][$id] ?? null;
        if ( $cached ) { $counts = $cached['counts']; $choice = $cached['choice']; }
        else {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT choice,COUNT(*) AS votes FROM %i WHERE object_id=%d GROUP BY choice', $wpdb->prefix . 'riffit_poll_votes', $id ) );
            $counts = array_fill( 0, count( $meta['options'] ), 0 );
            foreach ( $rows as $row ) { if ( isset( $counts[$row->choice] ) ) { $counts[$row->choice] = (int) $row->votes; } }
            $choice = get_current_user_id() ? $wpdb->get_var( $wpdb->prepare( 'SELECT choice FROM %i WHERE object_id=%d AND user_id=%d', $wpdb->prefix . 'riffit_poll_votes', $id, get_current_user_id() ) ) : null;
        }
        return array( 'options' => $meta['options'], 'counts' => $counts, 'total' => array_sum( $counts ), 'choice' => null === $choice ? null : (int) $choice, 'closed' => time() >= strtotime( $meta['closes_at'] . ' UTC' ), 'closes_at' => mysql2date( 'c', $meta['closes_at'], false ) );
    }

    public function poll_vote( \WP_REST_Request $request ) {
        global $wpdb;
        $post = $this->public_topic( (int) $request['id'] );
        if ( is_wp_error( $post ) ) { return $post; }
        $group = (int) get_post_meta( bbp_get_topic_forum_id( $post->ID ), '_riffit_group_id', true );
        if ( groups_is_user_banned( get_current_user_id(), $group ) ) { return $this->error( 'banned', 'You cannot vote in this community.', 403 ); }
        $meta = get_post_meta( $post->ID, '_riffit_post', true );
        if ( ! is_array( $meta ) || 'poll' !== ( $meta['kind'] ?? '' ) ) { return $this->error( 'not_found', 'Poll not found.', 404 ); }
        if ( bbp_is_topic_closed( $post->ID ) || time() >= strtotime( $meta['closes_at'] . ' UTC' ) ) { return $this->error( 'poll_closed', 'This poll has closed.', 403 ); }
        $choice = (int) $request['choice'];
        if ( ! isset( $meta['options'][$choice] ) ) { return $this->error( 'invalid_choice', 'Choose an option from this poll.' ); }
        $saved = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (object_id,user_id,choice,updated_at) VALUES (%d,%d,%d,%s) ON DUPLICATE KEY UPDATE choice=VALUES(choice),updated_at=VALUES(updated_at)', $wpdb->prefix . 'riffit_poll_votes', $post->ID, get_current_user_id(), $choice, current_time( 'mysql', true ) ) );
        $this->poll_cache = array();
        return false === $saved ? $this->error( 'poll_failed', 'Could not save your vote.', 500 ) : $this->poll_data( $post->ID, $meta );
    }

    public function upload_image( \WP_REST_Request $request ) {
        $group = $this->writable_group( (int) $request['id'] );
        if ( is_wp_error( $group ) ) { return $group; }
        if ( ( new Auth() )->limited( 'upload:' . get_current_user_id(), 20, DAY_IN_SECONDS ) ) { return $this->error( 'rate_limited', 'You have reached today’s image upload limit.', 429 ); }
        $bytes = base64_decode( $request['data'], true );
        if ( false === $bytes || strlen( $bytes ) > 5 * MB_IN_BYTES || ! strlen( $bytes ) ) { return $this->error( 'invalid_image', 'Choose a JPEG, PNG, or WebP image up to 5 MB.' ); }
        $size = @getimagesizefromstring( $bytes );
        if ( ! $size || ! in_array( $size['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || $size[0] > 8000 || $size[1] > 8000 || $size[0] * $size[1] > 20000000 ) { return $this->error( 'invalid_image', 'Use a JPEG, PNG, or WebP image smaller than 20 megapixels and 8000 pixels per side.' ); }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $temp = wp_tempnam( $request['name'] );
        if ( ! $temp || false === file_put_contents( $temp, $bytes ) ) { return $this->error( 'upload_failed', 'Could not prepare the upload.', 500 ); }
        $checked = wp_check_filetype_and_ext( $temp, $request['name'], array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
        if ( ! $checked['type'] || $checked['type'] !== $size['mime'] ) { wp_delete_file( $temp ); return $this->error( 'invalid_image', 'The filename and image type must match.' ); }
        $upload = array( 'name' => $request['name'], 'tmp_name' => $temp, 'size' => strlen( $bytes ), 'error' => 0 );
        $file = wp_handle_sideload( $upload, array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
        if ( isset( $file['error'] ) ) { wp_delete_file( $temp ); return $this->error( 'upload_failed', 'Could not save the image.', 500 ); }
        $id = wp_insert_attachment( array( 'post_mime_type' => $file['type'], 'post_title' => sanitize_text_field( pathinfo( $request['name'], PATHINFO_FILENAME ) ), 'post_author' => get_current_user_id(), 'post_status' => 'inherit' ), $file['file'], 0, true );
        if ( is_wp_error( $id ) ) { wp_delete_file( $file['file'] ); return $this->error( 'upload_failed', 'Could not save the image.', 500 ); }
        wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
        update_post_meta( $id, '_riffit_upload', 1 ); update_post_meta( $id, '_riffit_community', $group->id );
        update_post_meta( $id, '_wp_attachment_image_alt', $request['alt'] );
        return new \WP_REST_Response( array( 'id' => (int) $id, 'url' => wp_get_attachment_image_url( $id, 'large' ), 'alt' => $request['alt'] ), 201 );
    }
}

<?php
/**
 * Plugin Name: Riffit Core
 * Description: Headless community APIs for the Riffit Spacefast starter.
 * Version: 0.9.1
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Requires Plugins: bbpress, buddypress
 * License: GPL-2.0-or-later
 * Text Domain: riffit-core
 */
namespace Riffit;
if ( ! defined( 'ABSPATH' ) ) { exit; }

const VERSION = '0.9.1';
require_once __DIR__ . '/src/Api.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/WordPressConnect.php';
require_once __DIR__ . '/src/SocialApi.php';
require_once __DIR__ . '/src/MessagesApi.php';
require_once __DIR__ . '/src/ContentApi.php';
require_once __DIR__ . '/src/NotificationsApi.php';
require_once __DIR__ . '/src/ModerationApi.php';
( new Auth() )->hooks();
( new WordPressConnect() )->hooks();

function activate(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = $wpdb->prefix . 'riffit_votes';
    $charset = $wpdb->get_charset_collate();
    dbDelta( "CREATE TABLE {$table} (
        object_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        value smallint NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (object_id,user_id),
        KEY user_id (user_id)
    ) {$charset};" );
    dbDelta( "CREATE TABLE {$wpdb->prefix}riffit_saved (
        user_id bigint(20) unsigned NOT NULL,
        object_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (user_id,object_id),
        KEY object_id (object_id)
    ) {$charset};" );
    dbDelta( "CREATE TABLE {$wpdb->prefix}riffit_blocks (
        user_id bigint(20) unsigned NOT NULL,
        blocked_id bigint(20) unsigned NOT NULL,
        PRIMARY KEY  (user_id,blocked_id),
        KEY blocked_id (blocked_id)
    ) {$charset};" );
    dbDelta( "CREATE TABLE {$wpdb->prefix}riffit_poll_votes (
        object_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        choice smallint unsigned NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (object_id,user_id)
    ) {$charset};" );
    dbDelta( "CREATE TABLE {$wpdb->prefix}riffit_reports (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        object_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        community_id bigint(20) unsigned NOT NULL,
        reason text NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'open',
        created_at datetime NOT NULL,
        resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY reporter_object (user_id,object_id),
        KEY community_status (community_id,status)
    ) {$charset};" );
    Auth::install();
    WordPressConnect::install();
    $components = get_option( 'bp-active-components', array() );
    foreach ( array( 'groups', 'messages', 'xprofile', 'activity', 'notifications' ) as $component ) {
        $components[$component] = 1;
    }
    update_option( 'bp-active-components', $components );
    if ( function_exists( 'buddypress' ) ) {
        require_once buddypress()->plugin_dir . 'bp-core/admin/bp-core-admin-schema.php';
        bp_core_install( $components );
    }
    update_option( 'riffit_schema_version', VERSION, false );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
add_action( 'rest_api_init', static function (): void { ( new ModerationApi() )->register(); } );

add_action( 'init', static function (): void { if ( VERSION !== get_option( 'riffit_schema_version' ) ) { activate(); } }, 20 );

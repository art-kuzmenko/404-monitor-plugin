<?php
/**
 * Uninstall 404 Error Log plugin.
 * Removes all plugin data: options and log table.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'e404_log_options' );
delete_option( 'e404_log_db_version' );

$table_name = $wpdb->prefix . '404_error_log';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

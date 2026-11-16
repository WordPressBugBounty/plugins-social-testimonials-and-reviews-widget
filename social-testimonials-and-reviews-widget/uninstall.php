<?php
/**
 * Uninstall: remove what the AI Visibility features stored (includes/class-repuso-ai-visibility.php).
 * The plugin's older options are left as before, so reinstalling keeps the connection settings.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( (array) get_option( 'rw_ai_w_ids', array() ) as $rw_id ) {
	delete_option( 'rw_ai_w_' . preg_replace( '/[^0-9a-z\-]/i', '', (string) $rw_id ) );
}
foreach ( array( 'rw_ai_w_ids', 'rw_ai_readable', 'rw_installed_new', 'rw_ai_since' ) as $rw_opt ) {
	delete_option( $rw_opt );
}

global $wpdb;
// Transients are keyed per sub-account (rw_ai_sum_{id}, rw_ai_account_{id}, rw_ai_pp_{id}, rw_ai_widgets_{id}).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_rw\_ai\_%' OR option_name LIKE '\_transient\_timeout\_rw\_ai\_%'" );

foreach ( array( 'rw_ai_seen', 'rw_ai_hide_pitch', 'rw_ai_hide_card' ) as $rw_meta ) {
	delete_metadata( 'user', 0, $rw_meta, '', true );
}

wp_clear_scheduled_hook( 'rw_ai_refresh' );
wp_unschedule_hook( 'rw_ai_refresh_widget' );

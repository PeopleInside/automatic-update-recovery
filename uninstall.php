<?php
/**
 * Removes all data stored by Automatic Update Recovery.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function aur_uninstall_site() {
	delete_option( 'aur_recovery_state' );
	delete_option( 'aur_recovery_log' );
	delete_option( 'aur_recovery_enabled' );
	delete_option( 'aur_recovery_exclusions' );
	delete_option( 'aur_recovery.lock' );
	delete_transient( 'aur_recovery_lock' ); // Left over by 1.0.0.
	wp_clear_scheduled_hook( 'aur_recovery_check' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $aur_site_id ) {
		switch_to_blog( $aur_site_id );
		aur_uninstall_site();
		restore_current_blog();
	}
} else {
	aur_uninstall_site();
}

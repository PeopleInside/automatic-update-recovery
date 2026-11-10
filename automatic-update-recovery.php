<?php
/**
 * Plugin Name: Automatic Update Recovery
 * Plugin URI:  https://github.com/PeopleInside/automatic-update-recovery
 * Description: Attempts controlled recovery updates when eligible plugin updates remain pending, featuring GitHub self-update, email alerts, and cron diagnostics.
 * Version:     1.4.1
 * Requires at least: 6.5
 * Tested up to:      7.1.4
 * Requires PHP:      7.4
 * Author:            PeopleInside
 * Author URI:        https://github.com/PeopleInside
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       automatic-update-recovery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Automatic_Update_Recovery {
	const VERSION            = '1.4.1';
	const CRON_HOOK          = 'aur_recovery_check';
	const STATE_OPTION       = 'aur_recovery_state';
	const LOG_OPTION         = 'aur_recovery_log';
	const ENABLED_OPTION     = 'aur_recovery_enabled';
	const EXCLUDE_OPTION     = 'aur_recovery_exclusions';
	const MAX_ATTEMPTS_OPT   = 'aur_max_attempts';
	const EMAIL_FAIL_OPT     = 'aur_email_on_fail';
	const EMAIL_SUCCESS_OPT  = 'aur_email_on_success';
	const LAST_RUN_OPTION    = 'aur_last_run_time';
	const LOCK_NAME          = 'aur_recovery';
	const CAPABILITY         = 'update_plugins';
	const REQUIRED_CHECKS    = 2;
	const LOG_LIMIT          = 100;

	private static $page_hook = '';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_check' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_post_aur_save_settings', array( __CLASS__, 'save_settings' ) );
			add_action( 'admin_post_aur_clear_log', array( __CLASS__, 'clear_log' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
			
			// Inizializza l'updater GitHub corretto (funziona anche in cron/WP-CLI)
			if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				AUR_GitHub_Updater::instance();
			}
		}
	}

	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['aur_six_hours'] ) ) {
			$schedules['aur_six_hours'] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => 'Every six hours (Automatic Update Recovery)',
			);
		}
		return $schedules;
	}

	public static function ensure_schedule() {
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'aur_six_hours', self::CRON_HOOK );
		}
	}

	public static function activate() {
		if ( false === get_option( self::ENABLED_OPTION, false ) ) {
			add_option( self::ENABLED_OPTION, 1, '', false );
		}
		// Default settings
		if ( false === get_option( self::MAX_ATTEMPTS_OPT, false ) ) {
			add_option( self::MAX_ATTEMPTS_OPT, 3, '', false );
		}
		if ( false === get_option( self::EMAIL_FAIL_OPT, false ) ) {
			add_option( self::EMAIL_FAIL_OPT, 1, '', false ); // Default ON
		}
		if ( false === get_option( self::EMAIL_SUCCESS_OPT, false ) ) {
			add_option( self::EMAIL_SUCCESS_OPT, 0, '', false ); // Default OFF
		}
		self::ensure_schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::LOCK_NAME . '.lock' );
	}

	public static function run_check() {
		if ( ! get_option( self::ENABLED_OPTION, 1 ) ) {
			return;
		}
		if ( wp_installing() || ( is_multisite() && ! is_main_site() ) ) {
			return;
		}

		// Registra l'ultima esecuzione
		update_option( self::LAST_RUN_OPTION, time(), false );

		self::load_upgrader_files();

		if ( ! WP_Upgrader::create_lock( self::LOCK_NAME, 15 * MINUTE_IN_SECONDS ) ) {
			return;
		}

		try {
			self::process();
		} catch ( Throwable $error ) {
			self::add_log( 'error', '', $error->getMessage() );
		} finally {
			WP_Upgrader::release_lock( self::LOCK_NAME );
		}
	}

	private static function process() {
		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) || empty( $updates->last_checked ) || empty( $updates->response ) || ! is_array( $updates->response ) ) {
			self::save_state( array() );
			return;
		}

		$check_id   = (string) $updates->last_checked;
		$plugins    = get_plugins();
		$state      = self::get_state();
		$excludes   = self::get_exclusions();
		$excludes[] = plugin_basename( __FILE__ );
		$updater    = new WP_Automatic_Updater();
		$eligible   = array();
		$max_attempts = max( 1, (int) get_option( self::MAX_ATTEMPTS_OPT, 3 ) );
		$min_age    = max( 0, (int) apply_filters( 'aur_min_pending_seconds', 12 * HOUR_IN_SECONDS ) );

		foreach ( $updates->response as $plugin_file => $item ) {
			if ( ! is_string( $plugin_file ) || ! isset( $plugins[ $plugin_file ] ) || ! is_object( $item ) ) {
				continue;
			}
			if ( in_array( $plugin_file, $excludes, true ) ) {
				continue;
			}

			$installed_version = isset( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '';
			$target_version    = isset( $item->new_version ) ? (string) $item->new_version : '';

			if ( '' === $installed_version || '' === $target_version || ! version_compare( $target_version, $installed_version, '>' ) ) {
				continue;
			}

			$item         = clone $item;
			$item->plugin = $plugin_file;

			if ( ! $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR ) ) {
				continue;
			}

			$eligible[ $plugin_file ] = true;
			$record = self::new_record( $target_version );
			
			if ( isset( $state[ $plugin_file ] ) && is_array( $state[ $plugin_file ] )
				&& isset( $state[ $plugin_file ]['target'] ) && $state[ $plugin_file ]['target'] === $target_version ) {
				$record = array_merge( $record, $state[ $plugin_file ] );
			}

			if ( $record['last_check'] !== $check_id ) {
				$record['last_check'] = $check_id;
				$record['checks']     = min( self::REQUIRED_CHECKS, (int) $record['checks'] + 1 );
			}

			$state[ $plugin_file ] = $record;

			$due = (int) $record['checks'] >= self::REQUIRED_CHECKS
				&& ( (int) ( $record['attempts'] ?? 0 ) < $max_attempts )
				&& ( time() - (int) $record['first_seen'] ) >= $min_age;

			if ( $due ) {
				self::attempt( $updater, $plugin_file, $item, $record, $state, $max_attempts );
			}
		}

		foreach ( array_keys( $state ) as $tracked_plugin ) {
			if ( ! isset( $eligible[ $tracked_plugin ] ) ) {
				unset( $state[ $tracked_plugin ] );
			}
		}

		self::save_state( $state );
	}

	private static function attempt( $updater, $plugin_file, $item, array $record, array &$state, $max_attempts ) {
		if ( ! WP_Upgrader::create_lock( 'auto_updater' ) ) {
			self::add_log( 'skipped', $plugin_file, __( 'The native WordPress updater is busy. The recovery attempt will be retried on the next run.', 'automatic-update-recovery' ) );
			return;
		}

		$record['attempts']        = (int) ( $record['attempts'] ?? 0 ) + 1;
		$record['last_status']     = 'attempting';
		$record['last_attempt_at'] = time();
		$state[ $plugin_file ]     = $record;
		self::save_state( $state );

		self::add_log(
			'attempt',
			$plugin_file,
			sprintf(
				/* translators: 1: target version, 2: attempt number, 3: max attempts. */
				__( 'Starting recovery attempt %2$d/%3$d for version %1$s.', 'automatic-update-recovery' ),
				$record['target'],
				$record['attempts'],
				$max_attempts
			)
		);

		try {
			$result = $updater->update( 'plugin', $item );
		} catch ( Throwable $error ) {
			$result = new WP_Error( 'aur_exception', $error->getMessage() );
		} finally {
			WP_Upgrader::release_lock( 'auto_updater' );
		}

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		$plugins_after = get_plugins();
		$after_version = isset( $plugins_after[ $plugin_file ]['Version'] ) ? (string) $plugins_after[ $plugin_file ]['Version'] : '';

		$email_on_fail    = (bool) get_option( self::EMAIL_FAIL_OPT, 1 );
		$email_on_success = (bool) get_option( self::EMAIL_SUCCESS_OPT, 0 );
		$admin_email      = get_option( 'admin_email' );

		if ( '' !== $after_version && version_compare( $after_version, $record['target'], '>=' ) ) {
			$msg = sprintf( __( 'Recovery successful. Installed version: %s.', 'automatic-update-recovery' ), $after_version );
			self::add_log( 'success', $plugin_file, $msg );
			unset( $state[ $plugin_file ] );
			
			if ( $email_on_success ) {
				wp_mail( $admin_email, '[AUR] Update Successful: ' . $plugin_file, $msg );
			}
		} else {
			$message                = self::result_message( $result );
			$record['last_status']  = 'failed';
			$record['last_message'] = $message;
			$state[ $plugin_file ]  = $record;
			self::add_log( 'failed', $plugin_file, $message );

			if ( $email_on_fail ) {
				$mail_msg = sprintf( "Plugin: %s\nTarget: %s\nAttempt: %d/%d\nError: %s", $plugin_file, $record['target'], $record['attempts'], $max_attempts, $message );
				wp_mail( $admin_email, '[AUR] Update Failed: ' . $plugin_file, $mail_msg );
			}
		}

		self::save_state( $state );
	}

	private static function new_record( $target_version ) {
		return array(
			'target'          => (string) $target_version,
			'checks'          => 0,
			'attempts'        => 0,
			'last_check'      => '',
			'first_seen'      => time(),
			'last_status'     => 'pending',
			'last_message'    => '',
			'last_attempt_at' => 0,
		);
	}

	private static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	private static function load_upgrader_files() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
	}

	private static function result_message( $result ) {
		if ( is_wp_error( $result ) ) {
			$message = implode( ' | ', $result->get_error_messages() );
			return '' !== $message ? $message : __( 'The updater returned an unspecified error.', 'automatic-update-recovery' );
		}
		if ( false === $result ) {
			return __( 'WordPress declined the recovery update. Check automatic-update settings and eligibility.', 'automatic-update-recovery' );
		}
		if ( null === $result ) {
			return __( 'The updater did not return a result. Check WordPress update logs.', 'automatic-update-recovery' );
		}
		return __( 'The updater finished, but the installed version did not change. Check update logs.', 'automatic-update-recovery' );
	}

	private static function get_exclusions() {
		$raw = get_option( self::EXCLUDE_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', (string) $raw );
		}
		$clean = array();
		foreach ( $raw as $plugin_file ) {
			$plugin_file = trim( (string) $plugin_file );
			if ( '' !== $plugin_file ) {
				$clean[] = plugin_basename( $plugin_file );
			}
		}
		return array_values( array_unique( $clean ) );
	}

	private static function add_log( $status, $plugin_file, $message ) {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		$message = sanitize_text_field( wp_strip_all_tags( (string) $message ) );
		$log[] = array(
			'time'    => time(),
			'status'  => sanitize_key( $status ),
			'plugin'  => sanitize_text_field( $plugin_file ),
			'message' => wp_html_excerpt( $message, 500, '…' ),
		);
		if ( count( $log ) > self::LOG_LIMIT ) {
			$log = array_slice( $log, -self::LOG_LIMIT );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	public static function clear_log() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the log.', 'automatic-update-recovery' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'aur_clear_log' );
		update_option( self::LOG_OPTION, array(), false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'automatic-update-recovery', 'log_cleared' => '1' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function action_links( $links ) {
		$url = admin_url( 'tools.php?page=automatic-update-recovery' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'automatic-update-recovery' ) . '</a>' );
		return $links;
	}

	public static function admin_menu() {
		self::$page_hook = (string) add_management_page(
			__( 'Automatic Update Recovery', 'automatic-update-recovery' ),
			__( 'Update Recovery', 'automatic-update-recovery' ),
			self::CAPABILITY,
			'automatic-update-recovery',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( '' === self::$page_hook || self::$page_hook !== $hook_suffix ) {
			return;
		}
		$js = "document.addEventListener('DOMContentLoaded',function(){var f=document.getElementById('aur-filter');"
			. "var o=document.getElementById('aur-only-updates');if(!f){return;}"
			. "function apply(){var q=f.value.toLowerCase().trim();var only=o&&o.checked;"
			. "document.querySelectorAll('#aur-plugins tbody tr').forEach(function(r){"
			. "var t=(r.getAttribute('data-search')||'').toLowerCase();"
			. "var ok=(q===''||t.indexOf(q)!==-1)&&(!only||r.getAttribute('data-update')==='1');"
			. "r.style.display=ok?'':'none';});}"
			. "f.addEventListener('input',apply);if(o){o.addEventListener('change',apply);}});";
		wp_register_script( 'aur-admin', false, array(), self::VERSION, true );
		wp_enqueue_script( 'aur-admin' );
		wp_add_inline_script( 'aur-admin', $js );
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$enabled        = (bool) get_option( self::ENABLED_OPTION, 1 );
		$max_attempts   = (int) get_option( self::MAX_ATTEMPTS_OPT, 3 );
		$email_fail     = (bool) get_option( self::EMAIL_FAIL_OPT, 1 );
		$email_success  = (bool) get_option( self::EMAIL_SUCCESS_OPT, 0 );
		$exclusions     = self::get_exclusions();
		$state          = self::get_state();
		$log            = get_option( self::LOG_OPTION, array() );
		$log            = is_array( $log ) ? array_reverse( $log ) : array();
		$next           = wp_next_scheduled( self::CRON_HOOK );
		$last_run       = get_option( self::LAST_RUN_OPTION, 0 );
		$disabled_cron  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$installed = get_plugins();
		self::load_upgrader_files();
		$updater = new WP_Automatic_Updater();
		$offers  = get_site_transient( 'update_plugins' );
		$pending = self::pending_updates( $offers, $installed );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$just_saved    = isset( $_GET['updated'] );
		$log_cleared   = isset( $_GET['log_cleared'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Automatic Update Recovery', 'automatic-update-recovery' ); ?></h1>

			<?php if ( $just_saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'automatic-update-recovery' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $log_cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Diagnostic log cleared successfully.', 'automatic-update-recovery' ); ?></p></div>
			<?php endif; ?>

			<?php $blocked = self::global_block_reason( $updater ); ?>
			<?php if ( '' !== $blocked ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Warning:', 'automatic-update-recovery' ); ?></strong> <?php echo esc_html( $blocked ); ?></p></div>
			<?php endif; ?>

			<?php self::render_cron_health( $disabled_cron, $next, $last_run ); ?>
			<?php self::render_pending_summary( $updater, $offers, $pending, $exclusions ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aur_save_settings">
				<?php wp_nonce_field( 'aur_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Recovery system', 'automatic-update-recovery' ); ?></th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Enable recovery attempts', 'automatic-update-recovery' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Max attempts per version', 'automatic-update-recovery' ); ?></th>
						<td>
							<input type="number" name="max_attempts" value="<?php echo esc_attr( $max_attempts ); ?>" min="1" max="10" class="small-text"> 
							<p class="description"><?php esc_html_e( 'Number of times to retry a failed update for the same version.', 'automatic-update-recovery' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email notifications', 'automatic-update-recovery' ); ?></th>
						<td>
							<label><input type="checkbox" name="email_fail" value="1" <?php checked( $email_fail ); ?>> <?php esc_html_e( 'Send email on update failure', 'automatic-update-recovery' ); ?></label><br>
							<label><input type="checkbox" name="email_success" value="1" <?php checked( $email_success ); ?>> <?php esc_html_e( 'Send email on successful update', 'automatic-update-recovery' ); ?></label>
							<p class="description"><?php printf( esc_html__( 'Emails will be sent to: %s', 'automatic-update-recovery' ), esc_html( get_option( 'admin_email' ) ) ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Excluded plugins', 'automatic-update-recovery' ); ?></h2>
				<?php self::render_exclusion_table( $installed, $exclusions, $updater, $offers, $pending ); ?>
				<?php submit_button( __( 'Save settings', 'automatic-update-recovery' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Updates currently tracked', 'automatic-update-recovery' ); ?></h2>
			<?php if ( empty( $state ) ) : ?>
				<p><?php esc_html_e( 'No updates are currently being tracked.', 'automatic-update-recovery' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Plugin', 'automatic-update-recovery' ); ?></th><th><?php esc_html_e( 'Target', 'automatic-update-recovery' ); ?></th><th><?php esc_html_e( 'Checks', 'automatic-update-recovery' ); ?></th><th><?php esc_html_e( 'Attempts', 'automatic-update-recovery' ); ?></th><th><?php esc_html_e( 'Status', 'automatic-update-recovery' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $state as $tracked_file => $record ) : ?>
							<?php if ( ! is_array( $record ) ) { continue; } ?>
							<tr>
								<td><code><?php echo esc_html( $tracked_file ); ?></code></td>
								<td><?php echo esc_html( $record['target'] ?? '' ); ?></td>
								<td><?php echo esc_html( (string) ( $record['checks'] ?? 0 ) . ' / ' . self::REQUIRED_CHECKS ); ?></td>
								<td><?php echo esc_html( (string) ( $record['attempts'] ?? 0 ) . ' / ' . $max_attempts ); ?></td>
								<td><?php echo esc_html( strtoupper( (string) ( $record['last_status'] ?? '' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Diagnostic Log', 'automatic-update-recovery' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="aur_clear_log">
				<?php wp_nonce_field( 'aur_clear_log' ); ?>
				<?php submit_button( __( 'Clear Log', 'automatic-update-recovery' ), 'secondary', 'clear_log', false ); ?>
			</form>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'No activity recorded yet.', 'automatic-update-recovery' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:10px;">
					<thead><tr><th><?php esc_html_e( 'Date', 'automatic-update-recovery' ); ?></th><th><?php esc_html_e( 'Status', 'automatic-update-recovery' ); ?></th><th><?php esc_html_e( 'Plugin', 'automatic-update-recovery' ); ?></th><th><?php esc_html_e( 'Details', 'automatic-update-recovery' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( array_slice( $log, 0, 50 ) as $entry ) : ?>
							<?php $time = is_numeric( $entry['time'] ) ? wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ) : (string) $entry['time']; ?>
							<tr>
								<td><?php echo esc_html( $time ); ?></td>
								<td><?php echo esc_html( strtoupper( $entry['status'] ?? '' ) ); ?></td>
								<td><code><?php echo esc_html( $entry['plugin'] ?? '' ); ?></code></td>
								<td><?php echo esc_html( $entry['message'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_cron_health( $disabled_cron, $next, $last_run ) {
		$status_class = $disabled_cron ? 'notice-error' : 'notice-success';
		$status_text  = $disabled_cron ? __( 'WP-Cron is disabled in wp-config.php (DISABLE_WP_CRON is true). Ensure you have a real server cron job set up, otherwise recovery checks will not run.', 'automatic-update-recovery' ) : __( 'WP-Cron appears to be active.', 'automatic-update-recovery' );
		?>
		<div class="notice <?php echo esc_attr( $status_class ); ?> inline">
			<p><strong><?php esc_html_e( 'Cron Health:', 'automatic-update-recovery' ); ?></strong> <?php echo esc_html( $status_text ); ?></p>
			<p>
				<?php if ( $last_run ) : ?>
					<?php printf( esc_html__( 'Last successful execution: %s', 'automatic-update-recovery' ), esc_html( wp_date( 'Y-m-d H:i:s', (int) $last_run ) ) ); ?><br>
				<?php else : ?>
					<?php esc_html_e( 'Last successful execution: Never', 'automatic-update-recovery' ); ?><br>
				<?php endif; ?>
				<?php if ( $next ) : ?>
					<?php printf( esc_html__( 'Next scheduled check: %s', 'automatic-update-recovery' ), esc_html( wp_date( 'Y-m-d H:i:s', (int) $next ) ) ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Next scheduled check: Not scheduled (will be scheduled on next initialization).', 'automatic-update-recovery' ); ?>
		<?php endif; ?>
			</p>
		</div>
		<?php
	}

	private static function render_exclusion_table( array $installed, array $exclusions, $updater, $offers, array $pending ) {
		$self    = plugin_basename( __FILE__ );
		$missing = array_diff( $exclusions, array_keys( $installed ) );
		?>
		<p class="description"><?php esc_html_e( 'Tick the plugins that must never be updated by the recovery system.', 'automatic-update-recovery' ); ?></p>
		<p>
			<label class="screen-reader-text" for="aur-filter"><?php esc_html_e( 'Filter plugins', 'automatic-update-recovery' ); ?></label>
			<input type="search" id="aur-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter plugins…', 'automatic-update-recovery' ); ?>" autocomplete="off">
			&nbsp;<label><input type="checkbox" id="aur-only-updates"> <?php esc_html_e( 'Show only plugins with updates', 'automatic-update-recovery' ); ?></label>
		</p>
		<table class="widefat striped" id="aur-plugins">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column"><input type="checkbox" id="aur-check-all"></td>
					<th scope="col"><?php esc_html_e( 'Plugin', 'automatic-update-recovery' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Version', 'automatic-update-recovery' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Update available', 'automatic-update-recovery' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'automatic-update-recovery' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Auto-update eligible', 'automatic-update-recovery' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $installed as $plugin_file => $data ) : ?>
					<?php
					$name    = ! empty( $data['Name'] ) ? $data['Name'] : $plugin_file;
					$is_self = ( $plugin_file === $self );
					$box_id  = 'aur-ex-' . md5( $plugin_file );
					$active  = is_plugin_active( $plugin_file ) || is_plugin_active_for_network( $plugin_file );
					?>
					<tr data-update="<?php echo isset( $pending[ $plugin_file ] ) ? '1' : '0'; ?>" data-search="<?php echo esc_attr( $name . ' ' . $plugin_file ); ?>">
						<th scope="row" class="check-column">
							<?php if ( $is_self ) : ?>
								<span aria-hidden="true">&mdash;</span>
							<?php else : ?>
								<input type="checkbox" id="<?php echo esc_attr( $box_id ); ?>" name="excluded[]" value="<?php echo esc_attr( $plugin_file ); ?>" <?php checked( in_array( $plugin_file, $exclusions, true ) ); ?>>
							<?php endif; ?>
						</th>
						<td><strong><?php echo esc_html( $name ); ?></strong><br><code><?php echo esc_html( $plugin_file ); ?></code><?php if ( $is_self ) : ?><br><em><?php esc_html_e( 'Always excluded.', 'automatic-update-recovery' ); ?></em><?php endif; ?></td>
						<td><?php echo esc_html( $data['Version'] ?? '' ); ?></td>
						<td><?php echo isset( $pending[ $plugin_file ] ) ? '<strong>' . esc_html( $pending[ $plugin_file ] ) . '</strong>' : '&mdash;'; ?></td>
						<td><?php echo $active ? esc_html__( 'Active', 'automatic-update-recovery' ) : esc_html__( 'Inactive', 'automatic-update-recovery' ); ?></td>
						<td><?php echo self::is_eligible( $updater, $plugin_file, $offers ) ? esc_html__( 'Yes', 'automatic-update-recovery' ) : esc_html__( 'No', 'automatic-update-recovery' ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php foreach ( $missing as $plugin_file ) : ?>
					<tr data-update="0" data-search="<?php echo esc_attr( $plugin_file ); ?>">
						<th scope="row" class="check-column"><input type="checkbox" name="excluded[]" value="<?php echo esc_attr( $plugin_file ); ?>" checked></th>
						<td><code><?php echo esc_html( $plugin_file ); ?></code></td>
						<td colspan="4"><em><?php esc_html_e( 'Not installed (exclusion kept; untick to remove)', 'automatic-update-recovery' ); ?></em></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function pending_updates( $offers, array $installed ) {
		$pending = array();
		if ( ! is_object( $offers ) || empty( $offers->response ) || ! is_array( $offers->response ) ) {
			return $pending;
		}
		foreach ( $offers->response as $plugin_file => $item ) {
			if ( ! is_string( $plugin_file ) || ! isset( $installed[ $plugin_file ] ) || ! is_object( $item ) || empty( $item->new_version ) ) {
				continue;
			}
			$current = isset( $installed[ $plugin_file ]['Version'] ) ? (string) $installed[ $plugin_file ]['Version'] : '';
			if ( '' !== $current && version_compare( (string) $item->new_version, $current, '>' ) ) {
				$pending[ $plugin_file ] = (string) $item->new_version;
			}
		}
		return $pending;
	}

	private static function render_pending_summary( $updater, $offers, array $pending, array $exclusions ) {
		$excluded = $exclusions;
		$excluded[] = plugin_basename( __FILE__ );
		$total = count( $pending );
		$count = array( 'auto' => 0, 'excluded' => 0, 'manual' => 0 );
		foreach ( array_keys( $pending ) as $plugin_file ) {
			if ( in_array( $plugin_file, $excluded, true ) ) {
				++$count['excluded'];
			} elseif ( self::is_eligible( $updater, $plugin_file, $offers ) ) {
				++$count['auto'];
			} else {
				++$count['manual'];
			}
		}
		$last = ( is_object( $offers ) && ! empty( $offers->last_checked ) ) ? (int) $offers->last_checked : 0;
		?>
		<div class="notice notice-info inline">
			<p><strong><?php echo esc_html( sprintf( _n( '%d plugin has an update available.', '%d plugins have updates available.', $total, 'automatic-update-recovery' ), $total ) ); ?></strong>
			<?php if ( $total > 0 ) : ?><br><?php echo esc_html( sprintf( __( 'Eligible for auto-update: %1$d · Excluded here: %2$d · Manual update needed: %3$d', 'automatic-update-recovery' ), $count['auto'], $count['excluded'], $count['manual'] ) ); ?><?php endif; ?>
			<br><em><?php echo $last > 0 ? esc_html( sprintf( __( 'Last WP update check: %s.', 'automatic-update-recovery' ), wp_date( 'Y-m-d H:i', $last ) ) ) : esc_html_e( 'No update check recorded yet.', 'automatic-update-recovery' ); ?></em></p>
		</div>
		<?php
	}

	private static function is_eligible( $updater, $plugin_file, $offers ) {
		$item = null;
		if ( is_object( $offers ) ) {
			if ( isset( $offers->response[ $plugin_file ] ) && is_object( $offers->response[ $plugin_file ] ) ) {
				$item = $offers->response[ $plugin_file ];
			} elseif ( isset( $offers->no_update[ $plugin_file ] ) && is_object( $offers->no_update[ $plugin_file ] ) ) {
				$item = $offers->no_update[ $plugin_file ];
			}
		}
		$item = is_object( $item ) ? clone $item : new stdClass();
		if ( empty( $item->slug ) ) {
			$dir        = dirname( $plugin_file );
			$item->slug = '.' !== $dir ? $dir : basename( $plugin_file, '.php' );
		}
		$item->plugin = $plugin_file;
		return (bool) $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR );
	}

	private static function global_block_reason( $updater ) {
		if ( $updater->is_disabled() ) {
			return __( 'Automatic updates are disabled site-wide (AUTOMATIC_UPDATER_DISABLED or filter).', 'automatic-update-recovery' );
		}
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			return __( 'File modifications are not allowed (e.g., DISALLOW_FILE_MODS).', 'automatic-update-recovery' );
		}
		return '';
	}

	private static function sanitize_exclusions( array $submitted, array $installed_files, array $existing ) {
		$allowed = array_merge( $installed_files, $existing );
		$clean   = array();
		foreach ( $submitted as $plugin_file ) {
			if ( is_string( $plugin_file ) && in_array( $plugin_file, $allowed, true ) ) {
				$clean[] = $plugin_file;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	public static function save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'automatic-update-recovery' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'aur_save_settings' );
		update_option( self::ENABLED_OPTION, isset( $_POST['enabled'] ) ? 1 : 0, false );
		update_option( self::MAX_ATTEMPTS_OPT, isset( $_POST['max_attempts'] ) ? max( 1, (int) $_POST['max_attempts'] ) : 3, false );
		update_option( self::EMAIL_FAIL_OPT, isset( $_POST['email_fail'] ) ? 1 : 0, false );
		update_option( self::EMAIL_SUCCESS_OPT, isset( $_POST['email_success'] ) ? 1 : 0, false );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted = isset( $_POST['excluded'] ) && is_array( $_POST['excluded'] ) ? wp_unslash( $_POST['excluded'] ) : array();
		update_option( self::EXCLUDE_OPTION, self::sanitize_exclusions( $submitted, array_keys( get_plugins() ), self::get_exclusions() ), false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'automatic-update-recovery', 'updated' => '1' ), admin_url( 'tools.php' ) ) );
		exit;
	}
}

/**
 * CORRECTED GitHub Auto-Updater Class
 * Fixes: 1. Added github-releases.githubusercontent.com to trusted hosts.
 *        2. Reduced cache TTL to 2 hours for faster detection.
 *        3. Robust folder renaming fallback.
 */
class AUR_GitHub_Updater {
	private static ?AUR_GitHub_Updater $instance = null;
	const GITHUB_REPO      = 'PeopleInside/automatic-update-recovery';
	const GITHUB_API_URL   = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
	const PLUGIN_SLUG      = 'automatic-update-recovery';
	const CACHE_KEY        = 'aur_github_latest_release';
	const CACHE_TTL        = 2 * HOUR_IN_SECONDS; // FIX: Reduced from 12h to 2h
	const CACHE_FAIL_TTL   = 15 * MINUTE_IN_SECONDS;

	public static function instance(): AUR_GitHub_Updater {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update_info' ) );
		add_filter( 'plugins_api', array( $this, 'inject_plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package_host' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	private function plugin_basename(): string {
		return plugin_basename( dirname( __DIR__ ) . '/automatic-update-recovery/automatic-update-recovery.php' ); // Fallback safe
	}

	private function installed_version( $transient, string $basename ): string {
		if ( isset( $transient->checked[ $basename ] ) && '' !== $transient->checked[ $basename ] ) {
			return (string) $transient->checked[ $basename ];
		}
		return Automatic_Update_Recovery::VERSION;
	}

	private function target_dir_name(): string {
		return self::PLUGIN_SLUG;
	}

	public function get_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'skip' === $cached ) {
			return new WP_Error( 'aur_github_check_deferred', __( 'Update check deferred after recent error.', 'automatic-update-recovery' ) );
		}

		$response = wp_remote_get(
			self::GITHUB_API_URL,
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AUR/' . Automatic_Update_Recovery::VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->remember_failure( $response );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return $this->remember_failure( new WP_Error( 'aur_github_http_error', sprintf( __( 'GitHub responded with code %d.', 'automatic-update-recovery' ), $code ) ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return $this->remember_failure( new WP_Error( 'aur_github_bad_response', __( 'Invalid GitHub response.', 'automatic-update-recovery' ) ) );
		}

		$version = preg_replace( '/^v/i', '', (string) $body['tag_name'] );
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return $this->remember_failure( new WP_Error( 'aur_github_invalid_version', __( 'Invalid release version number.', 'automatic-update-recovery' ) ) );
		}

		$package_url = $this->resolve_package_url( $body );
		if ( is_wp_error( $package_url ) ) {
			return $this->remember_failure( $package_url );
		}

		$result = array(
			'version'     => $version,
			'package_url' => $package_url,
			'html_url'    => isset( $body['html_url'] ) ? esc_url_raw( (string) $body['html_url'] ) : 'https://github.com/' . self::GITHUB_REPO . '/releases',
			'body'        => isset( $body['body'] ) ? (string) $body['body'] : '',
		);

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	private function remember_failure( WP_Error $error ): WP_Error {
		set_transient( self::CACHE_KEY, 'skip', self::CACHE_FAIL_TTL );
		return $error;
	}

	private function resolve_package_url( array $release ) {
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
				continue;
			}
			$asset_name = strtolower( (string) $asset['name'] );
			if ( '.zip' !== substr( $asset_name, -4 ) ) {
				continue;
			}
			$url = (string) $asset['browser_download_url'];
			if ( $this->is_trusted_github_url( $url ) ) {
				return esc_url_raw( $url );
			}
		}

		if ( ! empty( $release['zipball_url'] ) && $this->is_trusted_github_url( (string) $release['zipball_url'] ) ) {
			return esc_url_raw( (string) $release['zipball_url'] );
		}

		return new WP_Error( 'aur_github_no_package', __( 'No downloadable package found for this release.', 'automatic-update-recovery' ) );
	}

	private function is_trusted_github_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' !== $parts['scheme'] ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		// FIX: Added 'github-releases.githubusercontent.com' to prevent download blocking
		return in_array( $host, array( 'github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com' ), true );
	}

	public function inject_update_info( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) ) {
			return $transient;
		}

		// Use a reliable basename detection
		$basename = 'automatic-update-recovery/automatic-update-recovery.php';
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$installed_version = $this->installed_version( $transient, $basename );

		if ( ! version_compare( $release['version'], $installed_version, '>' ) ) {
			unset( $transient->response[ $basename ] );
			$transient->no_update[ $basename ] = $this->build_item( $installed_version, '', $release['html_url'], $basename );
			return $transient;
		}

		unset( $transient->no_update[ $basename ] );
		$transient->response[ $basename ] = $this->build_item( $release['version'], $release['package_url'], $release['html_url'], $basename );
		return $transient;
	}

	private function build_item( string $version, string $package_url, string $html_url, string $basename ): stdClass {
		$item = new stdClass();
		$item->id           = 'github.com/' . self::GITHUB_REPO;
		$item->slug         = self::PLUGIN_SLUG;
		$item->plugin       = $basename;
		$item->new_version  = $version;
		$item->url          = $html_url;
		$item->package      = $package_url;
		$item->tested       = '';
		$item->requires_php = '';
		$item->icons        = array();
		$item->banners      = array();
		$item->banners_rtl  = array();
		return $item;
	}

	public function inject_plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}
		if ( self::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( is_wp_error( $release ) ) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = 'Automatic Update Recovery';
		$info->slug          = self::PLUGIN_SLUG;
		$info->version       = $release['version'];
		$info->author        = '<a href="https://github.com/PeopleInside">PeopleInside</a>';
		$info->homepage      = $release['html_url'];
		$info->download_link = $release['package_url'];
		$info->sections      = array(
			'description' => wp_kses_post( wpautop( $release['body'] ) ),
		);
		return $info;
	}

	public function verify_package_host( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$basename = 'automatic-update-recovery/automatic-update-recovery.php';
		$is_ours  = ( ! empty( $hook_extra['plugin'] ) && $basename === (string) $hook_extra['plugin'] );

		if ( ! $is_ours && $upgrader instanceof Plugin_Upgrader && ! empty( $upgrader->skin->plugin ) ) {
			$is_ours = ( $basename === $upgrader->skin->plugin );
		}

		if ( ! $is_ours ) {
			return $reply;
		}

		if ( ! $this->is_trusted_github_url( (string) $package ) ) {
			return new WP_Error( 'aur_untrusted_package_host', __( 'Update package does not come from a trusted GitHub host: download blocked.', 'automatic-update-recovery' ) );
		}
		return $reply;
	}

	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$plugin = isset( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
		$basename = 'automatic-update-recovery/automatic-update-recovery.php';
		if ( $basename !== $plugin ) {
			return $source;
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			return $source;
		}

		$target_dir     = $this->target_dir_name();
		$source_dirname = basename( untrailingslashit( $source ) );
		if ( $target_dir === $source_dirname ) {
			return $source;
		}

		$desired_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $target_dir . '/';

		if ( $wp_filesystem->exists( $desired_source ) ) {
			$wp_filesystem->delete( $desired_source, true );
		}

		$moved = $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired_source ) );
		if ( ! $moved ) {
			return new WP_Error( 'aur_rename_failed', __( 'Could not rename the downloaded package folder.', 'automatic-update-recovery' ) );
		}
		return $desired_source;
	}

	public function clear_cache_after_update( $upgrader, $hook_extra ): void {
		if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
			return;
		}
		if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}
		$plugins = array();
		if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$plugins = $hook_extra['plugins'];
		} elseif ( ! empty( $hook_extra['plugin'] ) ) {
			$plugins = array( $hook_extra['plugin'] );
		}
		if ( in_array( 'automatic-update-recovery/automatic-update-recovery.php', $plugins, true ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}
}

Automatic_Update_Recovery::init();
register_activation_hook( __FILE__, array( 'Automatic_Update_Recovery', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Automatic_Update_Recovery', 'deactivate' ) );

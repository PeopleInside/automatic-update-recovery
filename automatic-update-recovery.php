<?php
/**
 * Plugin Name: Automatic Update Recovery
 * Description: Attempts a controlled recovery update when an eligible plugin update remains pending across two separate update checks.
 * Version: 1.3.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: PeopleInside
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: automatic-update-recovery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Automatic_Update_Recovery {
	const VERSION         = '1.3.0';
	const CRON_HOOK       = 'aur_recovery_check';
	const STATE_OPTION    = 'aur_recovery_state';
	const LOG_OPTION      = 'aur_recovery_log';
	const ENABLED_OPTION  = 'aur_recovery_enabled';
	const EXCLUDE_OPTION  = 'aur_recovery_exclusions';
	const LOCK_NAME       = 'aur_recovery';
	const CAPABILITY      = 'update_plugins';
	const REQUIRED_CHECKS = 2;
	const LOG_LIMIT       = 100;

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Bootstrap.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_check' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_post_aur_save_settings', array( __CLASS__, 'save_settings' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
		}
	}

	/**
	 * Add a six-hour recurrence.
	 *
	 * The label is intentionally not translated: this filter can run before
	 * `init`, and translating that early triggers notices on WordPress 6.7+.
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['aur_six_hours'] ) ) {
			$schedules['aur_six_hours'] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => 'Every six hours (Automatic Update Recovery)',
			);
		}
		return $schedules;
	}

	/**
	 * Ensure the internal WP-Cron event exists (main site only on multisite).
	 */
	public static function ensure_schedule() {
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'aur_six_hours', self::CRON_HOOK );
		}
	}

	/**
	 * Activation: enable by default and schedule the first check.
	 */
	public static function activate() {
		if ( false === get_option( self::ENABLED_OPTION, false ) ) {
			add_option( self::ENABLED_OPTION, 1, '', false );
		}
		self::ensure_schedule();
	}

	/**
	 * Deactivation: remove only our scheduled event and lock.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::LOCK_NAME . '.lock' );
	}

	/**
	 * Entry point of the scheduled event.
	 */
	public static function run_check() {
		if ( ! get_option( self::ENABLED_OPTION, 1 ) ) {
			return;
		}

		if ( wp_installing() || ( is_multisite() && ! is_main_site() ) ) {
			return;
		}

		self::load_upgrader_files();

		// Atomic lock (INSERT IGNORE on the options table), unlike get/set_transient.
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

	/**
	 * Count distinct update checks and attempt recovery when due.
	 */
	private static function process() {
		/*
		 * Update check only, nothing is installed. WordPress throttles this
		 * call itself, so `last_checked` only changes on a real new check.
		 */
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
		$excludes[] = plugin_basename( __FILE__ ); // Never replace ourselves while running.
		$updater    = new WP_Automatic_Updater();
		$eligible   = array();

		/**
		 * Minimum time an update must have been pending before a recovery
		 * attempt, so that manual "check again" clicks or admin page loads
		 * cannot make two checks happen within minutes.
		 */
		$min_age = max( 0, (int) apply_filters( 'aur_min_pending_seconds', 12 * HOUR_IN_SECONDS ) );

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

			/*
			 * Work on a copy and make sure `plugin` is set: the native updater
			 * relies on it, and some third-party (e.g. GitHub) updaters omit it.
			 */
			$item         = clone $item;
			$item->plugin = $plugin_file;

			/*
			 * Authoritative WordPress eligibility check: respects
			 * auto_update_plugin, Easy Updates Manager, auto-update
			 * preferences, file-mod constraints, VCS checks and requires_php.
			 */
			if ( ! $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR ) ) {
				continue;
			}

			$eligible[ $plugin_file ] = true;

			$record = self::new_record( $target_version );
			if ( isset( $state[ $plugin_file ] ) && is_array( $state[ $plugin_file ] )
				&& isset( $state[ $plugin_file ]['target'] ) && $state[ $plugin_file ]['target'] === $target_version ) {
				$record = array_merge( $record, $state[ $plugin_file ] );
			}

			// Only a new `last_checked` value counts as a new check.
			if ( $record['last_check'] !== $check_id ) {
				$record['last_check'] = $check_id;
				$record['checks']     = min( self::REQUIRED_CHECKS, (int) $record['checks'] + 1 );

				self::add_log(
					'pending',
					$plugin_file,
					sprintf(
						/* translators: 1: installed version, 2: available version, 3: check count, 4: required checks. */
						__( 'Update %1$s → %2$s is still pending (check %3$d of %4$d).', 'automatic-update-recovery' ),
						$installed_version,
						$target_version,
						$record['checks'],
						self::REQUIRED_CHECKS
					)
				);
			}

			$state[ $plugin_file ] = $record;

			$due = (int) $record['checks'] >= self::REQUIRED_CHECKS
				&& empty( $record['attempted'] )
				&& ( time() - (int) $record['first_seen'] ) >= $min_age;

			if ( $due ) {
				self::attempt( $updater, $plugin_file, $item, $record, $state );
			}
		}

		// Drop tracking for plugins whose offer disappeared or are no longer eligible.
		foreach ( array_keys( $state ) as $tracked_plugin ) {
			if ( ! isset( $eligible[ $tracked_plugin ] ) ) {
				unset( $state[ $tracked_plugin ] );
			}
		}

		self::save_state( $state );
	}

	/**
	 * Run the single recovery attempt for a plugin/target version.
	 */
	private static function attempt( $updater, $plugin_file, $item, array $record, array &$state ) {
		// Share the lock of the native auto-updater to avoid concurrent upgrades.
		if ( ! WP_Upgrader::create_lock( 'auto_updater' ) ) {
			self::add_log( 'skipped', $plugin_file, __( 'The native WordPress updater is busy. The recovery attempt will be retried on the next run.', 'automatic-update-recovery' ) );
			return;
		}

		/*
		 * Persist BEFORE updating: if PHP dies or times out mid-update, the
		 * attempt is already recorded and will not be repeated.
		 */
		$record['attempted']       = true;
		$record['last_status']     = 'attempting';
		$record['last_attempt_at'] = time();
		$state[ $plugin_file ]     = $record;
		self::save_state( $state );

		self::add_log(
			'attempt',
			$plugin_file,
			/* translators: %s: target version. */
			sprintf( __( 'Starting recovery attempt for version %s.', 'automatic-update-recovery' ), $record['target'] )
		);

		try {
			$result = $updater->update( 'plugin', $item );
		} catch ( Throwable $error ) {
			$result = new WP_Error( 'aur_exception', $error->getMessage() );
		} finally {
			WP_Upgrader::release_lock( 'auto_updater' );
		}

		// Clear plugin metadata and update caches before checking the version.
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		$plugins_after = get_plugins();
		$after_version = isset( $plugins_after[ $plugin_file ]['Version'] ) ? (string) $plugins_after[ $plugin_file ]['Version'] : '';

		if ( '' !== $after_version && version_compare( $after_version, $record['target'], '>=' ) ) {
			/* translators: %s: installed version. */
			self::add_log( 'success', $plugin_file, sprintf( __( 'Recovery successful. Installed version: %s.', 'automatic-update-recovery' ), $after_version ) );
			unset( $state[ $plugin_file ] );
		} else {
			$message                  = self::result_message( $result );
			$record['last_status']    = 'failed';
			$record['last_message']   = $message;
			$state[ $plugin_file ]    = $record;
			self::add_log( 'failed', $plugin_file, $message );
		}

		self::save_state( $state );
	}

	/**
	 * Fresh tracking record.
	 */
	private static function new_record( $target_version ) {
		return array(
			'target'          => (string) $target_version,
			'checks'          => 0,
			'last_check'      => '',
			'first_seen'      => time(),
			'attempted'       => false,
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

	/**
	 * Load the native upgrader classes (same files wp_maybe_auto_update() loads).
	 */
	private static function load_upgrader_files() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
	}

	/**
	 * Convert the native updater result to a concise log message.
	 */
	private static function result_message( $result ) {
		if ( is_wp_error( $result ) ) {
			$message = implode( ' | ', $result->get_error_messages() );
			return '' !== $message ? $message : __( 'The updater returned an unspecified error.', 'automatic-update-recovery' );
		}

		if ( false === $result ) {
			return __( 'WordPress declined the recovery update. Check the automatic-update settings and eligibility.', 'automatic-update-recovery' );
		}

		if ( null === $result ) {
			return __( 'The updater did not return a result. Check the WordPress update logs.', 'automatic-update-recovery' );
		}

		return __( 'The updater finished, but the installed version did not change. Check the update logs.', 'automatic-update-recovery' );
	}

	/**
	 * Get excluded plugin basenames.
	 */
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

	/**
	 * Append to a bounded diagnostic log.
	 */
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

	/**
	 * "Settings" link on the Plugins screen.
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'tools.php?page=automatic-update-recovery' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'automatic-update-recovery' ) . '</a>' );
		return $links;
	}

	/**
	 * Add the settings page under Tools.
	 */
	public static function admin_menu() {
		self::$page_hook = (string) add_management_page(
			__( 'Automatic Update Recovery', 'automatic-update-recovery' ),
			__( 'Update Recovery', 'automatic-update-recovery' ),
			self::CAPABILITY,
			'automatic-update-recovery',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Tiny inline script for the plugin filter box (settings page only).
	 */
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

	/**
	 * Render settings and recent activity.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$enabled    = (bool) get_option( self::ENABLED_OPTION, 1 );
		$exclusions = self::get_exclusions();
		$state      = self::get_state();
		$log        = get_option( self::LOG_OPTION, array() );
		$log        = is_array( $log ) ? array_reverse( $log ) : array();
		$next       = wp_next_scheduled( self::CRON_HOOK );

		$installed = get_plugins();

		self::load_upgrader_files();
		$updater = new WP_Automatic_Updater();
		$offers  = get_site_transient( 'update_plugins' );
		$pending = self::pending_updates( $offers, $installed );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$just_saved = isset( $_GET['updated'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Automatic Update Recovery', 'automatic-update-recovery' ); ?></h1>

			<?php if ( $just_saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'automatic-update-recovery' ); ?></p></div>
			<?php endif; ?>

			<?php $blocked = self::global_block_reason( $updater ); ?>
			<?php if ( '' !== $blocked ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $blocked ); ?></p></div>
			<?php endif; ?>

			<?php self::render_pending_summary( $updater, $offers, $pending, $exclusions ); ?>

			<p><?php esc_html_e( 'Checks every six hours for plugin updates that remain pending across two distinct checks. Recovery uses the native WordPress automatic updater and respects existing automatic-update preferences and exclusions.', 'automatic-update-recovery' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aur_save_settings">
				<?php wp_nonce_field( 'aur_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Recovery system', 'automatic-update-recovery' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>>
								<?php esc_html_e( 'Enable recovery attempts', 'automatic-update-recovery' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Excluded plugins', 'automatic-update-recovery' ); ?></h2>
				<?php self::render_exclusion_table( $installed, $exclusions, $updater, $offers, $pending ); ?>

				<?php submit_button( __( 'Save settings', 'automatic-update-recovery' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Schedule', 'automatic-update-recovery' ); ?></h2>
			<p>
				<?php
				if ( $next ) {
					printf(
						/* translators: %s: next scheduled run. */
						esc_html__( 'Next scheduled check: %s', 'automatic-update-recovery' ),
						esc_html( wp_date( 'Y-m-d H:i:s', $next ) )
					);
				} else {
					esc_html_e( 'The event is not currently scheduled. It will be scheduled automatically on the next WordPress initialization.', 'automatic-update-recovery' );
				}
				?>
			</p>
			<p><?php esc_html_e( 'Recovery threshold: two distinct update checks, and at least 12 hours pending. At most one recovery attempt is made for each plugin and target version. A failed attempt is logged and is not retried automatically for that same version.', 'automatic-update-recovery' ); ?></p>

			<?php if ( ! empty( $state ) ) : ?>
				<h2><?php esc_html_e( 'Updates currently tracked', 'automatic-update-recovery' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'automatic-update-recovery' ); ?></th>
							<th><?php esc_html_e( 'Target version', 'automatic-update-recovery' ); ?></th>
							<th><?php esc_html_e( 'Checks', 'automatic-update-recovery' ); ?></th>
							<th><?php esc_html_e( 'Status', 'automatic-update-recovery' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $state as $tracked_file => $record ) : ?>
							<?php if ( ! is_array( $record ) ) { continue; } ?>
							<tr>
								<td><code><?php echo esc_html( $tracked_file ); ?></code></td>
								<td><?php echo esc_html( $record['target'] ?? '' ); ?></td>
								<td><?php echo esc_html( (string) ( $record['checks'] ?? 0 ) . ' / ' . self::REQUIRED_CHECKS ); ?></td>
								<td><?php echo esc_html( strtoupper( (string) ( $record['last_status'] ?? '' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent activity', 'automatic-update-recovery' ); ?></h2>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'No activity recorded yet.', 'automatic-update-recovery' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'automatic-update-recovery' ); ?></th>
							<th><?php esc_html_e( 'Status', 'automatic-update-recovery' ); ?></th>
							<th><?php esc_html_e( 'Plugin', 'automatic-update-recovery' ); ?></th>
							<th><?php esc_html_e( 'Details', 'automatic-update-recovery' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $log, 0, 30 ) as $entry ) : ?>
							<?php
							$time = $entry['time'] ?? '';
							// 1.0.0 stored a local-time string, 1.1.0+ stores a timestamp.
							$time = is_numeric( $time ) ? wp_date( 'Y-m-d H:i:s', (int) $time ) : (string) $time;
							?>
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

	/**
	 * Checklist of installed plugins to exclude from recovery updates.
	 */
	private static function render_exclusion_table( array $installed, array $exclusions, $updater, $offers, array $pending ) {
		$self    = plugin_basename( __FILE__ );
		$missing = array_diff( $exclusions, array_keys( $installed ) );
		?>
		<p class="description"><?php esc_html_e( 'Tick the plugins that must never be updated by the recovery system. Eligibility is decided by WordPress itself, including auto-update settings made by other plugins such as Easy Updates Manager: this list is an extra safety net.', 'automatic-update-recovery' ); ?></p>
		<p>
			<label class="screen-reader-text" for="aur-filter"><?php esc_html_e( 'Filter plugins', 'automatic-update-recovery' ); ?></label>
			<input type="search" id="aur-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter plugins…', 'automatic-update-recovery' ); ?>" autocomplete="off">
			&nbsp;
			<label><input type="checkbox" id="aur-only-updates"> <?php esc_html_e( 'Show only plugins with updates', 'automatic-update-recovery' ); ?></label>
		</p>
		<table class="widefat striped" id="aur-plugins">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<label class="screen-reader-text" for="aur-check-all"><?php esc_html_e( 'Select all visible plugins', 'automatic-update-recovery' ); ?></label>
						<input type="checkbox" id="aur-check-all">
					</td>
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
								<label class="screen-reader-text" for="<?php echo esc_attr( $box_id ); ?>">
									<?php
									/* translators: %s: plugin name. */
									echo esc_html( sprintf( __( 'Exclude %s', 'automatic-update-recovery' ), $name ) );
									?>
								</label>
								<input type="checkbox" id="<?php echo esc_attr( $box_id ); ?>" name="excluded[]" value="<?php echo esc_attr( $plugin_file ); ?>" <?php checked( in_array( $plugin_file, $exclusions, true ) ); ?>>
							<?php endif; ?>
						</th>
						<td>
							<strong><?php echo esc_html( $name ); ?></strong><br>
							<code><?php echo esc_html( $plugin_file ); ?></code>
							<?php if ( $is_self ) : ?>
								<br><em><?php esc_html_e( 'Always excluded: this plugin never updates itself.', 'automatic-update-recovery' ); ?></em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $data['Version'] ?? '' ); ?></td>
						<td>
							<?php if ( isset( $pending[ $plugin_file ] ) ) : ?>
								<strong><?php echo esc_html( $pending[ $plugin_file ] ); ?></strong>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php echo $active ? esc_html__( 'Active', 'automatic-update-recovery' ) : esc_html__( 'Inactive', 'automatic-update-recovery' ); ?></td>
						<td><?php echo self::is_eligible( $updater, $plugin_file, $offers ) ? esc_html__( 'Yes', 'automatic-update-recovery' ) : esc_html__( 'No', 'automatic-update-recovery' ); ?></td>
					</tr>
				<?php endforeach; ?>

				<?php foreach ( $missing as $plugin_file ) : ?>
					<?php $box_id = 'aur-ex-' . md5( $plugin_file ); ?>
					<tr data-update="0" data-search="<?php echo esc_attr( $plugin_file ); ?>">
						<th scope="row" class="check-column">
							<label class="screen-reader-text" for="<?php echo esc_attr( $box_id ); ?>">
								<?php
								/* translators: %s: plugin basename. */
								echo esc_html( sprintf( __( 'Exclude %s', 'automatic-update-recovery' ), $plugin_file ) );
								?>
							</label>
							<input type="checkbox" id="<?php echo esc_attr( $box_id ); ?>" name="excluded[]" value="<?php echo esc_attr( $plugin_file ); ?>" checked>
						</th>
						<td><code><?php echo esc_html( $plugin_file ); ?></code></td>
						<td>&mdash;</td>
						<td>&mdash;</td>
						<td colspan="2"><em><?php esc_html_e( 'Not installed (exclusion kept; untick to remove it)', 'automatic-update-recovery' ); ?></em></td>
					</tr>
				<?php endforeach; ?>

				<?php if ( empty( $installed ) && empty( $missing ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No plugins found.', 'automatic-update-recovery' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Installed plugins that have a newer version on offer, as file => version.
	 * Reads the cached update_plugins transient; it does not trigger a check.
	 */
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

	/**
	 * Summary box: how many plugins have an update and how each will be handled.
	 */
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
			<p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of plugins. */
							_n( '%d plugin has an update available.', '%d plugins have updates available.', $total, 'automatic-update-recovery' ),
							$total
						)
					);
					?>
				</strong>
				<?php if ( $total > 0 ) : ?>
					<br>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: eligible for automatic update, 2: excluded by the user, 3: needing a manual update. */
							__( 'Eligible for automatic update: %1$d · Excluded here: %2$d · Not eligible (manual update needed): %3$d', 'automatic-update-recovery' ),
							$count['auto'],
							$count['excluded'],
							$count['manual']
						)
					);
					?>
				<?php endif; ?>
				<br>
				<em>
					<?php
					if ( $last > 0 ) {
						echo esc_html(
							sprintf(
								/* translators: %s: date and time of the last update check. */
								__( 'Last WordPress update check: %s. Figures come from that check and can be up to 12 hours old.', 'automatic-update-recovery' ),
								wp_date( 'Y-m-d H:i', $last )
							)
						);
					} else {
						esc_html_e( 'No update check has been recorded yet.', 'automatic-update-recovery' );
					}
					?>
				</em>
			</p>
		</div>
		<?php
	}

	/**
	 * Would WordPress auto-update this plugin if a new version appeared?
	 *
	 * Uses WP_Automatic_Updater::should_update(), i.e. the same decision the
	 * recovery run takes, so the `auto_update_plugin` filter (Easy Updates
	 * Manager and similar), `automatic_updater_disabled`, file-mod and VCS
	 * rules are all taken into account.
	 */
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
			$dir          = dirname( $plugin_file );
			$item->slug   = '.' !== $dir ? $dir : basename( $plugin_file, '.php' );
		}
		$item->plugin = $plugin_file;

		return (bool) $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR );
	}

	/**
	 * Reason why no automatic update can happen site-wide, or ''.
	 */
	private static function global_block_reason( $updater ) {
		if ( $updater->is_disabled() ) {
			return __( 'Automatic updates are disabled on this site (AUTOMATIC_UPDATER_DISABLED or the automatic_updater_disabled filter). The recovery system will not update anything.', 'automatic-update-recovery' );
		}
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			return __( 'File modifications are not allowed on this site (for example DISALLOW_FILE_MODS). The recovery system will not update anything.', 'automatic-update-recovery' );
		}
		return '';
	}

	/**
	 * Keep only submitted basenames that are installed plugins or were
	 * already excluded (so an exclusion survives a temporary removal).
	 */
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

	/**
	 * Save settings.
	 */
	public static function save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'automatic-update-recovery' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'aur_save_settings' );

		update_option( self::ENABLED_OPTION, isset( $_POST['enabled'] ) ? 1 : 0, false );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated against the installed plugin list below.
		$submitted = isset( $_POST['excluded'] ) && is_array( $_POST['excluded'] ) ? wp_unslash( $_POST['excluded'] ) : array();

		update_option(
			self::EXCLUDE_OPTION,
			self::sanitize_exclusions( $submitted, array_keys( get_plugins() ), self::get_exclusions() ),
			false
		);

		$args = array(
			'page'    => 'automatic-update-recovery',
			'updated' => '1',
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
		exit;
	}
}

Automatic_Update_Recovery::init();

register_activation_hook( __FILE__, array( 'Automatic_Update_Recovery', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Automatic_Update_Recovery', 'deactivate' ) );

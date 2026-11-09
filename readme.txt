=== Automatic Update Recovery ===
Contributors: peopleinside
Requires at least: 6.5
Tested up to: 7.1.4
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Attempts controlled recovery updates when eligible plugin updates remain pending, with email alerts, cron diagnostics and GitHub self-update.

== Description ==

Every six hours the plugin runs a WordPress update check and tracks plugin updates that remain pending. When an update that WordPress itself considers eligible for automatic updating is still pending after two distinct checks (and at least 12 hours), the plugin asks the native WordPress updater to install it.

Safety rules:

* Uses `WP_Automatic_Updater::should_update()` and `WP_Automatic_Updater::update()`.
* Respects the `auto_update_plugin` filter, WordPress auto-update preferences and Easy Updates Manager decisions.
* Extra exclusions are chosen from a checklist of your installed plugins (searchable), no manual typing.
* Counts only distinct `update_plugins.last_checked` timestamps.
* The number of recovery attempts per plugin and target version is limited and configurable (default 3). The attempt is recorded before the update starts, so a fatal error cannot cause an endless loop.
* Shares the native `auto_updater` lock, so it never updates concurrently with WordPress.
* Never updates itself through the recovery system, and never touches themes, core or translations.
* Does not force FTP credentials or change filesystem permissions.

Notifications and diagnostics:

* Email alert when a recovery attempt fails (enabled by default).
* Optional email alert when the plugin successfully updates something (disabled by default).
* Cron health box: whether WP-Cron is disabled in `wp-config.php`, last execution, next scheduled run.
* Bounded diagnostic log, readable only by administrators, with a one-click clear button.

Updates for this plugin itself are delivered from the GitHub Releases of the official repository, through the native WordPress updater (same "Update available" screen, same "Update now" button, compatible with auto-updates if enabled for this plugin).

On multisite the plugin runs on the main site only and the settings page requires the `update_plugins` capability.

== Installation ==

1. Upload the plugin and activate it.
2. Open Tools > Update Recovery.
3. Check that recovery is enabled, set the maximum number of attempts and tick any plugins you want to exclude.

The plugin uses WP-Cron. To force a run for testing: `wp cron event run aur_recovery_check`.

== Frequently Asked Questions ==

= The cron health box says WP-Cron is disabled. Is that a problem? =

Not necessarily. `DISABLE_WP_CRON` is often set on purpose when a real server cron job calls `wp-cron.php`. If no server cron is configured, neither WordPress auto-updates nor this plugin will run.

= Why did an update fail and stop being retried? =

Each plugin/target version has a maximum number of attempts (default 3). Once the limit is reached the failure is logged and no further attempt is made for that same version. A new target version resets the counter.

= Does the plugin update itself? =

Not through the recovery system: it always excludes itself from recovery. Its own updates come from GitHub Releases and are installed by the native WordPress updater.

== Filters ==

`aur_min_pending_seconds` (default 43200): minimum time an update must have been pending before a recovery attempt.

== Disclaimer ==

This software is provided "AS IS", without any warranty. While it has been tested and reasonable efforts are made to ensure security and reliability, no guarantees are provided. As an open project, anyone may contribute or report issues, but this does not imply endorsement or liability from the maintainers.

You use this software entirely at your own risk. The authors and contributors are not liable for any damages, data loss, or unexpected behavior resulting from its use, modification, or distribution. Always review and test the code independently before deploying it in critical or production environments.

== Changelog ==

= 1.4.0 =
* Add: the number of recovery attempts per plugin and target version is now configurable in the settings (default 3). Previously a single attempt was made and a failure was never retried.
* Add: email notification when a recovery attempt fails (enabled by default).
* Add: optional email notification when the plugin successfully updates something (disabled by default).
* Add: cron health box on the settings page, showing whether WP-Cron is disabled in wp-config.php, the last execution and the next scheduled run.
* Add: "Clear log" button for the diagnostic log (administrators only, nonce protected).
* Add: self-update from the GitHub Releases of the official repository, using the native WordPress updater and the native auto-update mechanism.
* Add: the GitHub release workflow now builds a ZIP named with the version number and containing the correct plugin folder.
* Change: GitHub release cache reduced from 12 to 2 hours, so new releases are detected sooner.
* Fix: the GitHub package host allowlist now includes the release CDN host, which could silently block the download of the update package.
* Fix: more robust renaming of the downloaded package folder, so the plugin cannot end up installed in a directory with a random suffix.
* Add: Plugin URI and Author URI now point to the GitHub repository.
* Tested up to WordPress 7.1.4.

= 1.3.0 =
* Add: the settings page now shows how many plugins have an update available, split into eligible for automatic update / excluded here / not eligible (manual update needed), plus the time of the last WordPress update check.
* Add: "Update available" column with the target version for each plugin, and a "Show only plugins with updates" filter.
* The figures are read from WordPress' cached update data; no extra update check is triggered by opening the page.

= 1.2.1 =
* Fix: the "auto-updates" column wrongly read only WordPress' own per-plugin list, so it showed "Disabled" on sites where auto-updates are controlled by a filter or by Easy Updates Manager. It now shows "Auto-update eligible", computed with the same WP_Automatic_Updater::should_update() check the recovery run uses.
* Add: warning on the settings page when automatic updates are disabled site-wide (AUTOMATIC_UPDATER_DISABLED, automatic_updater_disabled filter or DISALLOW_FILE_MODS).

= 1.2.0 =
* Add: exclusions are now a graphical checklist of installed plugins (with name, version, active status, WordPress auto-update status and a filter box) instead of a text area.
* Security: submitted exclusions are validated against installed plugins or previously saved exclusions, so forged values are discarded.
* Existing exclusions are preserved; an exclusion for a plugin that is currently not installed is kept and shown so it can be removed.

= 1.1.0 =
* Fix: attempt state is saved before the update starts, so a fatal error/timeout can no longer cause repeated attempts.
* Fix: uses the native `auto_updater` lock and an atomic lock for its own runs (the old transient lock was racy).
* Fix: a recovery skipped because the native updater was busy is retried on the next run instead of waiting for a new update check.
* Fix: `plugin` property is guaranteed on the update item (third-party updaters often omit it).
* Fix: single-file plugins (e.g. `hello.php`) can now be excluded.
* Fix: settings page shows "Settings saved".
* Add: minimum 12 hours pending before recovery; plugin excludes itself.
* Security/hardening: capability is now `update_plugins`; log messages are length-bounded; 403 on forbidden save.
* Add: timestamps stored as UTC timestamps and rendered in the site timezone; tracked-updates table; Settings link.
* Add: `uninstall.php` removes all options, lock and cron event.
* Fix: schedule label no longer translated before `init` (WordPress 6.7+ notice); multisite schedules only on main site.

= 1.0.0 =
* Initial release.

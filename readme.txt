=== Automatic Update Recovery ===
Contributors: peopleinside
Requires at least: 6.5
Tested up to: 7.1.4
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Disclaimer ==

This software is provided "AS IS", without any warranty. While it has been tested and reasonable efforts are made to ensure security and reliability, no guarantees are provided. As an open project, anyone may contribute or report issues, but this does not imply endorsement or liability from the maintainers.

You use this software entirely at your own risk. The authors and contributors are not liable for any damages, data loss, or unexpected behavior resulting from its use, modification, or distribution. Always review and test the code independently before deploying it in critical or production environments.

Attempts controlled recovery updates when eligible plugin updates remain pending, featuring GitHub self-update, email alerts, and cron diagnostics.

== Description ==

Every six hours the plugin runs a WordPress update check and tracks plugin updates that remain pending. When an update that WordPress itself considers eligible for automatic updating is still pending after two distinct checks (and at least 12 hours), the plugin asks the native WordPress updater to install it once.

Safety rules:

* Uses `WP_Automatic_Updater::should_update()` and `WP_Automatic_Updater::update()`.
* Respects the `auto_update_plugin` filter, WordPress auto-update preferences and Easy Updates Manager decisions.
* Extra exclusions are chosen from a checklist of your installed plugins (searchable), no manual typing.
* Counts only distinct `update_plugins.last_checked` timestamps.
* Makes at most one recovery attempt per plugin and target version, even if PHP dies mid-update.
* Shares the native `auto_updater` lock, so it never updates concurrently with WordPress.
* Never updates itself, themes, core or translations.
* Does not force FTP credentials or change filesystem permissions.

On multisite the plugin runs on the main site only and the settings page requires the `update_plugins` capability.

== Installation ==

1. Upload the plugin and activate it.
2. Open Tools > Update Recovery.
3. Check that recovery is enabled and tick any plugins you want to exclude.

The plugin uses WP-Cron. To force a run for testing: `wp cron event run aur_recovery_check`.

== Filters ==

`aur_min_pending_seconds` (default 43200): minimum time an update must have been pending before a recovery attempt.

== Changelog ==

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

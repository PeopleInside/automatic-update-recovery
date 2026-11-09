# Automatic Update Recovery

[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-Tested%20up%20to%207.1.4-21759b.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-8892bf.svg)](http://php.net/)
[![GitHub Stars](https://img.shields.io/github/stars/PeopleInside/automatic-update-recovery.svg?style=social&label=Star)](https://github.com/PeopleInside/automatic-update-recovery/stargazers)

A lightweight WordPress plugin that ensures automatic updates are applied correctly, even if previous attempts failed due to timeouts or filesystem errors. It features a robust GitHub self-updater, customizable retry logic, email alerts, and built-in cron diagnostics.

## Features

- **Controlled Recovery Logic**: Attempts recovery only when an update remains pending across two distinct WordPress update checks (minimum 12 hours apart).
- **Customizable Retry Attempts**: Configure the maximum number of retry attempts per failed version (default: 3).
- **Email Notifications**: Get alerted via email when an update fails (enabled by default) or succeeds (disabled by default).
- **Cron Health Dashboard**: Visual indicators showing if `DISABLE_WP_CRON` is active, the timestamp of the last successful execution, and the next scheduled run.
- **Secure Diagnostic Logging**: Detailed, length-bounded logs accessible exclusively to administrators, with a one-click clear function.
- **Robust GitHub Self-Updater**: Built-in updater that fetches releases directly from GitHub, featuring optimized caching (2 hours), trusted CDN validation, and safe folder renaming.
- **Safe Exclusions**: Graphical checklist to exclude specific plugins from the recovery system, respecting native WordPress auto-update filters and third-party managers (e.g., Easy Updates Manager).

## Disclaimer
This software is provided **"AS IS"**, without any warranty. While it has been tested and reasonable efforts are made to ensure security and reliability, no guarantees are provided. As an open project, anyone may contribute or report issues, but this does not imply endorsement or liability from the maintainers.

**You use this software entirely at your own risk.** The authors and contributors are not liable for any damages, data loss, or unexpected behavior resulting from its use, modification, or distribution. Always review and test the code independently before deploying it in critical or production environments.

## Installation

1. Download the latest release `.zip` file from the [Releases page](https://github.com/PeopleInside/automatic-update-recovery/releases).
2. In your WordPress admin panel, go to **Plugins > Add New > Upload Plugin**.
3. Choose the downloaded `.zip` file and click **Install Now**.
4. Activate the plugin.

## Usage & Configuration

Once activated, navigate to **Tools > Update Recovery** in your WordPress dashboard.

1. **Enable/Disable**: Toggle the main recovery system on or off.
2. **Max Attempts**: Set how many times the plugin should retry a failed update for the same version.
3. **Email Alerts**: Configure whether you want to receive notifications for failed or successful recovery attempts.
4. **Exclusions**: Use the searchable table to check the boxes next to plugins that should never be touched by the recovery system.

## Cron Diagnostics

The settings page includes a dedicated "Cron Health" panel. If you see a warning that `WP-Cron is disabled`, ensure your server has a real system cron job configured (e.g., `wget -q -O - https://yourdomain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1`), otherwise the background recovery checks will not execute.

## Security & Privacy

- All diagnostic logs are sanitized, length-bounded, and strictly limited to users with the `update_plugins` capability.
- The plugin never updates itself, themes, WordPress core, or translations.
- Exclusion lists are strictly validated against installed plugins to prevent forged submissions.

## Contributing

Contributions, bug reports, and feature requests are welcome. Please open an issue or submit a pull request on the [GitHub repository](https://github.com/PeopleInside/automatic-update-recovery).

## License

This project is licensed under the GNU General Public License v2.0 or later. See the [LICENSE](LICENSE) file for details.

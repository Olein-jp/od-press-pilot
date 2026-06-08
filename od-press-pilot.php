<?php
/**
 * Plugin Name: OD Press Pilot
 * Description: Development scaffold for the OD Press Pilot WordPress plugin.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: OD Press Pilot
 * Text Domain: od-press-pilot
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('OD_PRESS_PILOT_VERSION', '0.1.0');
define('OD_PRESS_PILOT_PLUGIN_FILE', __FILE__);
define('OD_PRESS_PILOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OD_PRESS_PILOT_PLUGIN_URL', plugin_dir_url(__FILE__));

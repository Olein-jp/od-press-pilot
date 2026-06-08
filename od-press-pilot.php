<?php
/**
 * Plugin Name: OD Press Pilot
 * Description: AI notice writing assistant powered by the WordPress AI Client.
 * Version: 0.1.0
 * Requires at least: 7.0
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
define('OD_PRESS_PILOT_REST_NAMESPACE', 'od-press-pilot/v1');

require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Settings/ProfileSettings.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/AI/PromptBuilder.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/AI/ResponseParser.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/AI/Client.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Draft/ContentBlockConverter.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Draft/DraftCreator.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Rest/Controller.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Admin/Admin.php';

add_action('plugins_loaded', ['ODPressPilot\Admin\Admin', 'init']);
add_action('rest_api_init', ['ODPressPilot\Rest\Controller', 'register_routes']);

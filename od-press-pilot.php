<?php
/**
 * Plugin Name: Press Pilot
 * Description: AI notice writing assistant powered by the WordPress AI Client.
 * Version: 0.1.8
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Tested up to: 7.0
 * Author: Koji Kuno
 * Author URI: https://olein-design.com/
 * Text Domain: od-press-pilot
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('OD_PRESS_PILOT_VERSION', '0.1.8');
define('OD_PRESS_PILOT_PLUGIN_FILE', __FILE__);
define('OD_PRESS_PILOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OD_PRESS_PILOT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OD_PRESS_PILOT_REST_NAMESPACE', 'od-press-pilot/v1');
define('OD_PRESS_PILOT_GITHUB_USER', 'Olein-jp');
define('OD_PRESS_PILOT_GITHUB_REPOSITORY', 'od-press-pilot');

$od_press_pilot_autoload = OD_PRESS_PILOT_PLUGIN_DIR . 'vendor/autoload.php';

if (file_exists($od_press_pilot_autoload)) {
	require_once $od_press_pilot_autoload;
}

require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Settings/ProfileSettings.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Settings/TemplateSettings.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Generation/FieldRegistry.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/AI/PromptBuilder.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/AI/ResponseParser.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/AI/UsageStats.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/AI/Client.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Draft/ContentBlockConverter.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Draft/DraftCreator.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Rest/Controller.php';
require_once OD_PRESS_PILOT_PLUGIN_DIR . 'src/Admin/Admin.php';

if (class_exists('Inc2734\WP_GitHub_Plugin_Updater\Bootstrap')) {
	new Inc2734\WP_GitHub_Plugin_Updater\Bootstrap(
		plugin_basename(__FILE__),
		OD_PRESS_PILOT_GITHUB_USER,
		OD_PRESS_PILOT_GITHUB_REPOSITORY,
		[
			'homepage'     => 'https://github.com/Olein-jp/od-press-pilot',
			'tested'       => '7.0',
			'requires'     => '7.0',
			'requires_php' => '7.4',
		]
	);
}

add_action('plugins_loaded', ['ODPressPilot\Admin\Admin', 'init']);
add_action('rest_api_init', ['ODPressPilot\Rest\Controller', 'register_routes']);

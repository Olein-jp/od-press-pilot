<?php
/**
 * Admin screens and assets.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\Admin;

if (! defined('ABSPATH')) {
	exit;
}

final class Admin {
	private const MENU_SLUG      = 'od-press-pilot';
	private const PROFILE_SLUG   = 'od-press-pilot-profile';
	private const TEMPLATES_SLUG = 'od-press-pilot-templates';

	public static function init(): void {
		add_action('admin_menu', [self::class, 'register_menu']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
	}

	public static function register_menu(): void {
		add_menu_page(
			__('Press Pilot', 'od-press-pilot'),
			__('Press Pilot', 'od-press-pilot'),
			'edit_posts',
			self::MENU_SLUG,
			[self::class, 'render_page'],
			'dashicons-megaphone',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('コンテンツ生成', 'od-press-pilot'),
			__('コンテンツ生成', 'od-press-pilot'),
			'edit_posts',
			self::MENU_SLUG,
			[self::class, 'render_page']
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('広報プロフィール', 'od-press-pilot'),
			__('広報プロフィール', 'od-press-pilot'),
			'manage_options',
			self::PROFILE_SLUG,
			[self::class, 'render_page']
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('テンプレート', 'od-press-pilot'),
			__('テンプレート', 'od-press-pilot'),
			'edit_posts',
			self::TEMPLATES_SLUG,
			[self::class, 'render_page']
		);
	}

	public static function enqueue_assets(string $hook_suffix): void {
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

		if (! in_array($page, [self::MENU_SLUG, self::PROFILE_SLUG, self::TEMPLATES_SLUG], true)) {
			return;
		}

		$asset_file = OD_PRESS_PILOT_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = file_exists($asset_file) ? require $asset_file : ['dependencies' => [], 'version' => OD_PRESS_PILOT_VERSION];

		wp_enqueue_script(
			'od-press-pilot-admin',
			OD_PRESS_PILOT_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'od-press-pilot-admin',
			OD_PRESS_PILOT_PLUGIN_URL . 'build/style-index.css',
			['wp-components'],
			$asset['version']
		);

		wp_set_script_translations('od-press-pilot-admin', 'od-press-pilot', OD_PRESS_PILOT_PLUGIN_DIR . 'languages');

		wp_localize_script(
			'od-press-pilot-admin',
			'odPressPilot',
			[
				'restNamespace' => OD_PRESS_PILOT_REST_NAMESPACE,
				'page'          => self::page_key($page),
			]
		);
	}

	public static function render_page(): void {
		echo '<div class="wrap"><div id="od-press-pilot-admin"></div></div>';
	}

	private static function page_key(string $page): string {
		if (self::PROFILE_SLUG === $page) {
			return 'profile';
		}

		if (self::TEMPLATES_SLUG === $page) {
			return 'templates';
		}

		return 'generate';
	}
}

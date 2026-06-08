<?php
/**
 * REST API controller.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\Rest;

use ODPressPilot\AI\Client;
use ODPressPilot\Draft\DraftCreator;
use ODPressPilot\Settings\ProfileSettings;
use WP_REST_Request;
use WP_REST_Server;

if (! defined('ABSPATH')) {
	exit;
}

final class Controller {
	public static function register_routes(): void {
		register_rest_route(
			OD_PRESS_PILOT_REST_NAMESPACE,
			'/profile',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [self::class, 'get_profile'],
					'permission_callback' => static fn (): bool => current_user_can('manage_options'),
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [self::class, 'save_profile'],
					'permission_callback' => static fn (): bool => current_user_can('manage_options'),
				],
			]
		);

		register_rest_route(
			OD_PRESS_PILOT_REST_NAMESPACE,
			'/providers',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [self::class, 'get_providers'],
				'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
			]
		);

		register_rest_route(
			OD_PRESS_PILOT_REST_NAMESPACE,
			'/generate',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [self::class, 'generate'],
				'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
			]
		);

		register_rest_route(
			OD_PRESS_PILOT_REST_NAMESPACE,
			'/draft',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [self::class, 'create_draft'],
				'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
			]
		);
	}

	public static function get_profile(): array {
		return ProfileSettings::get();
	}

	public static function save_profile(WP_REST_Request $request): array {
		$params = self::get_request_params($request);

		return ProfileSettings::save($params);
	}

	public static function get_providers(): array {
		return [
			'available' => Client::is_available(),
			'providers' => Client::get_providers(),
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function generate(WP_REST_Request $request) {
		$params = self::sanitize_generation_request(self::get_request_params($request));

		if ('' === $params['post_content']) {
			return new \WP_Error('od_press_pilot_post_content_required', __('投稿内容を入力してください。', 'od-press-pilot'), ['status' => 400]);
		}

		return Client::generate($params);
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_draft(WP_REST_Request $request) {
		return DraftCreator::create(self::get_request_params($request));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_request_params(WP_REST_Request $request): array {
		$json_params = $request->get_json_params();

		if (is_array($json_params) && [] !== $json_params) {
			return $json_params;
		}

		return (array) $request->get_params();
	}

	/**
	 * @param array<string, mixed> $params Raw params.
	 * @return array<string, mixed>
	 */
	private static function sanitize_generation_request(array $params): array {
		$translation_language = sanitize_key((string) ($params['translation_language'] ?? 'none'));
		$allowed_languages    = ['none', 'en', 'zh-hans', 'zh-hant', 'ko', 'ja', 'custom'];

		return [
			'post_content'                => sanitize_textarea_field((string) ($params['post_content'] ?? '')),
			'audience'                    => sanitize_text_field((string) ($params['audience'] ?? '')),
			'desired_length'              => absint($params['desired_length'] ?? 0),
			'translation_language'        => in_array($translation_language, $allowed_languages, true) ? $translation_language : 'none',
			'custom_translation_language' => sanitize_text_field((string) ($params['custom_translation_language'] ?? '')),
			'use_emoji'                   => ! empty($params['use_emoji']),
			'generate_hashtags'           => ! empty($params['generate_hashtags']),
			'provider'                    => sanitize_key((string) ($params['provider'] ?? '')),
		];
	}
}

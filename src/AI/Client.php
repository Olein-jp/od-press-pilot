<?php
/**
 * WordPress AI Client adapter.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\AI;

use ODPressPilot\Settings\ProfileSettings;
use WP_Error;

if (! defined('ABSPATH')) {
	exit;
}

final class Client {
	/**
	 * Generate content through WordPress AI Client.
	 *
	 * @param array<string, mixed> $request Request data.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function generate(array $request) {
		if (empty($request['provider'])) {
			return new WP_Error('od_press_pilot_provider_missing', __('AI Provider が選択されていません。', 'od-press-pilot'), ['status' => 400]);
		}

		if (! function_exists('wp_ai_client_prompt')) {
			return new WP_Error('od_press_pilot_ai_unavailable', __('AI Provider が利用できません。', 'od-press-pilot'), ['status' => 503]);
		}

		$profile = ProfileSettings::get();
		$prompt  = PromptBuilder::build($profile, $request);
		$builder = wp_ai_client_prompt($prompt);

		if (method_exists($builder, 'as_json_response')) {
			$builder = $builder->as_json_response(PromptBuilder::schema());
		}

		if (method_exists($builder, 'is_supported_for_text_generation') && ! $builder->is_supported_for_text_generation()) {
			return new WP_Error('od_press_pilot_ai_unavailable', __('AI Provider が利用できません。', 'od-press-pilot'), ['status' => 503]);
		}

		$json = $builder->generate_text();

		if (is_wp_error($json)) {
			self::log_generation_error($json);

			$error_text = strtolower($json->get_error_code() . ' ' . $json->get_error_message());

			if (false !== strpos($error_text, 'timeout') || false !== strpos($error_text, 'timed out')) {
				return new WP_Error('od_press_pilot_timeout', __('応答がタイムアウトしました。', 'od-press-pilot'), ['status' => 504]);
			}

			return new WP_Error('od_press_pilot_generation_failed', __('コンテンツ生成に失敗しました。', 'od-press-pilot'), ['status' => 502]);
		}

		return ResponseParser::parse((string) $json);
	}

	private static function log_generation_error(WP_Error $error): void {
		if (! defined('WP_DEBUG') || ! WP_DEBUG) {
			return;
		}

		error_log(
			sprintf(
				'OD Press Pilot AI generation failed: code=%s message=%s data=%s',
				$error->get_error_code(),
				$error->get_error_message(),
				wp_json_encode($error->get_error_data(), JSON_UNESCAPED_UNICODE)
			)
		);
	}

	/**
	 * Return selectable provider choices for the UI.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_providers(): array {
		$providers = [
			[
				'id'    => 'auto',
				'label' => __('AI Client 自動選択', 'od-press-pilot'),
			],
		];

		/**
		 * Filter provider choices for integrations that expose provider IDs.
		 *
		 * The default stays provider-agnostic and lets WordPress AI Client choose
		 * a suitable configured provider/model.
		 *
		 * @param array<int, array<string, string>> $providers Provider choices.
		 */
		return apply_filters('od_press_pilot_ai_providers', $providers);
	}

	public static function is_available(): bool {
		if (! function_exists('wp_ai_client_prompt')) {
			return false;
		}

		$builder = wp_ai_client_prompt('test');

		return ! method_exists($builder, 'is_supported_for_text_generation') || $builder->is_supported_for_text_generation();
	}
}

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
	private const OPENAI_RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';
	private const GENERATION_HTTP_TIMEOUT   = 90;

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
		$provider = sanitize_key((string) ($request['provider'] ?? 'auto'));

		if ('auto' !== $provider) {
			if (! self::is_selectable_provider($provider)) {
				return new WP_Error(
					'od_press_pilot_provider_unavailable',
					__('選択中の AI Provider は利用できません。Settings > Connectors の接続状態を確認してください。', 'od-press-pilot'),
					['status' => 400]
				);
			}

			$builder = $builder->using_provider($provider);
		}

		$builder = $builder->as_json_response(PromptBuilder::schema());

		if (true !== $builder->is_supported_for_text_generation()) {
			return new WP_Error('od_press_pilot_ai_unavailable', __('AI Provider が利用できません。', 'od-press-pilot'), ['status' => 503]);
		}

		$timeout_filter = static function (array $args, string $url): array {
			if (0 === strpos($url, self::OPENAI_RESPONSES_ENDPOINT)) {
				$args['timeout'] = max((int) ($args['timeout'] ?? 0), self::GENERATION_HTTP_TIMEOUT);
			}

			return $args;
		};

		add_filter('http_request_args', $timeout_filter, 10, 2);

		try {
			$json = $builder->generate_text();
		} finally {
			remove_filter('http_request_args', $timeout_filter, 10);
		}

		if (is_wp_error($json)) {
			self::log_generation_error($json);

			return self::create_generation_error($json, $provider);
		}

		$parsed = ResponseParser::parse((string) $json);

		if (is_wp_error($parsed)) {
			return $parsed;
		}

		if (self::is_google_provider($provider)) {
			UsageStats::record_generation($provider);
		}

		return self::apply_requested_translation_labels($parsed, $request);
	}

	/**
	 * Keep translated X text fields aligned to the languages selected in the UI.
	 *
	 * @param array<string, mixed> $parsed  Parsed AI response.
	 * @param array<string, mixed> $request Request data.
	 * @return array<string, mixed>
	 */
	private static function apply_requested_translation_labels(array $parsed, array $request): array {
		$labels = self::requested_translation_labels(
			$request['translation_languages'] ?? ($request['translation_language'] ?? []),
			(string) ($request['custom_translation_language'] ?? '')
		);

		if ([] === $labels) {
			$parsed['translated_x_texts'] = [];
			return $parsed;
		}

		$translated_texts = is_array($parsed['translated_x_texts'] ?? null) ? array_values($parsed['translated_x_texts']) : [];
		$aligned_texts    = [];

		foreach ($labels as $index => $label) {
			$translated_text = $translated_texts[$index] ?? [];

			$aligned_texts[] = [
				'language' => $label,
				'text'     => is_array($translated_text) ? (string) ($translated_text['text'] ?? '') : '',
			];
		}

		$parsed['translated_x_texts'] = $aligned_texts;

		return $parsed;
	}

	/**
	 * @param mixed $languages Requested translation languages.
	 * @return string[]
	 */
	private static function requested_translation_labels($languages, string $custom_language): array {
		$labels = [
			'en'      => '英語',
			'zh-hans' => '中国語（簡体字）',
			'zh-hant' => '中国語（繁体字）',
			'ko'      => '韓国語',
			'ja'      => '日本語',
			'custom'  => '' !== $custom_language ? $custom_language : 'カスタム言語',
		];

		if (! is_array($languages)) {
			$languages = [(string) $languages];
		}

		$requested_labels = [];

		foreach ($languages as $language) {
			$language = sanitize_key((string) $language);

			if (isset($labels[$language])) {
				$requested_labels[] = $labels[$language];
			}
		}

		return array_values(array_unique($requested_labels));
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

	private static function create_generation_error(WP_Error $error, string $provider = ''): WP_Error {
		$details = self::generation_error_details($error);

		if (self::is_google_provider($provider) && self::is_rate_limit_error_code($details['code'])) {
			UsageStats::record_rate_limit_error($provider, $details['raw']);
		}

		return new WP_Error(
			$details['code'],
			$details['message'],
			[
				'status'  => $details['status'],
				'details' => $details['raw'],
			]
		);
	}

	/**
	 * @return array{code:string,message:string,status:int,raw:string}
	 */
	private static function generation_error_details(WP_Error $error): array {
		$raw        = self::error_text($error);
		$normalized = strtolower($raw);

		if (self::contains_any($normalized, ['timeout', 'timed out', 'cURL error 28'])) {
			return [
				'code'    => 'od_press_pilot_timeout',
				'message' => __('AI Provider からの応答がタイムアウトしました。しばらく待ってから再実行してください。', 'od-press-pilot') . self::format_raw_error($raw),
				'status'  => 504,
				'raw'     => $raw,
			];
		}

		if (self::contains_any($normalized, ['service unavailable', 'high demand', 'temporarily unavailable', 'overloaded', 'serverexception', 'server exception', '503'])) {
			return [
				'code'    => 'od_press_pilot_provider_temporarily_unavailable',
				'message' => __('AI Provider 側が混雑している、または一時的に利用できない状態です。数分から十数分ほど時間を空けて再実行してください。別のモデルを選べる場合は、モデルを切り替えることで改善することがあります。', 'od-press-pilot') . self::format_raw_error($raw),
				'status'  => 503,
				'raw'     => $raw,
			];
		}

		if (self::contains_any($normalized, ['insufficient_quota', 'billing', 'credit', 'credits', 'balance', 'payment', 'hard_limit'])) {
			return [
				'code'    => 'od_press_pilot_billing_required',
				'message' => __('AI Provider 側の利用枠またはクレジットが不足している可能性があります。API キーを発行済みでも、Billing の有効化、支払い方法、またはクレジット残高を確認してください。', 'od-press-pilot') . self::format_raw_error($raw),
				'status'  => 402,
				'raw'     => $raw,
			];
		}

		if (self::contains_any($normalized, ['resource_exhausted', 'resource exhausted', 'quota exceeded', 'current quota', 'rate_limit', 'rate limit', 'too many requests', 'requests per minute', 'tokens per minute', 'requests per day', '429'])) {
			return [
				'code'    => 'od_press_pilot_provider_rate_limited',
				'message' => __('AI Provider の無料枠または現在の利用上限に達した可能性があります。しばらく時間をおいて再実行してください。日次上限に達している場合は、翌日以降に再度お試しください。継続的に利用する場合は、Provider 側の利用上限や課金設定を確認してください。', 'od-press-pilot') . self::format_raw_error($raw),
				'status'  => 429,
				'raw'     => $raw,
			];
		}

		if (self::contains_any($normalized, ['invalid_api_key', 'incorrect api key', 'api key', 'unauthorized', 'authentication', '401'])) {
			return [
				'code'    => 'od_press_pilot_auth_failed',
				'message' => __('AI Provider の認証に失敗しました。Settings > Connectors で API キーが正しく保存されているか、キーが無効化されていないか確認してください。', 'od-press-pilot') . self::format_raw_error($raw),
				'status'  => 401,
				'raw'     => $raw,
			];
		}

		if (self::contains_any($normalized, ['forbidden', 'permission', 'not allowed', '403'])) {
			return [
				'code'    => 'od_press_pilot_provider_forbidden',
				'message' => __('AI Provider でこの操作が許可されていません。利用中のキー、Provider、またはモデルの権限を確認してください。', 'od-press-pilot') . self::format_raw_error($raw),
				'status'  => 403,
				'raw'     => $raw,
			];
		}

		if (self::contains_any($normalized, ['model_not_found', 'model not found', 'no models found', 'text_generation', 'unsupported model', 'not supported'])) {
			return [
				'code'    => 'od_press_pilot_model_unavailable',
				'message' => __('選択中の AI Provider またはモデルではテキスト生成を利用できません。Provider の設定を確認してください。', 'od-press-pilot') . self::format_raw_error($raw),
				'status'  => 503,
				'raw'     => $raw,
			];
		}

		return [
			'code'    => 'od_press_pilot_generation_failed',
			'message' => __('コンテンツ生成に失敗しました。AI Provider から返された詳細を確認してください。', 'od-press-pilot') . self::format_raw_error($raw),
			'status'  => 502,
			'raw'     => $raw,
		];
	}

	private static function error_text(WP_Error $error): string {
		$parts = array_filter(
			[
				$error->get_error_code(),
				$error->get_error_message(),
				self::stringify_error_data($error->get_error_data()),
			]
		);

		return self::redact_sensitive_text(implode(' ', $parts));
	}

	/**
	 * @param mixed $data Error data.
	 */
	private static function stringify_error_data($data): string {
		if (empty($data)) {
			return '';
		}

		if (is_scalar($data)) {
			return (string) $data;
		}

		$encoded = wp_json_encode($data, JSON_UNESCAPED_UNICODE);

		return is_string($encoded) ? $encoded : '';
	}

	private static function redact_sensitive_text(string $text): string {
		$text = preg_replace('/sk-[A-Za-z0-9_-]{8,}/', 'sk-***', $text) ?? $text;
		$text = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer ***', $text) ?? $text;

		return trim(wp_strip_all_tags($text));
	}

	private static function format_raw_error(string $raw): string {
		if ('' === $raw) {
			return '';
		}

		return ' ' . sprintf(
			/* translators: %s: Raw AI Provider error message. */
			__('Provider からの詳細: %s', 'od-press-pilot'),
			$raw
		);
	}

	/**
	 * @param string[] $needles Search strings.
	 */
	private static function contains_any(string $haystack, array $needles): bool {
		foreach ($needles as $needle) {
			if (false !== strpos($haystack, strtolower($needle))) {
				return true;
			}
		}

		return false;
	}

	private static function is_google_provider(string $provider): bool {
		return 'google' === sanitize_key($provider);
	}

	private static function is_rate_limit_error_code(string $code): bool {
		return in_array($code, ['od_press_pilot_provider_rate_limited'], true);
	}

	/**
	 * Return selectable provider choices for the UI.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_providers(): array {
		$providers = array_merge(
			[
				[
					'id'    => 'auto',
					'label' => __('AI Client 自動選択', 'od-press-pilot'),
				],
			],
			self::get_configured_ai_providers()
		);

		/**
		 * Filter provider choices for integrations that expose provider IDs.
		 *
		 * The default includes auto-selection and configured AI Provider connectors.
		 *
		 * @param array<int, array<string, string>> $providers Provider choices.
		 */
		return apply_filters('od_press_pilot_ai_providers', $providers);
	}

	public static function is_available(): bool {
		if (! function_exists('wp_ai_client_prompt')) {
			return false;
		}

		if ([] !== self::get_configured_ai_providers()) {
			return true;
		}

		$builder = wp_ai_client_prompt('test');

		return true === $builder->is_supported_for_text_generation();
	}

	/**
	 * Return tracked AI Provider usage stats.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_usage_stats(): array {
		return UsageStats::all();
	}

	/**
	 * @return array<int, array{id:string,label:string}>
	 */
	private static function get_configured_ai_providers(): array {
		if (! function_exists('wp_get_connectors')) {
			return [];
		}

		$connectors = wp_get_connectors();

		if (! is_array($connectors)) {
			return [];
		}

		$providers = [];

		foreach ($connectors as $id => $connector) {
			if (! is_string($id) || ! is_array($connector)) {
				continue;
			}

			if ('ai_provider' !== ($connector['type'] ?? '')) {
				continue;
			}

			if (! self::is_connector_plugin_active($connector)) {
				continue;
			}

			if (! self::is_ai_provider_configured($id, $connector)) {
				continue;
			}

			$providers[] = [
				'id'    => sanitize_key($id),
				'label' => self::provider_label($id, $connector),
			];
		}

		return $providers;
	}

	/**
	 * @param array<string, mixed> $connector Connector metadata.
	 */
	private static function is_connector_plugin_active(array $connector): bool {
		$is_active = $connector['plugin']['is_active'] ?? null;

		return ! is_callable($is_active) || (bool) $is_active();
	}

	/**
	 * @param array<string, mixed> $connector Connector metadata.
	 */
	private static function is_ai_provider_configured(string $id, array $connector): bool {
		if (class_exists('\WordPress\AiClient\AiClient')) {
			try {
				return \WordPress\AiClient\AiClient::isConfigured($id);
			} catch (\Throwable $e) {
				return false;
			}
		}

		$auth    = is_array($connector['authentication'] ?? null) ? $connector['authentication'] : [];
		$setting = (string) ($auth['setting_name'] ?? '');

		if ('' !== $setting && (bool) get_option($setting)) {
			return true;
		}

		$constant = (string) ($auth['constant_name'] ?? '');

		return '' !== $constant && defined($constant) && '' !== (string) constant($constant);
	}

	/**
	 * @param array<string, mixed> $connector Connector metadata.
	 */
	private static function provider_label(string $id, array $connector): string {
		if ('google' === $id) {
			return __('Google (Gemini)', 'od-press-pilot');
		}

		$name = trim((string) ($connector['name'] ?? ''));

		return '' !== $name ? $name : $id;
	}

	private static function is_selectable_provider(string $provider): bool {
		$provider_ids = array_map(
			static fn (array $provider_choice): string => sanitize_key((string) ($provider_choice['id'] ?? '')),
			self::get_providers()
		);

		return in_array($provider, $provider_ids, true);
	}
}

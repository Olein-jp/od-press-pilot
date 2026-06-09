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

		if (method_exists($builder, 'as_json_response')) {
			$builder = $builder->as_json_response(PromptBuilder::schema());
		}

		if (method_exists($builder, 'is_supported_for_text_generation') && ! $builder->is_supported_for_text_generation()) {
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

			return self::create_generation_error($json);
		}

		$parsed = ResponseParser::parse((string) $json);

		if (is_wp_error($parsed)) {
			return $parsed;
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

	private static function create_generation_error(WP_Error $error): WP_Error {
		$details = self::generation_error_details($error);

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

		if (self::contains_any($normalized, ['insufficient_quota', 'quota', 'billing', 'credit', 'credits', 'balance', 'payment', 'hard_limit'])) {
			return [
				'code'    => 'od_press_pilot_billing_required',
				'message' => __('AI Provider 側の利用枠またはクレジットが不足している可能性があります。API キーを発行済みでも、Billing の有効化、支払い方法、またはクレジット残高を確認してください。', 'od-press-pilot') . self::format_raw_error($raw),
				'status'  => 402,
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

		if (self::contains_any($normalized, ['rate_limit', 'rate limit', 'too many requests', '429'])) {
			return [
				'code'    => 'od_press_pilot_rate_limited',
				'message' => __('AI Provider のレート制限に達しました。少し時間を空けてから再実行してください。', 'od-press-pilot') . self::format_raw_error($raw),
				'status'  => 429,
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

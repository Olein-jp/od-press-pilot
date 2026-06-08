<?php
/**
 * AI response parser.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\AI;

use WP_Error;

if (! defined('ABSPATH')) {
	exit;
}

final class ResponseParser {
	/**
	 * Parse and normalize JSON text.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function parse(string $json) {
		$data = json_decode(trim($json), true);

		if (! is_array($data)) {
			return new WP_Error('od_press_pilot_json_parse_failed', __('AIレスポンスの解析に失敗しました。再生成してください。', 'od-press-pilot'), ['status' => 502]);
		}

		return [
			'title'                  => sanitize_text_field((string) ($data['title'] ?? '')),
			'notice'                 => wp_kses_post((string) ($data['notice'] ?? '')),
			'sns_summary'            => sanitize_textarea_field((string) ($data['sns_summary'] ?? '')),
			'sns_summary_translated' => sanitize_textarea_field((string) ($data['sns_summary_translated'] ?? '')),
			'meta_description'       => sanitize_textarea_field((string) ($data['meta_description'] ?? '')),
			'hashtags'               => self::sanitize_hashtags($data['hashtags'] ?? []),
		];
	}

	/**
	 * @param mixed $hashtags Raw hashtags.
	 * @return string[]
	 */
	private static function sanitize_hashtags($hashtags): array {
		if (! is_array($hashtags)) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static function ($hashtag): string {
						return sanitize_text_field((string) $hashtag);
					},
					$hashtags
				)
			)
		);
	}
}

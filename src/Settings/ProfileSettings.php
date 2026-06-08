<?php
/**
 * Profile option storage.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\Settings;

if (! defined('ABSPATH')) {
	exit;
}

final class ProfileSettings {
	public const OPTION_NAME = 'od_ai_writer_profile';

	/**
	 * Return the default profile shape.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return [
			'business_name'       => '',
			'service_name'        => '',
			'service_description' => '',
			'target_customer'     => '',
			'strengths'           => '',
			'philosophy'          => '',
			'catch_copy'          => '',
			'tone'                => '丁寧',
			'ng_words'            => '',
			'cta'                 => '',
			'sns_policy'          => '',
			'hashtag_policy'      => '',
			'additional_notes'    => '',
		];
	}

	/**
	 * Get the stored profile.
	 *
	 * @return array<string, string>
	 */
	public static function get(): array {
		$value = get_option(self::OPTION_NAME, []);

		if (! is_array($value)) {
			$value = [];
		}

		return array_merge(self::defaults(), array_intersect_key($value, self::defaults()));
	}

	/**
	 * Sanitize incoming profile data.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, string>
	 */
	public static function sanitize(array $input): array {
		$profile = self::defaults();

		foreach ($profile as $key => $default) {
			$value = isset($input[$key]) ? wp_unslash($input[$key]) : $default;

			if ('tone' === $key) {
				$allowed_tones = ['丁寧', 'カジュアル', '親しみやすい', 'フォーマル'];
				$profile[$key] = in_array($value, $allowed_tones, true) ? $value : '丁寧';
				continue;
			}

			$profile[$key] = sanitize_textarea_field((string) $value);
		}

		return $profile;
	}

	/**
	 * Save profile with autoload disabled.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, string>
	 */
	public static function save(array $input): array {
		$profile = self::sanitize($input);

		if (false === get_option(self::OPTION_NAME, false)) {
			add_option(self::OPTION_NAME, $profile, '', false);
		} else {
			update_option(self::OPTION_NAME, $profile, false);
		}

		return $profile;
	}
}

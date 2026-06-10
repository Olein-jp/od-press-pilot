<?php
/**
 * Generation template option storage.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\Settings;

use WP_Error;

if (! defined('ABSPATH')) {
	exit;
}

final class TemplateSettings {
	public const OPTION_NAME = 'od_press_pilot_templates';

	/**
	 * Return the default template shape.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'id'                          => '',
			'name'                        => '',
			'post_content'                => '',
			'audience'                    => '',
			'desired_length'              => 0,
			'translation_language'        => 'none',
			'translation_languages'       => [],
			'custom_translation_language' => '',
			'use_emoji'                   => false,
			'generate_hashtags'           => true,
			'provider'                    => 'auto',
			'updated_at'                  => '',
			'source'                      => 'local',
			'readonly'                    => false,
		];
	}

	/**
	 * Get all stored templates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		return array_values(array_merge(self::stored(), self::external()));
	}

	/**
	 * Get all stored templates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function stored(): array {
		$value = get_option(self::OPTION_NAME, []);

		if (! is_array($value)) {
			return [];
		}

		$templates = [];

		foreach ($value as $template) {
			if (! is_array($template)) {
				continue;
			}

			$sanitized = self::sanitize($template, '', false);

			if ('' !== $sanitized['id'] && '' !== $sanitized['name']) {
				$templates[] = $sanitized;
			}
		}

		return $templates;
	}

	/**
	 * Get templates registered by external plugins.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function external(): array {
		$registered_templates = apply_filters('odpp_external_templates', []);

		if (! is_array($registered_templates)) {
			return [];
		}

		$templates    = [];
		$known_ids    = array_fill_keys(array_column(self::stored(), 'id'), true);
		$external_ids = [];

		foreach ($registered_templates as $template) {
			if (! is_array($template)) {
				continue;
			}

			if (empty($template['id'])) {
				continue;
			}

			$sanitized = self::sanitize($template, '', false);

			if ('' === $sanitized['id'] || '' === $sanitized['name'] || isset($known_ids[$sanitized['id']]) || isset($external_ids[$sanitized['id']])) {
				continue;
			}

			$sanitized['source'] = 'external';
			$sanitized['readonly'] = true;
			$templates[] = $sanitized;
			$external_ids[$sanitized['id']] = true;
		}

		return $templates;
	}

	/**
	 * Create a template.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create(array $input) {
		$template = self::sanitize($input, self::generate_id());

		if ('' === $template['name']) {
			return new WP_Error('od_press_pilot_template_name_required', __('テンプレート名を入力してください。', 'od-press-pilot'), ['status' => 400]);
		}

		$templates   = self::stored();
		$templates[] = $template;

		self::save_all($templates);

		return $template;
	}

	/**
	 * Update a template.
	 *
	 * @param string               $id    Template ID.
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update(string $id, array $input) {
		$id        = sanitize_key($id);
		$templates = self::stored();

		foreach ($templates as $index => $template) {
			if ($id !== $template['id']) {
				continue;
			}

			$updated = self::sanitize(array_merge($template, $input), $id);

			if ('' === $updated['name']) {
				return new WP_Error('od_press_pilot_template_name_required', __('テンプレート名を入力してください。', 'od-press-pilot'), ['status' => 400]);
			}

			$templates[$index] = $updated;
			self::save_all($templates);

			return $updated;
		}

		if (self::has_external($id)) {
			return new WP_Error('od_press_pilot_template_readonly', __('外部テンプレートは編集できません。', 'od-press-pilot'), ['status' => 403]);
		}

		return new WP_Error('od_press_pilot_template_not_found', __('テンプレートが見つかりませんでした。', 'od-press-pilot'), ['status' => 404]);
	}

	/**
	 * Delete a template.
	 *
	 * @param string $id Template ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete(string $id) {
		$id        = sanitize_key($id);
		$templates = self::stored();
		$next      = [];
		$deleted   = false;

		foreach ($templates as $template) {
			if ($id === $template['id']) {
				$deleted = true;
				continue;
			}

			$next[] = $template;
		}

		if (! $deleted) {
			if (self::has_external($id)) {
				return new WP_Error('od_press_pilot_template_readonly', __('外部テンプレートは削除できません。', 'od-press-pilot'), ['status' => 403]);
			}

			return new WP_Error('od_press_pilot_template_not_found', __('テンプレートが見つかりませんでした。', 'od-press-pilot'), ['status' => 404]);
		}

		self::save_all($next);

		return [
			'deleted'   => true,
			'templates' => self::all(),
		];
	}

	/**
	 * Sanitize incoming template data.
	 *
	 * @param array<string, mixed> $input      Raw input.
	 * @param string               $fallback_id Fallback template ID.
	 * @param bool                 $touch       Whether to refresh updated_at.
	 * @return array<string, mixed>
	 */
	public static function sanitize(array $input, string $fallback_id = '', bool $touch = true): array {
		$translation_languages = self::sanitize_translation_languages($input);
		$id                    = sanitize_key((string) ($input['id'] ?? $fallback_id));
		$provider              = sanitize_key((string) ($input['provider'] ?? 'auto'));

		if ('' === $id) {
			$id = self::generate_id();
		}

		return [
			'id'                          => $id,
			'name'                        => sanitize_text_field((string) wp_unslash($input['name'] ?? '')),
			'post_content'                => sanitize_textarea_field((string) wp_unslash($input['post_content'] ?? '')),
			'audience'                    => sanitize_textarea_field((string) wp_unslash($input['audience'] ?? '')),
			'desired_length'              => absint($input['desired_length'] ?? 0),
			'translation_language'        => $translation_languages[0] ?? 'none',
			'translation_languages'       => $translation_languages,
			'custom_translation_language' => sanitize_text_field((string) wp_unslash($input['custom_translation_language'] ?? '')),
			'use_emoji'                   => ! empty($input['use_emoji']),
			'generate_hashtags'           => ! empty($input['generate_hashtags']),
			'provider'                    => '' === $provider ? 'auto' : $provider,
			'updated_at'                  => $touch ? wp_date(DATE_ATOM) : sanitize_text_field((string) ($input['updated_at'] ?? '')),
			'source'                      => 'local',
			'readonly'                    => false,
		];
	}

	private static function has_external(string $id): bool {
		foreach (self::external() as $template) {
			if ($id === $template['id']) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $params Raw params.
	 * @return string[]
	 */
	private static function sanitize_translation_languages(array $params): array {
		$allowed_languages = ['en', 'zh-hans', 'zh-hant', 'ko', 'ja', 'custom'];
		$raw_languages     = $params['translation_languages'] ?? [];

		if (! is_array($raw_languages)) {
			$raw_languages = [];
		}

		if ([] === $raw_languages && ! empty($params['translation_language'])) {
			$raw_languages = [(string) $params['translation_language']];
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ($language): string => sanitize_key((string) $language),
						$raw_languages
					),
					static fn (string $language): bool => in_array($language, $allowed_languages, true)
				)
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $templates Templates.
	 */
	private static function save_all(array $templates): void {
		if (false === get_option(self::OPTION_NAME, false)) {
			add_option(self::OPTION_NAME, array_values($templates), '', false);
		} else {
			update_option(self::OPTION_NAME, array_values($templates), false);
		}
	}

	private static function generate_id(): string {
		return 'template_' . str_replace('-', '', wp_generate_uuid4());
	}
}

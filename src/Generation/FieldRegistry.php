<?php
/**
 * External generation field registry.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\Generation;

if (! defined('ABSPATH')) {
	exit;
}

final class FieldRegistry {
	private const ALLOWED_TYPES = ['text', 'textarea', 'number', 'checkbox', 'select'];

	/**
	 * Return normalized generation field definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		/**
		 * Filter generation condition field definitions.
		 *
		 * @param array<int, array<string, mixed>> $fields Field definitions.
		 */
		$fields = apply_filters('odpp_generation_fields', []);

		if (! is_array($fields)) {
			return [];
		}

		$definitions = [];
		$known_ids   = [];

		foreach ($fields as $field) {
			if (! is_array($field)) {
				continue;
			}

			$definition = self::normalize_definition($field);

			if ([] === $definition || isset($known_ids[$definition['id']])) {
				continue;
			}

			$definitions[] = $definition;
			$known_ids[$definition['id']] = true;
		}

		return $definitions;
	}

	/**
	 * Return definitions safe for REST responses.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function public_definitions(): array {
		return array_map(
			static function (array $field): array {
				unset($field['sanitize_callback']);

				return $field;
			},
			self::definitions()
		);
	}

	/**
	 * Return default extra field values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = [];

		foreach (self::definitions() as $field) {
			$defaults[$field['id']] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * Sanitize submitted extra field values against registered definitions.
	 *
	 * @param mixed $values Raw extra field values.
	 * @return array<string, mixed>
	 */
	public static function sanitize_values($values): array {
		if (! is_array($values)) {
			$values = [];
		}

		$sanitized = [];

		foreach (self::definitions() as $field) {
			$id  = $field['id'];
			$raw = array_key_exists($id, $values) ? wp_unslash($values[$id]) : $field['default'];

			$sanitized[$id] = self::sanitize_value($raw, $field);
		}

		return $sanitized;
	}

	/**
	 * Format extra fields for prompt context.
	 *
	 * @param array<string, mixed> $values Sanitized extra field values.
	 */
	public static function prompt_text(array $values): string {
		$lines = [];

		foreach (self::definitions() as $field) {
			if (false === $field['include_in_prompt']) {
				continue;
			}

			$id = $field['id'];

			if (! array_key_exists($id, $values)) {
				continue;
			}

			$value = self::prompt_value($values[$id], $field);

			if ('' === $value) {
				continue;
			}

			$lines[] = $field['prompt_label'] . ': ' . $value;
		}

		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $field Raw field definition.
	 * @return array<string, mixed>
	 */
	private static function normalize_definition(array $field): array {
		$id    = sanitize_key((string) ($field['id'] ?? ''));
		$label = sanitize_text_field((string) ($field['label'] ?? ''));
		$type  = sanitize_key((string) ($field['type'] ?? 'text'));

		if ('' === $id || '' === $label || ! in_array($type, self::ALLOWED_TYPES, true)) {
			return [];
		}

		$options = 'select' === $type ? self::sanitize_options($field['options'] ?? []) : [];

		if ('select' === $type && [] === $options) {
			return [];
		}

		$default = $field['default'] ?? self::default_for_type($type);

		if ('select' === $type && '' === (string) $default) {
			$default = $options[0]['value'];
		}

		$definition = [
			'id'                => $id,
			'label'             => $label,
			'type'              => $type,
			'default'           => self::sanitize_value($default, ['type' => $type, 'options' => $options]),
			'options'           => $options,
			'description'       => sanitize_text_field((string) ($field['description'] ?? '')),
			'prompt_label'      => sanitize_text_field((string) ($field['prompt_label'] ?? $label)),
			'include_in_prompt' => ! array_key_exists('include_in_prompt', $field) || ! empty($field['include_in_prompt']),
		];

		if (isset($field['sanitize_callback']) && is_callable($field['sanitize_callback'])) {
			$definition['sanitize_callback'] = $field['sanitize_callback'];
		}

		return $definition;
	}

	/**
	 * @param mixed                $value Raw value.
	 * @param array<string, mixed> $field Field definition.
	 * @return mixed
	 */
	private static function sanitize_value($value, array $field) {
		if (isset($field['sanitize_callback']) && is_callable($field['sanitize_callback'])) {
			return call_user_func($field['sanitize_callback'], $value, $field);
		}

		switch ($field['type']) {
			case 'textarea':
				return sanitize_textarea_field((string) $value);
			case 'number':
				return is_numeric($value) ? (float) $value : 0;
			case 'checkbox':
				return ! empty($value);
			case 'select':
				$value          = sanitize_text_field((string) $value);
				$allowed_values = array_column($field['options'] ?? [], 'value');

				return in_array($value, $allowed_values, true) ? $value : (string) ($field['default'] ?? '');
			case 'text':
			default:
				return sanitize_text_field((string) $value);
		}
	}

	/**
	 * @param mixed $options Raw select options.
	 * @return array<int, array{label:string,value:string}>
	 */
	private static function sanitize_options($options): array {
		if (! is_array($options)) {
			return [];
		}

		$sanitized = [];
		$known     = [];

		foreach ($options as $option) {
			if (! is_array($option)) {
				continue;
			}

			$label = sanitize_text_field((string) ($option['label'] ?? ''));
			$value = sanitize_text_field((string) ($option['value'] ?? ''));

			if ('' === $label || '' === $value || isset($known[$value])) {
				continue;
			}

			$sanitized[] = [
				'label' => $label,
				'value' => $value,
			];
			$known[$value] = true;
		}

		return $sanitized;
	}

	private static function default_for_type(string $type) {
		if ('checkbox' === $type) {
			return false;
		}

		if ('number' === $type) {
			return 0;
		}

		return '';
	}

	/**
	 * @param mixed                $value Sanitized value.
	 * @param array<string, mixed> $field Field definition.
	 */
	private static function prompt_value($value, array $field): string {
		if ('checkbox' === $field['type']) {
			return ! empty($value) ? 'あり' : 'なし';
		}

		if ('select' === $field['type']) {
			foreach ($field['options'] as $option) {
				if ((string) $value === $option['value']) {
					return $option['label'];
				}
			}
		}

		if (is_bool($value)) {
			return $value ? 'あり' : 'なし';
		}

		if (is_scalar($value)) {
			return trim((string) $value);
		}

		return '';
	}
}

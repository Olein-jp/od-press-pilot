<?php
/**
 * AI Provider usage stats.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\AI;

if (! defined('ABSPATH')) {
	exit;
}

final class UsageStats {
	private const OPTION_NAME = 'od_press_pilot_ai_usage_stats';

	/**
	 * Return usage stats for all tracked providers.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stats = get_option(self::OPTION_NAME, []);

		if (! is_array($stats)) {
			$stats = [];
		}

		$stats['google'] = self::normalize_provider_stats($stats['google'] ?? []);

		return $stats;
	}

	/**
	 * Return usage stats for one provider.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_provider(string $provider): array {
		$stats = self::all();
		$key   = self::provider_key($provider);

		return is_array($stats[$key] ?? null) ? $stats[$key] : self::default_provider_stats();
	}

	public static function record_generation(string $provider): void {
		$key = self::provider_key($provider);

		if ('google' !== $key) {
			return;
		}

		$stats          = self::all();
		$provider_stats = self::normalize_provider_stats($stats[$key] ?? []);
		$today          = self::today();
		$now            = self::now();

		if ($provider_stats['daily_date'] !== $today) {
			$provider_stats['daily_date']  = $today;
			$provider_stats['daily_count'] = 0;
		}

		$provider_stats['daily_count']       = (int) $provider_stats['daily_count'] + 1;
		$provider_stats['recent_timestamps'] = self::recent_timestamps(
			array_merge($provider_stats['recent_timestamps'], [$now]),
			$now
		);

		$stats[$key] = $provider_stats;

		self::save($stats);
	}

	public static function record_rate_limit_error(string $provider, string $message): void {
		$key = self::provider_key($provider);

		if ('google' !== $key) {
			return;
		}

		$stats          = self::all();
		$provider_stats = self::normalize_provider_stats($stats[$key] ?? []);

		$provider_stats['last_rate_limit_error_at']      = current_time('mysql');
		$provider_stats['last_rate_limit_error_message'] = self::trim_message($message);

		$stats[$key] = $provider_stats;

		self::save($stats);
	}

	/**
	 * @param mixed $stats Raw provider stats.
	 * @return array<string, mixed>
	 */
	private static function normalize_provider_stats($stats): array {
		$stats = is_array($stats) ? $stats : [];
		$today = self::today();
		$now   = self::now();

		$normalized = array_merge(
			self::default_provider_stats(),
			[
				'daily_count'                   => absint($stats['daily_count'] ?? 0),
				'daily_date'                    => sanitize_text_field((string) ($stats['daily_date'] ?? $today)),
				'recent_timestamps'             => self::sanitize_timestamps($stats['recent_timestamps'] ?? []),
				'last_rate_limit_error_at'      => sanitize_text_field((string) ($stats['last_rate_limit_error_at'] ?? '')),
				'last_rate_limit_error_message' => sanitize_text_field((string) ($stats['last_rate_limit_error_message'] ?? '')),
			]
		);

		if ($normalized['daily_date'] !== $today) {
			$normalized['daily_date']  = $today;
			$normalized['daily_count'] = 0;
		}

		$normalized['recent_timestamps'] = self::recent_timestamps($normalized['recent_timestamps'], $now);
		$normalized['recent_count']      = count($normalized['recent_timestamps']);

		return $normalized;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function default_provider_stats(): array {
		return [
			'daily_count'                   => 0,
			'daily_date'                    => self::today(),
			'recent_timestamps'             => [],
			'recent_count'                  => 0,
			'last_rate_limit_error_at'      => '',
			'last_rate_limit_error_message' => '',
		];
	}

	/**
	 * @param mixed $timestamps Raw timestamps.
	 * @return int[]
	 */
	private static function sanitize_timestamps($timestamps): array {
		if (! is_array($timestamps)) {
			return [];
		}

		return array_values(
			array_filter(
				array_map('absint', $timestamps),
				static fn (int $timestamp): bool => 0 < $timestamp
			)
		);
	}

	/**
	 * @param int[] $timestamps Timestamps.
	 * @return int[]
	 */
	private static function recent_timestamps(array $timestamps, int $now): array {
		return array_values(
			array_slice(
				array_filter(
					$timestamps,
					static fn (int $timestamp): bool => $timestamp >= ($now - MINUTE_IN_SECONDS)
				),
				-100
			)
		);
	}

	private static function provider_key(string $provider): string {
		return 'google' === sanitize_key($provider) ? 'google' : sanitize_key($provider);
	}

	private static function today(): string {
		return current_time('Y-m-d');
	}

	private static function now(): int {
		return (int) current_time('timestamp');
	}

	private static function trim_message(string $message): string {
		$message = trim(wp_strip_all_tags($message));

		if (function_exists('mb_substr')) {
			return mb_substr($message, 0, 300);
		}

		return substr($message, 0, 300);
	}

	/**
	 * @param array<string, mixed> $stats Stats to save.
	 */
	private static function save(array $stats): void {
		update_option(self::OPTION_NAME, $stats, false);
	}
}

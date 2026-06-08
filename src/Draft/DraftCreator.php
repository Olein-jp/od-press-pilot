<?php
/**
 * Draft post creation.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\Draft;

use WP_Error;

if (! defined('ABSPATH')) {
	exit;
}

final class DraftCreator {
	/**
	 * Create a WordPress post draft.
	 *
	 * @param array<string, mixed> $data Draft data.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create(array $data) {
		$title   = sanitize_text_field((string) ($data['title'] ?? ''));
		$content = ContentBlockConverter::convert((string) ($data['notice'] ?? ''));

		if ('' === $title || '' === $content) {
			return new WP_Error('od_press_pilot_draft_missing_content', __('タイトルと本文を入力してください。', 'od-press-pilot'), ['status' => 400]);
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
			],
			true
		);

		if (is_wp_error($post_id)) {
			return $post_id;
		}

		return [
			'id'       => $post_id,
			'edit_url' => get_edit_post_link($post_id, 'raw'),
			'message'  => __('下書きを作成しました。', 'od-press-pilot'),
		];
	}
}

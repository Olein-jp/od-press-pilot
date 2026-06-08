<?php
/**
 * AI prompt builder.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\AI;

if (! defined('ABSPATH')) {
	exit;
}

final class PromptBuilder {
	/**
	 * Build the prompt text sent to the AI Client.
	 *
	 * @param array<string, string> $profile Profile data.
	 * @param array<string, mixed>  $request Generation request.
	 */
	public static function build(array $profile, array $request): string {
		$profile_text = self::format_profile($profile);
		$translation  = self::translation_label((string) ($request['translation_language'] ?? 'none'), (string) ($request['custom_translation_language'] ?? ''));
		$emoji        = ! empty($request['use_emoji']) ? 'あり' : 'なし';
		$hashtags     = ! empty($request['generate_hashtags']) ? 'あり' : 'なし';
		$length       = ! empty($request['desired_length']) ? (string) absint($request['desired_length']) . '文字程度' : '指定なし';

		return sprintf(
			"あなたは、この事業者専属の広報担当者です。\n\n事業者プロフィール:\n%s\n\n追加指示:\n%s\n\n今回のお知らせ内容:\n%s\n\n対象読者:\n%s\n\n希望文字数:\n%s\n\n翻訳言語:\n%s\n\n絵文字利用:\n%s\n\nハッシュタグ生成:\n%s\n\nルール:\n- 事実を勝手に追加しない\n- 不明な情報は推測しない\n- 誇大表現を避ける\n- ターゲットに合わせる\n- 事業者らしい文章にする\n- メインコンテンツはWordPressブロックエディターへ貼り付け可能な形式で出力する\n- すべて日本語で出力する\n- 指定された翻訳言語がある場合のみ翻訳版を出力する\n- JSON以外の文字を出力しない\n\nJSON Schema:\n%s",
			$profile_text,
			$profile['additional_notes'],
			(string) ($request['post_content'] ?? ''),
			(string) ($request['audience'] ?? ''),
			$length,
			$translation,
			$emoji,
			$hashtags,
			wp_json_encode(self::schema(), JSON_UNESCAPED_UNICODE)
		);
	}

	/**
	 * Return the JSON schema for structured output.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'title'                  => ['type' => 'string'],
				'notice'                 => ['type' => 'string'],
				'sns_summary'            => ['type' => 'string'],
				'sns_summary_translated' => ['type' => 'string'],
				'meta_description'       => ['type' => 'string'],
				'hashtags'               => [
					'type'  => 'array',
					'items' => ['type' => 'string'],
				],
			],
			'required'   => ['title', 'notice', 'sns_summary', 'sns_summary_translated', 'meta_description', 'hashtags'],
		];
	}

	/**
	 * Format profile fields for prompt context.
	 *
	 * @param array<string, string> $profile Profile data.
	 */
	private static function format_profile(array $profile): string {
		$labels = [
			'business_name'       => '事業者名',
			'service_name'        => 'サービス名',
			'service_description' => 'サービス概要',
			'target_customer'     => 'ターゲット顧客',
			'strengths'           => '強み・特徴',
			'philosophy'          => '会社理念・想い',
			'catch_copy'          => 'よく使うキャッチコピー',
			'tone'                => '文章トーン',
			'ng_words'            => 'NG表現',
			'cta'                 => 'よく使うCTA',
			'sns_policy'          => 'SNS運用方針',
			'hashtag_policy'      => 'ハッシュタグ方針',
		];

		$lines = [];
		foreach ($labels as $key => $label) {
			$lines[] = $label . ': ' . ($profile[$key] ?? '');
		}

		return implode("\n", $lines);
	}

	private static function translation_label(string $language, string $custom_language): string {
		$labels = [
			'none'    => 'なし',
			'en'      => '英語',
			'zh-hans' => '中国語（簡体字）',
			'zh-hant' => '中国語（繁体字）',
			'ko'      => '韓国語',
			'ja'      => '日本語',
			'custom'  => $custom_language,
		];

		return $labels[$language] ?? 'なし';
	}
}

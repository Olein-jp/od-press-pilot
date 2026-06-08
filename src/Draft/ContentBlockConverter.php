<?php
/**
 * Convert draft content to WordPress block markup.
 *
 * @package ODPressPilot
 */

declare(strict_types=1);

namespace ODPressPilot\Draft;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMText;

if (! defined('ABSPATH')) {
	exit;
}

final class ContentBlockConverter {
	/**
	 * Convert plain text or HTML content to block markup.
	 */
	public static function convert(string $content): string {
		$content = trim($content);

		if ('' === $content) {
			return '';
		}

		if (function_exists('has_blocks') && has_blocks($content)) {
			return function_exists('filter_block_content') ? filter_block_content($content) : $content;
		}

		$content = wp_kses_post($content);

		if (! self::contains_html($content)) {
			return self::convert_plain_text($content);
		}

		$document = self::load_html($content);

		if (! $document instanceof DOMDocument) {
			return self::convert_plain_text(wp_strip_all_tags($content));
		}

		$root = $document->getElementById('od-press-pilot-content-root');

		if (! $root instanceof DOMElement) {
			return self::convert_plain_text(wp_strip_all_tags($content));
		}

		$blocks = self::convert_nodes($root->childNodes);

		return trim(implode("\n\n", $blocks));
	}

	private static function contains_html(string $content): bool {
		return $content !== wp_strip_all_tags($content);
	}

	private static function load_html(string $content): ?DOMDocument {
		$document = new DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		$loaded   = $document->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="od-press-pilot-content-root">' . $content . '</div></body></html>',
			LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return $loaded ? $document : null;
	}

	/**
	 * @param DOMNodeList<DOMNode> $nodes Nodes to convert.
	 * @return string[]
	 */
	private static function convert_nodes(DOMNodeList $nodes): array {
		$blocks = [];

		foreach ($nodes as $node) {
			if ($node instanceof DOMText) {
				$blocks = array_merge($blocks, self::convert_text_node($node));
				continue;
			}

			if (! $node instanceof DOMElement) {
				continue;
			}

			$block = self::convert_element($node);

			if ('' !== $block) {
				$blocks[] = $block;
			}
		}

		return $blocks;
	}

	/**
	 * @return string[]
	 */
	private static function convert_text_node(DOMText $node): array {
		$text = trim($node->wholeText);

		if ('' === $text) {
			return [];
		}

		return array_map(
			static fn (string $paragraph): string => self::serialize_block('core/paragraph', '<p>' . nl2br(esc_html(trim($paragraph)), false) . '</p>'),
			preg_split('/\R{2,}/u', $text) ?: []
		);
	}

	private static function convert_element(DOMElement $element): string {
		$tag = strtolower($element->tagName);

		if ('p' === $tag) {
			return self::serialize_block('core/paragraph', self::outer_html($element));
		}

		if (preg_match('/^h([1-6])$/', $tag, $matches)) {
			$level = (int) $matches[1];

			return self::serialize_block('core/heading', self::outer_html($element), ['level' => $level]);
		}

		if ('ul' === $tag || 'ol' === $tag) {
			return self::serialize_list_block($element, 'ol' === $tag);
		}

		if ('blockquote' === $tag) {
			return self::serialize_block('core/quote', self::outer_html($element));
		}

		if ('pre' === $tag) {
			return self::serialize_block('core/preformatted', self::outer_html($element));
		}

		if ('img' === $tag) {
			return self::serialize_block('core/image', '<figure class="wp-block-image">' . self::outer_html($element) . '</figure>');
		}

		if (self::has_block_children($element)) {
			return trim(implode("\n\n", self::convert_nodes($element->childNodes)));
		}

		return self::serialize_block('core/paragraph', '<p>' . self::inner_html($element) . '</p>');
	}

	private static function has_block_children(DOMElement $element): bool {
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'pre', 'img'], true)) {
				return true;
			}
		}

		return false;
	}

	private static function serialize_list_block(DOMElement $element, bool $ordered): string {
		$inner_blocks  = [];
		$inner_content = ['<' . ($ordered ? 'ol' : 'ul') . '>'];

		foreach ($element->childNodes as $child) {
			if (! $child instanceof DOMElement || 'li' !== strtolower($child->tagName)) {
				continue;
			}

			$inner_blocks[]  = self::create_list_item_block($child);
			$inner_content[] = null;
		}

		$inner_content[] = '</' . ($ordered ? 'ol' : 'ul') . '>';

		return serialize_block(
			[
				'blockName'    => 'core/list',
				'attrs'        => $ordered ? ['ordered' => true] : [],
				'innerBlocks'  => $inner_blocks,
				'innerHTML'    => self::outer_html($element),
				'innerContent' => $inner_content,
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function create_list_item_block(DOMElement $element): array {
		$inner_blocks = [];
		$content      = '';

		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
				$inner_blocks[] = self::create_list_child_block($child, 'ol' === strtolower($child->tagName));
				continue;
			}

			$content .= self::node_html($child);
		}

		$inner_content = ['<li>' . $content];

		foreach ($inner_blocks as $inner_block) {
			$inner_content[] = null;
		}

		$inner_content[] = '</li>';

		return [
			'blockName'    => 'core/list-item',
			'attrs'        => [],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '<li>' . $content . '</li>',
			'innerContent' => $inner_content,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function create_list_child_block(DOMElement $element, bool $ordered): array {
		$inner_blocks  = [];
		$inner_content = ['<' . ($ordered ? 'ol' : 'ul') . '>'];

		foreach ($element->childNodes as $child) {
			if (! $child instanceof DOMElement || 'li' !== strtolower($child->tagName)) {
				continue;
			}

			$inner_blocks[]  = self::create_list_item_block($child);
			$inner_content[] = null;
		}

		$inner_content[] = '</' . ($ordered ? 'ol' : 'ul') . '>';

		return [
			'blockName'    => 'core/list',
			'attrs'        => $ordered ? ['ordered' => true] : [],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => self::outer_html($element),
			'innerContent' => $inner_content,
		];
	}

	/**
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private static function serialize_block(string $block_name, string $inner_html, array $attrs = []): string {
		return serialize_block(
			[
				'blockName'    => $block_name,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => $inner_html,
				'innerContent' => [$inner_html],
			]
		);
	}

	private static function convert_plain_text(string $content): string {
		$lines           = preg_split('/\R/u', trim($content)) ?: [];
		$blocks          = [];
		$paragraph_lines = [];
		$line_count      = count($lines);

		for ($index = 0; $index < $line_count; $index++) {
			$line = trim($lines[$index]);

			if ('' === $line) {
				self::flush_paragraph($blocks, $paragraph_lines);
				continue;
			}

			if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $heading_matches)) {
				self::flush_paragraph($blocks, $paragraph_lines);

				$level    = strlen($heading_matches[1]);
				$blocks[] = self::serialize_block('core/heading', '<h' . $level . '>' . esc_html($heading_matches[2]) . '</h' . $level . '>', ['level' => $level]);
				continue;
			}

			if (self::is_list_line($line)) {
				self::flush_paragraph($blocks, $paragraph_lines);

				$items   = [];
				$ordered = self::is_ordered_list_line($line);

				while ($index < $line_count) {
					$current_line = trim($lines[$index]);

					if ('' === $current_line || ! self::is_list_line($current_line) || self::is_ordered_list_line($current_line) !== $ordered) {
						$index--;
						break;
					}

					$items[] = self::list_item_text($current_line);
					$index++;
				}

				$blocks[] = self::serialize_plain_list_block($items, $ordered);
				continue;
			}

			$paragraph_lines[] = $line;
		}

		self::flush_paragraph($blocks, $paragraph_lines);

		return trim(implode("\n\n", $blocks));
	}

	/**
	 * @param string[] $blocks Blocks by reference.
	 * @param string[] $paragraph_lines Pending paragraph lines by reference.
	 */
	private static function flush_paragraph(array &$blocks, array &$paragraph_lines): void {
		if ([] === $paragraph_lines) {
			return;
		}

		$blocks[]        = self::serialize_block('core/paragraph', '<p>' . esc_html(implode("\n", $paragraph_lines)) . '</p>');
		$paragraph_lines = [];
	}

	private static function is_list_line(string $line): bool {
		return (bool) preg_match('/^(?:[-*+]\s+|\d+[\.)]\s+).+$/u', $line);
	}

	private static function is_ordered_list_line(string $line): bool {
		return (bool) preg_match('/^\d+[\.)]\s+.+$/u', $line);
	}

	private static function list_item_text(string $line): string {
		return preg_replace('/^(?:[-*+]\s+|\d+[\.)]\s+)/u', '', $line) ?? $line;
	}

	/**
	 * @param string[] $items List item text.
	 */
	private static function serialize_plain_list_block(array $items, bool $ordered): string {
		$tag           = $ordered ? 'ol' : 'ul';
		$inner_blocks  = [];
		$inner_content = ['<' . $tag . '>'];
		$inner_html    = '<' . $tag . '>';

		foreach ($items as $item) {
			$list_item      = '<li>' . esc_html($item) . '</li>';
			$inner_blocks[] = [
				'blockName'    => 'core/list-item',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $list_item,
				'innerContent' => [$list_item],
			];
			$inner_content[] = null;
			$inner_html     .= $list_item;
		}

		$inner_content[] = '</' . $tag . '>';
		$inner_html     .= '</' . $tag . '>';

		return serialize_block(
			[
				'blockName'    => 'core/list',
				'attrs'        => $ordered ? ['ordered' => true] : [],
				'innerBlocks'  => $inner_blocks,
				'innerHTML'    => $inner_html,
				'innerContent' => $inner_content,
			]
		);
	}

	private static function outer_html(DOMNode $node): string {
		return $node->ownerDocument instanceof DOMDocument ? (string) $node->ownerDocument->saveHTML($node) : '';
	}

	private static function inner_html(DOMNode $node): string {
		$html = '';

		foreach ($node->childNodes as $child) {
			$html .= self::node_html($child);
		}

		return $html;
	}

	private static function node_html(DOMNode $node): string {
		if ($node instanceof DOMText) {
			return esc_html($node->wholeText);
		}

		return self::outer_html($node);
	}
}

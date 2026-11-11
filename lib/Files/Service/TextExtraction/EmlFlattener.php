<?php

/**
 * EmlFlattener — the flat plain-text rendering of a parsed EML.
 *
 * Format: header block (From / To / Cc / Subject / Date), blank line, body
 * (`text/plain` if present, otherwise HTML stripped to text), attachments
 * listed under marker lines. Nested EML attachments are inlined
 * recursively.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use Closure;
use DateTimeImmutable;

/**
 * Renders an `EmlStructure` as flat text for {@see EmlParser::flatten()}.
 *
 * @spec openspec/specs/text-extraction-eml/spec.md
 */
final class EmlFlattener {
	/**
	 * Headers rendered on the flat path, in order.
	 *
	 * @var array<int, string>
	 */
	private const FLAT_HEADERS = ['from', 'to', 'cc', 'subject', 'date'];

	/**
	 * Constructor.
	 *
	 * @param Closure $toUtf8 Makes a body part UTF-8: `fn (string $value): string`.
	 */
	public function __construct(
		private readonly Closure $toUtf8,
	) {
	}//end __construct()

	/**
	 * Build the flat plain-text representation of an EmlStructure.
	 *
	 * Order: header block, blank line, body (plainText preferred),
	 * attachments under marker lines (recursively for nested EML).
	 *
	 * @param EmlStructure $structure Parsed structure.
	 * @param int $depth Current recursion depth.
	 *
	 * @return string Flat plain-text output.
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	public function flatten(EmlStructure $structure, int $depth = 0): string {
		$lines = array_merge(
			$this->headerLines(headers: $structure->headers),
			[''],
			$this->bodyLines(body: $structure->body),
			$this->attachmentLines(structure: $structure, depth: $depth)
		);

		return rtrim(implode("\n", $lines), "\n");
	}//end flatten()

	/**
	 * The `Name: value` lines of the flat header block, skipping empty headers.
	 *
	 * @param array<string, mixed> $headers The parsed headers.
	 *
	 * @return array<int, string>
	 */
	private function headerLines(array $headers): array {
		$lines = [];
		foreach (self::FLAT_HEADERS as $key) {
			$value = $headers[$key] ?? null;
			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$lines[] = ucfirst($key) . ': ' . $this->renderHeader(key: $key, value: $value);
		}

		return $lines;
	}//end headerLines()

	/**
	 * Render one header value for the flat path.
	 *
	 * The value is cast to a string before the date and list cases are looked
	 * at, as it always was; see the golden test for what that does to a
	 * parsed date and to an address list.
	 *
	 * @param string $key   The header key.
	 * @param mixed  $value The header value.
	 *
	 * @return string
	 */
	private function renderHeader(string $key, mixed $value): string {
		$rendered = (string)$value;
		if ($key === 'date' && $value instanceof DateTimeImmutable) {
			return $value->format(DateTimeImmutable::ATOM);
		}

		if (is_array($value) === true) {
			return implode(', ', $value);
		}

		return $rendered;
	}//end renderHeader()

	/**
	 * The body lines of the flat path: the body text and a blank line, or nothing.
	 *
	 * @param EmlBody $body The body parts.
	 *
	 * @return array<int, string>
	 */
	private function bodyLines(EmlBody $body): array {
		$bodyText = $this->bodyText(body: $body);
		if ($bodyText === '') {
			return [];
		}

		return [$bodyText, ''];
	}//end bodyLines()

	/**
	 * The body text of the flat path.
	 *
	 * Multipart/alternative preference: plainText > html-stripped fallback.
	 * The spec is explicit — when both parts exist, MUST emit plain only
	 * (do NOT concatenate the HTML).
	 *
	 * @param EmlBody $body The body parts.
	 *
	 * @return string The text, or an empty string when there is no body.
	 */
	private function bodyText(EmlBody $body): string {
		if ($body->plainText !== null && $body->plainText !== '') {
			return ($this->toUtf8)($body->plainText);
		}

		if ($body->html !== null && $body->html !== '') {
			return $this->htmlToText(html: ($this->toUtf8)($body->html));
		}

		return '';
	}//end bodyText()

	/**
	 * The attachment lines of the flat path.
	 *
	 * @param EmlStructure $structure Parsed structure.
	 * @param int          $depth     Current recursion depth.
	 *
	 * @return array<int, string>
	 */
	private function attachmentLines(EmlStructure $structure, int $depth): array {
		$lines = [];
		foreach ($structure->attachments as $attachment) {
			array_push($lines, ...$this->attachmentBlock(attachment: $attachment, depth: $depth));
		}

		return $lines;
	}//end attachmentLines()

	/**
	 * The lines for one attachment: a nested EML inlined, anything else listed.
	 *
	 * Non-EML extractable types — inline text extraction is deferred (see
	 * class docblock). For v1 we list the attachment by name + MIME.
	 *
	 * @param EmlAttachment $attachment The attachment.
	 * @param int           $depth      Current recursion depth.
	 *
	 * @return array<int, string>
	 */
	private function attachmentBlock(EmlAttachment $attachment, int $depth): array {
		if ($attachment->nestedEml !== null) {
			return [
				'--- Attachment: ' . $attachment->filename . ' ---',
				$this->flatten(structure: $attachment->nestedEml, depth: ($depth + 1)),
				'',
			];
		}

		return [
			'--- Attachment: ' . $attachment->filename . ' (' . $attachment->mimeType . ', not extractable inline in v1) ---',
			'',
		];
	}//end attachmentBlock()

	/**
	 * Strip a plain-text rendering out of an HTML string for the
	 * fallback path of `flatten()`.
	 *
	 * - Drops `<style>` and `<script>` element content entirely (block-level removal).
	 * - Replaces `<br>`, `<p>`, block-level tags with newlines.
	 * - Strips remaining tags via `strip_tags`.
	 * - Decodes entities via `html_entity_decode`.
	 * - Collapses whitespace runs.
	 *
	 * @param string $html HTML source.
	 *
	 * @return string Plain text.
	 *
	 * @spec openspec/specs/text-extraction-eml/spec.md#requirement-extracteml-and-parseemlstructured-must-not-log-pii-adr-005
	 */
	private function htmlToText(string $html): string {
		$stripped = preg_replace(
			pattern: '#<(style|script)\b[^>]*>.*?</\1>#is',
			replacement: '',
			subject: $html
		) ?? $html;

		$withBreaks = preg_replace(
			pattern: '#</?(br|p|div|section|article|h[1-6]|li|tr)[^>]*>#i',
			replacement: "\n",
			subject: $stripped
		) ?? $stripped;

		$text = strip_tags(string: $withBreaks);
		$text = html_entity_decode(string: $text, flags: (ENT_QUOTES | ENT_HTML5), encoding: 'UTF-8');

		// Collapse whitespace runs.
		$text = preg_replace(pattern: '/[\t ]+/', replacement: ' ', subject: $text) ?? $text;
		$text = preg_replace(pattern: "/\n{3,}/", replacement: "\n\n", subject: $text) ?? $text;

		return trim(string: $text);
	}//end htmlToText()
}//end class

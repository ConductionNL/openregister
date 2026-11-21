<?php

/**
 * OpenRegister RedactionPdfRouteReader
 *
 * Splits a written PDF into the regions each leak route lives in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The regions of a PDF, one per leak route, lower-cased.
 *
 * Compressed (Flate) streams are inflated in place first, because text under
 * a mark, an XMP packet or an attached file usually sits in a compressed
 * stream and a search of the raw bytes would never see it. A region that
 * cannot be isolated stays empty only where the route has no structure in
 * the file; text under the mark always searches the whole file.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-written-copy-cannot-be-read-back-req-rrs-001-carried-from-filinq-req-rwb-01
 */
final class RedactionPdfRouteReader {

	use RedactionRegionCutter;

	/**
	 * The tokens whose objects hold annotations and form fields.
	 */
	private const ANNOTATION_TOKENS = ['/annot', '/acroform', '/ft ', '/ft/'];

	/**
	 * The tokens whose objects hold attached files.
	 */
	private const ATTACHMENT_TOKENS = ['/embeddedfile', '/filespec'];

	/**
	 * The regions of a PDF, or null when the bytes are not a PDF.
	 *
	 * @param string $bytes The written file.
	 *
	 * @return array<string, string>|null The region of each route.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
	 */
	public function regions(string $bytes): ?array {
		if (str_starts_with(ltrim($bytes, "\xEF\xBB\xBF \t\r\n"), '%PDF-') === false) {
			return null;
		}

		$lower   = strtolower($this->inflated(bytes: $bytes));
		$objects = $this->objects(lower: $lower);

		return [
			RedactionLeakRoute::TEXT_UNDER_MARK => $lower,
			RedactionLeakRoute::EMBEDDED_PREVIEW => $this->previews(lower: $lower, objects: $objects),
			RedactionLeakRoute::XMP => $this->between(haystack: $lower, start: '<x:xmpmeta', end: '</x:xmpmeta>'),
			RedactionLeakRoute::EXIF => $this->properties(lower: $lower, objects: $objects),
			RedactionLeakRoute::INCREMENTAL_UPDATE => $this->earlierRevisions(lower: $lower),
			RedactionLeakRoute::ANNOTATIONS_AND_FIELDS => $this->containing(objects: $objects, tokens: self::ANNOTATION_TOKENS),
			RedactionLeakRoute::EMBEDDED_ATTACHMENT => $this->containing(objects: $objects, tokens: self::ATTACHMENT_TOKENS),
		];
	}//end regions()

	/**
	 * The file with every stream that inflates replaced by its inflated form.
	 *
	 * @param string $bytes The written file.
	 *
	 * @return string The file, streams inflated where possible.
	 */
	private function inflated(string $bytes): string {
		// A stream that is not Flate data makes zlib warn; that is an answer
		// (leave the stream as it is), not an error.
		set_error_handler(static fn (): bool => true);
		try {
			$out = preg_replace_callback(
				'/stream\r?\n(.*?)\r?\n?endstream/s',
				static function (array $match): string {
					$decoded = gzuncompress($match[1]);
					if ($decoded === false) {
						$decoded = gzinflate($match[1]);
					}

					if ($decoded === false) {
						return $match[0];
					}

					return "stream\n" . $decoded . "\nendstream";
				},
				$bytes
			);
		} finally {
			restore_error_handler();
		}

		return ($out ?? $bytes);
	}//end inflated()

	/**
	 * Every indirect object, keyed by its number and generation.
	 *
	 * @param string $lower The lower-cased file.
	 *
	 * @return array<string, string> The objects.
	 */
	private function objects(string $lower): array {
		$objects = [];
		if (preg_match_all('/(\d+)\s+(\d+)\s+obj\b(.*?)endobj/s', $lower, $matches, PREG_SET_ORDER) > 0) {
			foreach ($matches as $match) {
				$objects[$match[1] . ' ' . $match[2]] = $match[0];
			}
		}

		return $objects;
	}//end objects()

	/**
	 * The objects that hold a token, and the objects they point at by that token.
	 *
	 * @param array<string, string> $objects The objects.
	 * @param list<string>          $tokens  The tokens.
	 *
	 * @return string The concatenated objects.
	 */
	private function containing(array $objects, array $tokens): string {
		$out = '';
		foreach ($objects as $object) {
			foreach ($tokens as $token) {
				if (str_contains($object, $token) === true) {
					$out .= $object;
					break;
				}
			}
		}

		return $out;
	}//end containing()

	/**
	 * The thumbnails: the objects a `/Thumb` entry points at.
	 *
	 * @param string                $lower   The lower-cased file.
	 * @param array<string, string> $objects The objects.
	 *
	 * @return string The thumbnail objects.
	 */
	private function previews(string $lower, array $objects): string {
		return $this->referenced(lower: $lower, objects: $objects, key: '/thumb');
	}//end previews()

	/**
	 * The document properties: the `/Info` dictionary and any EXIF segment.
	 *
	 * @param string                $lower   The lower-cased file.
	 * @param array<string, string> $objects The objects.
	 *
	 * @return string The properties.
	 */
	private function properties(string $lower, array $objects): string {
		return $this->referenced(lower: $lower, objects: $objects, key: '/info')
			. $this->between(haystack: $lower, start: '/info <<', end: '>>')
			. $this->between(haystack: $lower, start: '/info<<', end: '>>')
			. $this->between(haystack: $lower, start: "exif\x00", end: 'endstream');
	}//end properties()

	/**
	 * The objects an indirect reference under a key points at.
	 *
	 * @param string                $lower   The lower-cased file.
	 * @param array<string, string> $objects The objects.
	 * @param string                $key     The key, such as `/thumb`.
	 *
	 * @return string The referenced objects.
	 */
	private function referenced(string $lower, array $objects, string $key): string {
		$out = '';
		if (preg_match_all('#' . preg_quote($key, '#') . '\s*(\d+)\s+(\d+)\s+r\b#', $lower, $matches, PREG_SET_ORDER) > 0) {
			foreach ($matches as $match) {
				$out .= ($objects[$match[1] . ' ' . $match[2]] ?? '');
			}
		}

		return $out;
	}//end referenced()

	/**
	 * Everything before the last end-of-file marker, when there is more than one.
	 *
	 * More than one `%%EOF` means an incremental save kept earlier revisions.
	 *
	 * @param string $lower The lower-cased file.
	 *
	 * @return string The earlier revisions, or an empty string.
	 */
	private function earlierRevisions(string $lower): string {
		if (substr_count($lower, '%%eof') <= 1) {
			return '';
		}

		return substr($lower, 0, (int) strrpos($lower, '%%eof'));
	}//end earlierRevisions()
}//end class

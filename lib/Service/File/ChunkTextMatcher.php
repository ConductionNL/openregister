<?php

/**
 * OpenRegister ChunkTextMatcher.
 *
 * Pure utility: finds every occurrence of a needle across a file's
 * extracted text chunks, with overlap-aware absolute-position dedup.
 *
 * Per the `manual-entity-anonymisation` design (§D2):
 *   - per-chunk preg_match_all (no concatenation — overlap would
 *     double-count),
 *   - absolute-position dedup (overlap regions naturally produce
 *     duplicate per-chunk matches; collapse to one canonical entry,
 *     keeping the lowest chunkIndex for determinism),
 *   - sorted by absolute start position.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Exception\ChunkMatcherException;

/**
 * Find every occurrence of an operator-supplied needle in a file's chunks.
 *
 * Stateless; safe to inject as a singleton. All public methods take
 * their full context as parameters.
 */
class ChunkTextMatcher {
	/**
	 * Number of context characters captured on each side of every match.
	 */
	private const CONTEXT_WINDOW = 30;

	/**
	 * Find every occurrence of `$needle` in `$chunks`, deduped by
	 * absolute position across chunk boundaries.
	 *
	 * @param Chunk[] $chunks Chunks for the target file, ordered by chunkIndex.
	 * @param string $needle Operator-supplied value to match.
	 * @param bool $wholeWord Wrap the needle in `\b...\b` regex boundaries when true.
	 * @param bool $caseSensitive Use case-sensitive matching when true.
	 * @param int $chunkOverlap Configured chunk overlap (chars). Needles longer than this
	 *                          cannot reliably be matched per-chunk and are rejected.
	 *
	 * @return array<int, array{chunkId: int, positionStart: int, positionEnd: int, context: string}>
	 *                                                                                                Matches in document order. Empty when no match found.
	 *
	 * @throws ChunkMatcherException When the needle is longer than `$chunkOverlap` or the
	 *                               regex pattern fails to compile.
	 */
	public function match(
		array $chunks,
		string $needle,
		bool $wholeWord,
		bool $caseSensitive,
		int $chunkOverlap,
	): array {
		if ($needle === '') {
			return [];
		}

		$needleLength = strlen($needle);
		if ($needleLength > $chunkOverlap) {
			// The needle is longer than the configured chunk overlap.
			// A match could straddle a chunk boundary in a way that fits
			// in neither chunk's tail nor head — per-chunk regex would
			// miss it. Rejecting is safer than silently under-matching.
			throw new ChunkMatcherException(
				reason: ChunkMatcherException::REASON_VALUE_TOO_LONG,
				message: sprintf(
					'Needle length (%d bytes) exceeds chunk overlap (%d).',
					$needleLength,
					$chunkOverlap
				)
			);
		}

		if (empty($chunks) === true) {
			return [];
		}

		$pattern = $this->buildPattern(
			needle: $needle,
			wholeWord: $wholeWord,
			caseSensitive: $caseSensitive
		);

		// Per-chunk regex pass. Collect (absoluteStart, absoluteEnd, chunkId,
		// chunkIndex, chunkRelativeStart) for every hit; the dedup pass below
		// collapses overlap-region duplicates.
		$hits = [];
		foreach ($chunks as $chunk) {
			$this->collectHits(
				chunk: $chunk,
				pattern: $pattern,
				needleLength: $needleLength,
				hits: $hits
			);
		}

		if (empty($hits) === true) {
			return [];
		}

		// Dedup by (absoluteStart, absoluteEnd), keeping the entry from
		// the LOWEST chunkIndex. This is deterministic — re-runs select
		// the same canonical chunk for each unique absolute position,
		// so `existsForFileAtPosition` lookups on subsequent calls hit
		// the same (chunkId, positionStart, positionEnd) tuple.
		$canonical = $this->dedupByAbsolutePosition(hits: $hits);

		// Sort by absoluteStart for document-order output.
		usort(
			$canonical,
			static fn (array $a, array $b): int => ($a['absoluteStart'] <=> $b['absoluteStart'])
		);

		// Map to the public output shape.
		$result = [];
		foreach ($canonical as $hit) {
			$result[] = [
				'chunkId' => $hit['chunkId'],
				'positionStart' => $hit['chunkRelativeStart'],
				'positionEnd' => ($hit['chunkRelativeStart'] + $needleLength),
				'context' => $hit['context'],
			];
		}

		return $result;
	}//end match()

	/**
	 * Build the regex pattern from the needle + match options.
	 *
	 * @param string $needle Raw needle.
	 * @param bool $wholeWord Wrap in `\b...\b` when true.
	 * @param bool $caseSensitive Use the `i` modifier when false.
	 *
	 * @return string Compiled pattern ready for preg_match_all.
	 *
	 * @throws ChunkMatcherException On regex compile failure (malformed Unicode in needle).
	 */
	private function buildPattern(string $needle, bool $wholeWord, bool $caseSensitive): string {
		$quoted = preg_quote($needle, '/');
		if ($wholeWord === true) {
			$quoted = '\b' . $quoted . '\b';
		}

		$flags = 'u';
		if ($caseSensitive === false) {
			$flags .= 'i';
		}

		$pattern = '/' . $quoted . '/' . $flags;

		// Probe the pattern with a zero-byte string to surface compile
		// failures early (malformed Unicode in the needle is the realistic
		// trigger).
		$probe = @preg_match($pattern, '');
		if ($probe === false) {
			throw new ChunkMatcherException(
				reason: ChunkMatcherException::REASON_REGEX_COMPILE_FAILURE,
				message: 'Regex pattern failed to compile (malformed needle).'
			);
		}

		return $pattern;
	}//end buildPattern()

	/**
	 * Run preg_match_all against one chunk and accumulate hits.
	 *
	 * `$hits` carries entries of shape
	 * `{absoluteStart: int, absoluteEnd: int, chunkId: int, chunkIndex: int, chunkRelativeStart: int, context: string}`.
	 *
	 * @param Chunk $chunk Source chunk.
	 * @param string $pattern Compiled regex pattern.
	 * @param int $needleLength Pre-computed needle length in bytes.
	 * @param array $hits Hits accumulator (mutated by reference).
	 *
	 * @return void
	 */
	private function collectHits(Chunk $chunk, string $pattern, int $needleLength, array &$hits): void {
		$text = $chunk->getTextContent();
		$matches = [];
		$count = preg_match_all(
			pattern: $pattern,
			subject: $text,
			matches: $matches,
			flags: (PREG_OFFSET_CAPTURE | PREG_SET_ORDER)
		);

		if ($count === false || $count === 0) {
			return;
		}

		$startOffset = $chunk->getStartOffset();
		$chunkIndex = $chunk->getChunkIndex();
		$chunkId = (int)$chunk->getId();

		foreach ($matches as $match) {
			$chunkRelativeStart = (int)$match[0][1];
			$absoluteStart = ($startOffset + $chunkRelativeStart);
			$absoluteEnd = ($absoluteStart + $needleLength);

			$hits[] = [
				'absoluteStart' => $absoluteStart,
				'absoluteEnd' => $absoluteEnd,
				'chunkId' => $chunkId,
				'chunkIndex' => $chunkIndex,
				'chunkRelativeStart' => $chunkRelativeStart,
				'context' => $this->extractContext(
					text: $text,
					relativeStart: $chunkRelativeStart,
					needleLength: $needleLength
				),
			];
		}

	}//end collectHits()

	/**
	 * Collapse multiple per-chunk hits at the same absolute position
	 * to one entry, keeping the lowest chunkIndex.
	 *
	 * Entry shape: `{absoluteStart, absoluteEnd, chunkId, chunkIndex,
	 * chunkRelativeStart, context}` (all keys present).
	 *
	 * @param array $hits Raw per-chunk hits.
	 *
	 * @return array Deduped hits with the same entry shape.
	 */
	private function dedupByAbsolutePosition(array $hits): array {
		$byKey = [];
		foreach ($hits as $hit) {
			$key = $hit['absoluteStart'] . ':' . $hit['absoluteEnd'];
			if (isset($byKey[$key]) === false) {
				$byKey[$key] = $hit;
				continue;
			}

			// Tiebreak: keep the entry from the lowest chunkIndex.
			if ($hit['chunkIndex'] < $byKey[$key]['chunkIndex']) {
				$byKey[$key] = $hit;
			}
		}

		return array_values($byKey);
	}//end dedupByAbsolutePosition()

	/**
	 * Return ~CONTEXT_WINDOW chars on either side of the match.
	 *
	 * @param string $text Source chunk text.
	 * @param int $relativeStart Match start offset within the chunk.
	 * @param int $needleLength Needle length in bytes.
	 *
	 * @return string Snippet with the match in the middle.
	 */
	private function extractContext(string $text, int $relativeStart, int $needleLength): string {
		$start = max(0, ($relativeStart - self::CONTEXT_WINDOW));
		$end = min(strlen($text), ($relativeStart + $needleLength + self::CONTEXT_WINDOW));
		$length = ($end - $start);

		return substr($text, $start, $length);
	}//end extractContext()
}//end class

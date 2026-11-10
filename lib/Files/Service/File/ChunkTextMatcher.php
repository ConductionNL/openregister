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

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Exception\ChunkMatcherException;

/**
 * Find every occurrence of an operator-supplied needle in a file's chunks.
 *
 * Stateless; safe to inject as a singleton. All public methods take
 * their full context as parameters.
 */
class ChunkTextMatcher {
	/**
	 * Find every occurrence of the searched value in the chunks, deduped by
	 * absolute position across chunk boundaries, in document order.
	 *
	 * @param ChunkSearch $search The chunks, the value with its match options and the chunk overlap.
	 *
	 * @return array<int, array{chunkId: int, positionStart: int, positionEnd: int, context: string}>
	 *
	 * @throws ChunkMatcherException When the needle is longer than the chunk overlap or the
	 *                               regex pattern fails to compile.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function search(ChunkSearch $search): array {
		if ($search->request->value === '') {
			return [];
		}

		$this->assertFitsOverlap(needle: $search->request->value, chunkOverlap: $search->chunkOverlap);
		if (empty($search->chunks) === true) {
			return [];
		}

		// Per-chunk regex pass; the run collects every hit and the dedup
		// pass collapses overlap-region duplicates.
		$run = new ChunkMatchRun(pattern: $this->buildPattern(request: $search->request), needleLength: strlen($search->request->value));
		foreach ($search->chunks as $chunk) {
			$this->collectHits(run: $run, chunk: $chunk);
		}

		return $this->matchesOf(run: $run);
	}//end search()

	/**
	 * Refuse a needle longer than the configured chunk overlap.
	 *
	 * A match could straddle a chunk boundary in a way that fits in neither
	 * chunk's tail nor head; per-chunk regex would miss it. Rejecting is
	 * safer than silently under-matching.
	 *
	 * @param string $needle       The value to match.
	 * @param int    $chunkOverlap Configured chunk overlap (chars).
	 *
	 * @return void
	 *
	 * @throws ChunkMatcherException When the needle is longer than the overlap.
	 */
	private function assertFitsOverlap(string $needle, int $chunkOverlap): void {
		$needleLength = strlen($needle);
		if ($needleLength > $chunkOverlap) {
			throw new ChunkMatcherException(
				reason: ChunkMatcherException::REASON_VALUE_TOO_LONG,
				message: sprintf('Needle length (%d bytes) exceeds chunk overlap (%d).', $needleLength, $chunkOverlap)
			);
		}
	}//end assertFitsOverlap()

	/**
	 * The run's hits as the public output: deduped, in document order.
	 *
	 * Dedup by (absoluteStart, absoluteEnd) keeps the entry from the LOWEST
	 * chunkIndex, so re-runs select the same canonical chunk for each unique
	 * absolute position and `existsForFileAtPosition` lookups hit the same
	 * (chunkId, positionStart, positionEnd) tuple.
	 *
	 * @param ChunkMatchRun $run The finished run.
	 *
	 * @return array<int, array{chunkId: int, positionStart: int, positionEnd: int, context: string}>
	 */
	private function matchesOf(ChunkMatchRun $run): array {
		$canonical = $this->dedupByAbsolutePosition(hits: $run->hits);
		usort(
			$canonical,
			static fn (array $a, array $b): int => ($a['absoluteStart'] <=> $b['absoluteStart'])
		);

		$result = [];
		foreach ($canonical as $hit) {
			$result[] = [
				'chunkId' => $hit['chunkId'],
				'positionStart' => $hit['chunkRelativeStart'],
				'positionEnd' => ($hit['chunkRelativeStart'] + $run->needleLength),
				'context' => $hit['context'],
			];
		}

		return $result;
	}//end matchesOf()

	/**
	 * Build the regex pattern from the needle + match options.
	 *
	 * Whole-word matching wraps the needle in `\b...\b`; case-insensitive
	 * matching adds the `i` modifier.
	 *
	 * @param ManualEntityRequest $request The raw needle and its match options.
	 *
	 * @return string Compiled pattern ready for preg_match_all.
	 *
	 * @throws ChunkMatcherException On regex compile failure (malformed Unicode in needle).
	 */
	private function buildPattern(ManualEntityRequest $request): string {
		$quoted = preg_quote($request->value, '/');
		if ($request->wholeWord() === true) {
			$quoted = '\b' . $quoted . '\b';
		}

		$flags = 'u';
		if ($request->caseSensitive() === false) {
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
	 * Run preg_match_all against one chunk and add its hits to the run.
	 *
	 * @param ChunkMatchRun $run   The run: pattern, needle length and hits so far.
	 * @param Chunk         $chunk Source chunk.
	 *
	 * @return void
	 */
	private function collectHits(ChunkMatchRun $run, Chunk $chunk): void {
		$text = $chunk->getTextContent();
		$matches = [];
		$count = preg_match_all(
			pattern: $run->pattern,
			subject: $text,
			matches: $matches,
			flags: (PREG_OFFSET_CAPTURE | PREG_SET_ORDER)
		);

		if ($count === false || $count === 0) {
			return;
		}

		foreach ($matches as $match) {
			$chunkRelativeStart = (int)$match[0][1];
			$absoluteStart = ($chunk->getStartOffset() + $chunkRelativeStart);

			$run->hits[] = [
				'absoluteStart' => $absoluteStart,
				'absoluteEnd' => ($absoluteStart + $run->needleLength),
				'chunkId' => (int)$chunk->getId(),
				'chunkIndex' => $chunk->getChunkIndex(),
				'chunkRelativeStart' => $chunkRelativeStart,
				'context' => $run->contextAround(text: $text, relativeStart: $chunkRelativeStart),
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
}//end class

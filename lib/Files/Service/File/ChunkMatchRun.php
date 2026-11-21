<?php

/**
 * OpenRegister ChunkMatchRun.
 *
 * State of one ChunkTextMatcher::match() run: the compiled pattern, the
 * needle length and the per-chunk hits collected so far.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * One chunk-match run; never leaves ChunkTextMatcher.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class ChunkMatchRun {
	/**
	 * Number of context characters captured on each side of every match.
	 */
	private const CONTEXT_WINDOW = 30;

	/**
	 * Hits collected so far, one per per-chunk match.
	 *
	 * @var array<int, array{absoluteStart: int, absoluteEnd: int, chunkId: int, chunkIndex: int, chunkRelativeStart: int, context: string}>
	 */
	public array $hits = [];

	/**
	 * Constructor.
	 *
	 * @param string $pattern      Compiled regex pattern.
	 * @param int    $needleLength Needle length in bytes.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly string $pattern,
		public readonly int $needleLength,
	) {
	}//end __construct()

	/**
	 * About CONTEXT_WINDOW bytes on either side of a match of this run's needle.
	 *
	 * @param string $text          Source chunk text.
	 * @param int    $relativeStart Match start offset within the chunk.
	 *
	 * @return string Snippet with the match in the middle.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function contextAround(string $text, int $relativeStart): string {
		$start = max(0, ($relativeStart - self::CONTEXT_WINDOW));
		$end = min(strlen($text), ($relativeStart + $this->needleLength + self::CONTEXT_WINDOW));

		return substr($text, $start, ($end - $start));
	}//end contextAround()
}//end class

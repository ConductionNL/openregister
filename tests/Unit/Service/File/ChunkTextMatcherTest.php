<?php

/**
 * Unit tests for `ChunkTextMatcher`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests\Unit\Service\File
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\Chunk;
use OCA\OpenRegister\Exception\ChunkMatcherException;
use OCA\OpenRegister\Service\File\ChunkTextMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Covers the algorithm-level invariants of the chunk-aware matcher.
 *
 * Each scenario builds a small set of `Chunk` entities with explicit
 * `startOffset` / `chunkIndex` so the dedup behaviour can be asserted
 * deterministically.
 */
class ChunkTextMatcherTest extends TestCase {
	private const CHUNK_OVERLAP = 200;

	/**
	 * Matcher SUT.
	 *
	 * @var ChunkTextMatcher
	 */
	private ChunkTextMatcher $matcher;

	/**
	 * Boot a fresh matcher for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->matcher = new ChunkTextMatcher();

	}//end setUp()

	/**
	 * Build a `Chunk` entity with the fields the matcher consults.
	 *
	 * @param int $id Chunk row id (used for the result tuple).
	 * @param string $textContent Chunk text.
	 * @param int $startOffset Absolute byte offset of the chunk in the source text.
	 * @param int $chunkIndex Ordering field (used for dedup tiebreak).
	 *
	 * @return Chunk
	 */
	private function makeChunk(int $id, string $textContent, int $startOffset, int $chunkIndex): Chunk {
		$chunk = new Chunk();
		$chunk->setId($id);
		$chunk->setTextContent($textContent);
		$chunk->setStartOffset($startOffset);
		$chunk->setChunkIndex($chunkIndex);
		return $chunk;
	}//end makeChunk()

	/**
	 * Single-chunk single-match: the simplest case.
	 *
	 * @return void
	 */
	public function testSingleChunkMatch(): void {
		$chunk = $this->makeChunk(
			id: 10,
			textContent: 'Aanvraag van Jan Jansen voor het loket.',
			startOffset: 0,
			chunkIndex: 0
		);

		$matches = $this->matcher->match(
			chunks: [$chunk],
			needle: 'Jan Jansen',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertCount(expectedCount: 1, haystack: $matches);
		$this->assertSame(expected: 10, actual: $matches[0]['chunkId']);
		$this->assertSame(expected: 13, actual: $matches[0]['positionStart']);
		$this->assertSame(expected: 23, actual: $matches[0]['positionEnd']);
		$this->assertStringContainsString(needle: 'Jan Jansen', haystack: $matches[0]['context']);

	}//end testSingleChunkMatch()

	/**
	 * Three occurrences in the same chunk — all returned, in order.
	 *
	 * @return void
	 */
	public function testSingleChunkMultipleMatches(): void {
		$chunk = $this->makeChunk(
			id: 11,
			textContent: 'Jan, hallo Jan, en nogmaals Jan.',
			startOffset: 0,
			chunkIndex: 0
		);

		$matches = $this->matcher->match(
			chunks: [$chunk],
			needle: 'Jan',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertCount(expectedCount: 3, haystack: $matches);
		$this->assertSame(expected: 0, actual: $matches[0]['positionStart']);
		$this->assertSame(expected: 11, actual: $matches[1]['positionStart']);
		$this->assertSame(expected: 28, actual: $matches[2]['positionStart']);

	}//end testSingleChunkMultipleMatches()

	/**
	 * Overlap-region dedup: the same absolute position appears in
	 * two adjacent chunks' overlap region. The matcher must collapse
	 * to one canonical entry, keeping the lower `chunkIndex`.
	 *
	 * @return void
	 */
	public function testOverlapRegionDedup(): void {
		// Chunk 0 (offsets 0-99) and chunk 1 (offsets 80-179) overlap
		// on bytes 80-99. The needle "Jansen" lives at absolute
		// position 85 → in chunk 0 it's at relative offset 85, in
		// chunk 1 it's at relative offset 5.
		$chunk0 = $this->makeChunk(
			id: 20,
			textContent: str_pad(string: 'X', length: 85, pad_string: ' ') . 'Jansen woont in DenHaag.',
			startOffset: 0,
			chunkIndex: 0
		);
		$chunk1 = $this->makeChunk(
			id: 21,
			textContent: 'XXXX Jansen woont in DenHaag.',
			startOffset: 80,
			chunkIndex: 1
		);

		$matches = $this->matcher->match(
			chunks: [$chunk0, $chunk1],
			needle: 'Jansen',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		// Exactly one match: the dedup kept chunk 0 (lowest chunkIndex)
		// and dropped chunk 1's copy at the same absolute position.
		$this->assertCount(expectedCount: 1, haystack: $matches);
		$this->assertSame(expected: 20, actual: $matches[0]['chunkId']);
		$this->assertSame(expected: 85, actual: $matches[0]['positionStart']);

	}//end testOverlapRegionDedup()

	/**
	 * Distinct matches in non-overlap regions across two chunks are
	 * both kept.
	 *
	 * @return void
	 */
	public function testDistinctMatchesAcrossChunks(): void {
		$chunk0 = $this->makeChunk(
			id: 30,
			textContent: 'Eerste regel met Anna in chunk een.',
			startOffset: 0,
			chunkIndex: 0
		);
		$chunk1 = $this->makeChunk(
			id: 31,
			textContent: 'Tweede regel met Anna in chunk twee.',
			startOffset: 500,
			chunkIndex: 1
		);

		$matches = $this->matcher->match(
			chunks: [$chunk0, $chunk1],
			needle: 'Anna',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertCount(expectedCount: 2, haystack: $matches);
		$this->assertSame(expected: 30, actual: $matches[0]['chunkId']);
		$this->assertSame(expected: 31, actual: $matches[1]['chunkId']);

	}//end testDistinctMatchesAcrossChunks()

	/**
	 * Re-running with the same inputs returns the same canonical
	 * `chunkId` selection (lowest chunkIndex for each unique absolute
	 * position). This is the precondition for the
	 * `existsForFileAtPosition` idempotency check on retry.
	 *
	 * @return void
	 */
	public function testDeterministicOnRerun(): void {
		$chunk0 = $this->makeChunk(
			id: 40,
			textContent: str_pad(string: 'X', length: 85, pad_string: ' ') . 'Jansen',
			startOffset: 0,
			chunkIndex: 0
		);
		$chunk1 = $this->makeChunk(
			id: 41,
			textContent: 'XXXX Jansen',
			startOffset: 80,
			chunkIndex: 1
		);

		$first = $this->matcher->match(
			chunks: [$chunk0, $chunk1],
			needle: 'Jansen',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);
		$second = $this->matcher->match(
			chunks: [$chunk0, $chunk1],
			needle: 'Jansen',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertSame(expected: $first, actual: $second);

	}//end testDeterministicOnRerun()

	/**
	 * Whole-word default rejects substring matches inside larger words.
	 *
	 * @return void
	 */
	public function testWholeWordRejectsSubstring(): void {
		$chunk = $this->makeChunk(
			id: 50,
			textContent: 'The Janitor opened the door in January.',
			startOffset: 0,
			chunkIndex: 0
		);

		$matches = $this->matcher->match(
			chunks: [$chunk],
			needle: 'Jan',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertSame(expected: [], actual: $matches);

	}//end testWholeWordRejectsSubstring()

	/**
	 * Disabling the whole-word flag finds substring matches.
	 *
	 * @return void
	 */
	public function testWholeWordFalseFindsSubstring(): void {
		$chunk = $this->makeChunk(
			id: 51,
			textContent: 'Janitor and January',
			startOffset: 0,
			chunkIndex: 0
		);

		$matches = $this->matcher->match(
			chunks: [$chunk],
			needle: 'Jan',
			wholeWord: false,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertCount(expectedCount: 2, haystack: $matches);

	}//end testWholeWordFalseFindsSubstring()

	/**
	 * Case-sensitive default rejects mismatched-case matches.
	 *
	 * @return void
	 */
	public function testCaseSensitiveRejectsMismatchedCase(): void {
		$chunk = $this->makeChunk(
			id: 60,
			textContent: 'Hallo jan jansen.',
			startOffset: 0,
			chunkIndex: 0
		);

		$matches = $this->matcher->match(
			chunks: [$chunk],
			needle: 'Jan',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertSame(expected: [], actual: $matches);

	}//end testCaseSensitiveRejectsMismatchedCase()

	/**
	 * Disabling case sensitivity finds mismatched-case matches.
	 *
	 * @return void
	 */
	public function testCaseInsensitiveFindsMismatchedCase(): void {
		$chunk = $this->makeChunk(
			id: 61,
			textContent: 'Hallo jan jansen.',
			startOffset: 0,
			chunkIndex: 0
		);

		$matches = $this->matcher->match(
			chunks: [$chunk],
			needle: 'Jan',
			wholeWord: true,
			caseSensitive: false,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertCount(expectedCount: 1, haystack: $matches);

	}//end testCaseInsensitiveFindsMismatchedCase()

	/**
	 * Zero matches returns an empty array, not an exception.
	 *
	 * @return void
	 */
	public function testZeroMatches(): void {
		$chunk = $this->makeChunk(
			id: 70,
			textContent: 'There is nothing matching here.',
			startOffset: 0,
			chunkIndex: 0
		);

		$matches = $this->matcher->match(
			chunks: [$chunk],
			needle: 'Jan Jansen',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertSame(expected: [], actual: $matches);

	}//end testZeroMatches()

	/**
	 * Empty chunk list returns an empty array.
	 *
	 * @return void
	 */
	public function testEmptyChunkList(): void {
		$matches = $this->matcher->match(
			chunks: [],
			needle: 'Jan',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertSame(expected: [], actual: $matches);

	}//end testEmptyChunkList()

	/**
	 * Needle longer than the chunk overlap throws the typed
	 * `value_too_long` exception. The message MUST NOT contain the
	 * needle itself per ADR-005.
	 *
	 * @return void
	 */
	public function testNeedleExceedingOverlapThrows(): void {
		$longNeedle = str_repeat(string: 'A', times: 201);

		try {
			$this->matcher->match(
				chunks: [],
				needle: $longNeedle,
				wholeWord: true,
				caseSensitive: true,
				chunkOverlap: self::CHUNK_OVERLAP
			);
			$this->fail(message: 'Expected ChunkMatcherException');
		} catch (ChunkMatcherException $e) {
			$this->assertSame(
				expected: ChunkMatcherException::REASON_VALUE_TOO_LONG,
				actual: $e->getReason()
			);
			$this->assertStringNotContainsString(needle: $longNeedle, haystack: $e->getMessage());
		}

	}//end testNeedleExceedingOverlapThrows()

	/**
	 * Empty needle returns empty without invoking the regex compile
	 * step — preg_quote on an empty string would otherwise produce
	 * an empty pattern that matches every position.
	 *
	 * @return void
	 */
	public function testEmptyNeedleReturnsEmpty(): void {
		$chunk = $this->makeChunk(
			id: 80,
			textContent: 'Anything.',
			startOffset: 0,
			chunkIndex: 0
		);

		$matches = $this->matcher->match(
			chunks: [$chunk],
			needle: '',
			wholeWord: true,
			caseSensitive: true,
			chunkOverlap: self::CHUNK_OVERLAP
		);

		$this->assertSame(expected: [], actual: $matches);

	}//end testEmptyNeedleReturnsEmpty()
}//end class

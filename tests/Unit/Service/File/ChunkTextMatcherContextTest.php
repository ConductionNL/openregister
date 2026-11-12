<?php

/**
 * ChunkTextMatcherContextTest
 *
 * Characterisation of the context window ChunkTextMatcher returns around
 * each match, and of the exact output shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
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

namespace Unit\Service\File;

use OCA\OpenRegister\Files\Db\Chunk;
use OCA\OpenRegister\Files\Service\File\ChunkSearch;
use OCA\OpenRegister\Files\Service\File\ChunkTextMatcher;
use OCA\OpenRegister\Files\Service\File\ManualEntityRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Context windows and output shape of {@see ChunkTextMatcher::match()}.
 */
class ChunkTextMatcherContextTest extends TestCase {

	/**
	 * A chunk with the given text and offset.
	 *
	 * @param int    $id          Row id.
	 * @param string $text        Chunk text.
	 * @param int    $startOffset Absolute offset.
	 * @param int    $chunkIndex  Order.
	 *
	 * @return Chunk
	 */
	private function chunk(int $id, string $text, int $startOffset, int $chunkIndex): Chunk {
		$chunk = new Chunk();
		$chunk->setId($id);
		$chunk->setTextContent($text);
		$chunk->setStartOffset($startOffset);
		$chunk->setChunkIndex($chunkIndex);
		return $chunk;
	}//end chunk()

	/**
	 * A case-insensitive substring request.
	 *
	 * @param string $needle The value.
	 *
	 * @return ManualEntityRequest
	 */
	private function request(string $needle): ManualEntityRequest {
		return (new ManualEntityRequest(fileId: 1, value: $needle, type: 'PERSON', actor: $this->createMock(IUser::class)))
			->withMatching(wholeWord: false, caseSensitive: false);
	}//end request()

	/**
	 * Windows are 30 bytes each side, clipped at the chunk edges; output is in document order.
	 *
	 * @return void
	 */
	public function testContextWindowsAndShape(): void {
		$long = str_repeat('a', 40) . 'XY' . str_repeat('b', 40);
		$matches = (new ChunkTextMatcher())->search(new ChunkSearch(
			chunks: [
				$this->chunk(7, $long, 100, 1),
				$this->chunk(5, 'xy at start', 0, 0),
			],
			request: $this->request('xy'),
			chunkOverlap: 10
		));

		self::assertSame(
			[
				['chunkId' => 5, 'positionStart' => 0, 'positionEnd' => 2, 'context' => 'xy at start'],
				['chunkId' => 7, 'positionStart' => 40, 'positionEnd' => 42, 'context' => str_repeat('a', 30) . 'XY' . str_repeat('b', 30)],
			],
			$matches
		);
	}//end testContextWindowsAndShape()

	/**
	 * A needle exactly as long as the overlap is still accepted.
	 *
	 * @return void
	 */
	public function testNeedleAsLongAsOverlapIsAccepted(): void {
		$matches = (new ChunkTextMatcher())->search(new ChunkSearch(
			chunks: [$this->chunk(1, 'abc', 0, 0)],
			request: $this->request('abc'),
			chunkOverlap: 3
		));

		self::assertCount(1, $matches);
	}//end testNeedleAsLongAsOverlapIsAccepted()
}//end class

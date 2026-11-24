<?php

/**
 * Tests for the scoped file content search.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File;

use OCA\OpenRegister\Files\Db\FileChunkSearch;
use OCA\OpenRegister\Service\File\ScopedContentSearchService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An app searches the content of the files one user may read (REQ-SFCS-001).
 */
class ScopedContentSearchServiceTest extends TestCase {

	/**
	 * The file ids the user's folder resolves, readable or not.
	 *
	 * @var array<int, bool>
	 */
	private array $nodes = [11 => true, 12 => true, 14 => false];

	/**
	 * The file ids that have chunks.
	 *
	 * @var list<int>
	 */
	private array $withChunks = [11, 12];

	/**
	 * The chunks the search answers.
	 *
	 * @var list<array{fileId: int, rank: float, text: string}>
	 */
	private array $rows = [];

	/**
	 * The ids the chunk search was asked about.
	 *
	 * @var list<list<int>>
	 */
	private array $searched = [];

	/**
	 * The service over doubled chunks and files.
	 *
	 * @param bool $userExists Whether the user's folder resolves.
	 *
	 * @return ScopedContentSearchService
	 */
	private function service(bool $userExists = true): ScopedContentSearchService {
		$chunks = $this->createMock(FileChunkSearch::class);
		$chunks->method('fileIdsWithChunks')->willReturnCallback(
			fn (array $fileIds): array => array_values(array_intersect(FileChunkSearch::fileIdList(fileIds: $fileIds), $this->withChunks))
		);
		$chunks->method('searchFileChunks')->willReturnCallback(
			function (string $words, array $fileIds, int $limit): array {
				$this->searched[] = $fileIds;
				return array_values(array_filter($this->rows, static fn (array $row): bool => in_array($row['fileId'], $fileIds, true)));
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->willReturnCallback(
			function (int $fileId): ?Node {
				if (isset($this->nodes[$fileId]) === false) {
					return null;
				}

				$node = $this->createMock(Node::class);
				$node->method('isReadable')->willReturn($this->nodes[$fileId]);
				return $node;
			}
		);

		$root = $this->createMock(IRootFolder::class);
		if ($userExists === true) {
			$root->method('getUserFolder')->willReturn($folder);
		} else {
			$root->method('getUserFolder')->willThrowException(new NotPermittedException('no such user'));
		}

		return new ScopedContentSearchService(chunks: $chunks, rootFolder: $root, logger: new NullLogger());
	}//end service()

	/**
	 * A word in a readable file is found once, with its best chunk marked.
	 *
	 * @return void
	 */
	public function testAWordInAReadableFileIsFoundOncePerFile(): void {
		$this->rows = [
			['fileId' => 11, 'rank' => 0.4, 'text' => 'De waterschapsheffing voor 2026.'],
			['fileId' => 11, 'rank' => 0.9, 'text' => 'Bezwaar tegen de Waterschapsheffing'],
		];

		$answer = $this->service()->searchFiles(words: 'waterschapsheffing', fileIds: [11, 12], userId: 'alice');

		self::assertCount(1, $answer['results']);
		self::assertSame(11, $answer['results'][0]['fileId']);
		self::assertSame(0.9, $answer['results'][0]['rank']);
		self::assertSame('Bezwaar tegen de <mark>Waterschapsheffing</mark>', $answer['results'][0]['snippet']);
		self::assertSame([], $answer['unextracted']);
	}//end testAWordInAReadableFileIsFoundOncePerFile()

	/**
	 * Hits are ordered best rank first.
	 *
	 * @return void
	 */
	public function testHitsAreOrderedByRank(): void {
		$this->rows = [
			['fileId' => 11, 'rank' => 0.2, 'text' => 'heffing'],
			['fileId' => 12, 'rank' => 0.7, 'text' => 'heffing'],
		];

		$answer = $this->service()->searchFiles(words: 'heffing', fileIds: [11, 12], userId: 'alice');

		self::assertSame([12, 11], array_column($answer['results'], 'fileId'));
	}//end testHitsAreOrderedByRank()

	/**
	 * A file that does not resolve, or is not readable, is never searched nor named.
	 *
	 * @return void
	 */
	public function testAFileTheUserCannotReadIsNeverSearched(): void {
		$this->withChunks = [11, 13, 14];
		$this->rows       = [['fileId' => 13, 'rank' => 1.0, 'text' => 'geheim'], ['fileId' => 14, 'rank' => 1.0, 'text' => 'geheim']];

		$answer = $this->service()->searchFiles(words: 'geheim', fileIds: [13, 14, '11', 'x', -3, 11], userId: 'alice');

		self::assertSame([[11]], $this->searched);
		self::assertSame([], $answer['results']);
		self::assertSame([], $answer['unextracted']);
	}//end testAFileTheUserCannotReadIsNeverSearched()

	/**
	 * Readable files without extracted text are named, and no search runs for them.
	 *
	 * @return void
	 */
	public function testFilesWithoutTextAreNamedAsUnextracted(): void {
		$this->withChunks = [];

		$answer = $this->service()->searchFiles(words: 'heffing', fileIds: [11, 12], userId: 'alice');

		self::assertSame([11, 12], $answer['unextracted']);
		self::assertSame([], $answer['results']);
		self::assertSame([], $this->searched);
	}//end testFilesWithoutTextAreNamedAsUnextracted()

	/**
	 * Empty words search nothing but still name the unextracted files.
	 *
	 * @return void
	 */
	public function testEmptyWordsSearchNothing(): void {
		$this->withChunks = [11];

		$answer = $this->service()->searchFiles(words: '   ', fileIds: [11, 12], userId: 'alice');

		self::assertSame([], $answer['results']);
		self::assertSame([12], $answer['unextracted']);
		self::assertSame([], $this->searched);
	}//end testEmptyWordsSearchNothing()

	/**
	 * An unknown user, or no user, has nothing to search.
	 *
	 * @return void
	 */
	public function testAnUnknownUserHasNothingToSearch(): void {
		self::assertSame(['results' => [], 'unextracted' => []], $this->service(userExists: false)->searchFiles(words: 'x', fileIds: [11], userId: 'ghost'));
		self::assertSame(['results' => [], 'unextracted' => []], $this->service()->searchFiles(words: 'x', fileIds: [11], userId: ' '));
	}//end testAnUnknownUserHasNothingToSearch()

	/**
	 * At most MAX_FILES listed ids are looked at.
	 *
	 * @return void
	 */
	public function testAtMostMaxFilesAreLookedAt(): void {
		$this->withChunks = [];
		$ids              = range(1, ScopedContentSearchService::MAX_FILES + 50);
		$this->nodes      = array_fill_keys($ids, true);

		$answer = $this->service()->searchFiles(words: 'x', fileIds: $ids, userId: 'alice');

		self::assertCount(ScopedContentSearchService::MAX_FILES, $answer['unextracted']);
	}//end testAtMostMaxFilesAreLookedAt()

	/**
	 * The snippet escapes the text, cuts around the first match and marks every word once.
	 *
	 * @return void
	 */
	public function testTheSnippetIsEscapedCutAndMarked(): void {
		$text    = str_repeat('voorwoord ', 40) . '<b>Heffing</b> & mark van het waterschap ' . str_repeat('slot ', 60);
		$snippet = ScopedContentSearchService::snippet(text: $text, words: 'heffing mark');

		self::assertStringStartsWith('…', $snippet);
		self::assertStringEndsWith('…', $snippet);
		self::assertStringContainsString('&lt;b&gt;<mark>Heffing</mark>&lt;/b&gt; &amp; <mark>mark</mark> van', $snippet);
		self::assertStringNotContainsString('<b>', $snippet);
		self::assertSame(1, substr_count($snippet, '<mark>mark</mark>'));
		self::assertSame('kort', ScopedContentSearchService::snippet(text: 'kort', words: ''));
	}//end testTheSnippetIsEscapedCutAndMarked()

	/**
	 * Only positive integer ids survive, once each, in order.
	 *
	 * @return void
	 */
	public function testTheIdListKeepsPositiveIntegersOnce(): void {
		self::assertSame([3, 7], FileChunkSearch::fileIdList(fileIds: ['3', 7, 'x', -1, 0, 3, 2.5, null, '07']));
	}//end testTheIdListKeepsPositiveIntegersOnce()
}//end class

<?php

/**
 * VectorSearchFacade answers in the published row shape, through the read filter, capped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-file-text-and-vector-facade
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Service\Vectorization\HybridSearchRequest;
use OCA\OpenRegister\Search\Service\Vectorization\SemanticSearchRequest;
use OCA\OpenRegister\Search\Service\VectorizationServiceInterface;
use OCA\OpenRegister\Search\Service\ViewService;
use OCA\OpenRegister\Service\Search\VectorResultFilter;
use OCA\OpenRegister\Service\Search\VectorSearchFacade;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The facade over a mocked vector store and the real read filter.
 */
#[CoversClass(VectorSearchFacade::class)]
#[UsesClass(VectorResultFilter::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectFindOptions::class)]
#[UsesClass(View::class)]
#[UsesClass(SemanticSearchRequest::class)]
#[UsesClass(HybridSearchRequest::class)]
#[UsesClass(KeywordChunkSearch::class)]
class VectorSearchFacadeTest extends TestCase {

	private VectorizationServiceInterface&MockObject $vectors;

	private ChunkMapperInterface&MockObject $chunks;

	private SettingsServiceInterface&MockObject $settings;

	private ViewService&MockObject $views;

	private VectorSearchFacade $facade;

	protected function setUp(): void {
		parent::setUp();
		$this->vectors = $this->createMock(VectorizationServiceInterface::class);
		$this->chunks = $this->createMock(ChunkMapperInterface::class);
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->views = $this->createMock(ViewService::class);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($alice);

		// Alice can open file 10, not file 20 (bob's).
		$files = $this->createMock(FileReadScope::class);
		$files->method('readableResults')->willReturnCallback(
			static fn (array $results): array => array_values(array_filter($results, static fn (array $r): bool => (string)$r['entity_id'] === '10'))
		);
		// Alice may read the beleid record, not the secret one.
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			static fn (int|string $id): ?ObjectEntity => ($id === 'secret') ? null : new ObjectEntity()
		);

		$this->facade = new VectorSearchFacade(
			$this->vectors,
			new VectorResultFilter($files, $objects, $this->views, $session),
			$this->chunks,
			$this->settings,
			new NullLogger()
		);
	}

	/**
	 * A raw vector hit as VectorSearchHandler returns it.
	 *
	 * @param string               $type       The entity type.
	 * @param int|string           $id         The entity id.
	 * @param float                $similarity The similarity.
	 * @param array<string, mixed> $metadata   The metadata.
	 *
	 * @return array<string, mixed>
	 */
	private static function hit(string $type, int|string $id, float $similarity, array $metadata = []): array {
		return [
			'vector_id' => 1, 'entity_type' => $type, 'entity_id' => $id, 'similarity' => $similarity,
			'chunk_index' => 0, 'total_chunks' => 1, 'chunk_text' => 'text of ' . $id,
			'metadata' => $metadata, 'model' => 'm', 'dimensions' => 3,
		];
	}

	public function testSemanticRowsHaveThePublishedShapeAndOnlyReadableEntities(): void {
		$this->vectors->method('semanticSearch')->willReturn(
			[
				self::hit('object', 'beleid-1', 0.91, ['register' => 3, 'schema' => 'beleid']),
				self::hit('file', 20, 0.88),
				self::hit('object', 'secret', 0.85),
				self::hit('file', 10, 0.80),
			]
		);

		$rows = $this->facade->searchSemantic('parkeervergunning', 10);

		$this->assertSame(
			[
				['entity_id' => 'beleid-1', 'entity_type' => 'object', 'chunk_text' => 'text of beleid-1', 'similarity' => 0.91, 'metadata' => ['register' => 3, 'schema' => 'beleid']],
				['entity_id' => 10, 'entity_type' => 'file', 'chunk_text' => 'text of 10', 'similarity' => 0.80, 'metadata' => []],
			],
			$rows
		);
	}

	public function testSemanticOverFetchesThreeTimesAndCapsTheLimitAtFifty(): void {
		$this->vectors->expects($this->once())->method('semanticSearch')
			->with($this->callback(static fn (SemanticSearchRequest $r): bool => $r->limit === 150 && $r->query === 'q'))
			->willReturn([]);

		$this->assertSame([], $this->facade->searchSemantic('q', 500));
	}

	public function testAFilteredPageIsStillFullWhenEnoughCandidatesAreReadable(): void {
		$candidates = [];
		for ($i = 0; $i < 6; $i++) {
			$candidates[] = self::hit('file', 20, 0.99);
			$candidates[] = self::hit('file', 10, (0.9 - $i / 100));
		}

		$this->vectors->method('semanticSearch')->willReturn($candidates);

		$this->assertCount(4, $this->facade->searchSemantic('q', 4));
	}

	public function testViewsNarrowObjectRowsAndKeepReadableFiles(): void {
		$view = new View();
		$view->setQuery(['registers' => [3], 'schemas' => ['beleid']]);
		$this->views->method('find')->willReturn($view);
		$this->vectors->method('semanticSearch')->willReturn(
			[
				self::hit('object', 'beleid-1', 0.9, ['register' => 3, 'schema' => 'beleid']),
				self::hit('object', 'zaak-1', 0.8, ['register' => 4, 'schema' => 'zaak']),
				self::hit('file', 10, 0.7),
			]
		);

		$rows = $this->facade->searchSemantic('parkeervergunning', 10, [5]);

		$this->assertSame(['beleid-1', 10], array_column($rows, 'entity_id'));
	}

	public function testHybridFiltersTheKeywordArmBeforeFusionAndReportsTheFusedScore(): void {
		$this->chunks->expects($this->once())->method('searchByKeyword')
			->with($this->callback(static fn (KeywordChunkSearch $s): bool => $s->limit === 30))
			->willReturn(
				[
					['entity_type' => 'file', 'entity_id' => '20', 'score' => 3.0],
					['entity_type' => 'file', 'entity_id' => '10', 'score' => 1.0],
				]
			);
		$this->vectors->expects($this->once())->method('hybridSearch')
			->with(
				$this->callback(
					static fn (HybridSearchRequest $r): bool => array_column($r->keywordResults, 'entity_id') === ['10'] && $r->limit === 30
				)
			)
			->willReturn(
				[
					'results' => [
						['entity_type' => 'file', 'entity_id' => '10', 'chunk_text' => 'plan', 'metadata' => [], 'combined_score' => 0.016],
						['entity_type' => 'object', 'entity_id' => 'secret', 'chunk_text' => 'no', 'metadata' => [], 'combined_score' => 0.012],
					],
				]
			);

		$rows = $this->facade->searchHybrid('plan', 10);

		$this->assertSame([['entity_id' => '10', 'entity_type' => 'file', 'chunk_text' => 'plan', 'similarity' => 0.016, 'metadata' => []]], $rows);
	}

	public function testEmbedTextsReturnsOneVectorPerTextInOrder(): void {
		$this->vectors->method('generateEmbedding')->willReturnCallback(
			static fn (string $text): array => ['embedding' => [(float)strlen($text)], 'model' => 'm', 'dimensions' => 1]
		);

		$this->assertSame([[1.0], [3.0]], $this->facade->embedTexts(['a', 'abc']));
	}

	public function testItIsAvailableOnlyWithLlmOnAndAnEmbeddingProvider(): void {
		$this->settings->method('getLLMSettingsOnly')->willReturnOnConsecutiveCalls(
			['enabled' => true, 'embeddingProvider' => 'ollama'],
			['enabled' => true, 'embeddingProvider' => null],
			['enabled' => false, 'embeddingProvider' => 'openai'],
			$this->throwException(new RuntimeException('unreadable'))
		);

		$this->assertTrue($this->facade->isAvailable());
		$this->assertFalse($this->facade->isAvailable());
		$this->assertFalse($this->facade->isAvailable());
		$this->assertFalse($this->facade->isAvailable());
	}
}

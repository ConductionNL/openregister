<?php

/**
 * QueryHandlerContentSearchTest
 *
 * Unit tests covering the `_content_search` wiring in
 * QueryHandler::searchObjectsPaginatedDatabase() — the seam that fans out to
 * ContentSearchHandler only when the caller opts in.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueryHandlerContentSearchTest extends TestCase {
	private MagicMapper&MockObject $objectMapper;
	private ContentSearchHandler&MockObject $contentSearchHandler;
	private QueryHandler $handler;

	/** @var ObjectEntity[] */
	private array $metadataMatchResults;

	protected function setUp(): void {
		parent::setUp();

		$matched = new ObjectEntity();
		$matched->setId(1);
		$this->metadataMatchResults = [$matched];

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectMapper->method('searchObjectsPaginated')->willReturn(
			[
				'results' => $this->metadataMatchResults,
				'total' => 1,
				'registers' => [],
				'schemas' => [],
				'facets' => [],
				'facetable' => [],
			]
		);

		$this->contentSearchHandler = $this->createMock(ContentSearchHandler::class);

		$this->handler = new QueryHandler(
			$this->objectMapper,
			$this->createMock(GetObject::class),
			$this->createMock(RenderObject::class),
			$this->createMock(SearchQueryHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->contentSearchHandler,
			$this->createMock(IAppContainer::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRequest::class)
		);
	}//end setUp()

	public function testContentSearchHandlerIsNeverCalledWhenFlagAbsent(): void {
		$this->contentSearchHandler->expects($this->never())->method('augmentWithChunkMatches');

		$result = $this->handler->searchObjectsPaginatedDatabase(
			query: ['_search' => 'quarterly report'],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame($this->metadataMatchResults, $result['results']);
		$this->assertSame(1, $result['total']);
	}//end testContentSearchHandlerIsNeverCalledWhenFlagAbsent()

	public function testContentSearchHandlerIsNeverCalledWhenFlagExplicitlyFalse(): void {
		$this->contentSearchHandler->expects($this->never())->method('augmentWithChunkMatches');

		$this->handler->searchObjectsPaginatedDatabase(
			query: ['_search' => 'quarterly report', '_content_search' => false],
			_rbac: false,
			_multitenancy: false
		);
	}//end testContentSearchHandlerIsNeverCalledWhenFlagExplicitlyFalse()

	public function testContentSearchHandlerIsInvokedAndItsResultReplacesResultsAndTotalWhenFlagTrue(): void {
		$chunkMatch = new ObjectEntity();
		$chunkMatch->setId(2);

		$this->contentSearchHandler->expects($this->once())
			->method('augmentWithChunkMatches')
			->with(
				$this->callback(fn (array $query): bool => ($query['_content_search'] ?? null) === true),
				$this->metadataMatchResults,
				1,
				20,
				false,
				false
			)
			->willReturn(
				[
					'results' => array_merge($this->metadataMatchResults, [$chunkMatch]),
					'total' => 2,
				]
			);

		$result = $this->handler->searchObjectsPaginatedDatabase(
			query: ['_search' => 'quarterly report', '_content_search' => true, '_limit' => 20],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertCount(2, $result['results']);
		$this->assertSame(2, $result['total']);
	}//end testContentSearchHandlerIsInvokedAndItsResultReplacesResultsAndTotalWhenFlagTrue()

	/**
	 * Regression: HTTP query params arrive as strings; the flag gate must coerce
	 * so `?_content_search=true` fires the handler. Prior to the fix at
	 * QueryHandler.php:419 (`filter_var(..., FILTER_VALIDATE_BOOLEAN)`) the
	 * check was strict-identity against `true` and every string form was
	 * silently ignored — the whole `_content_search` wire was dead from HTTP.
	 *
	 * @dataProvider provideTruthyStringForms
	 */
	public function testContentSearchHandlerIsInvokedForTruthyStringForms(mixed $flag): void {
		$this->contentSearchHandler->expects($this->once())
			->method('augmentWithChunkMatches')
			->willReturn(['results' => $this->metadataMatchResults, 'total' => 1]);

		$this->handler->searchObjectsPaginatedDatabase(
			query: ['_search' => 'q', '_content_search' => $flag],
			_rbac: false,
			_multitenancy: false
		);
	}//end testContentSearchHandlerIsInvokedForTruthyStringForms()

	/**
	 * Regression: falsy string forms must NOT fire the handler.
	 *
	 * @dataProvider provideFalsyStringForms
	 */
	public function testContentSearchHandlerIsNotInvokedForFalsyStringForms(mixed $flag): void {
		$this->contentSearchHandler->expects($this->never())->method('augmentWithChunkMatches');

		$this->handler->searchObjectsPaginatedDatabase(
			query: ['_search' => 'q', '_content_search' => $flag],
			_rbac: false,
			_multitenancy: false
		);
	}//end testContentSearchHandlerIsNotInvokedForFalsyStringForms()

	public static function provideTruthyStringForms(): array {
		return [
			'string "true"' => ['true'],
			'string "1"' => ['1'],
			'int 1' => [1],
			'bool true' => [true],
		];
	}//end provideTruthyStringForms()

	public static function provideFalsyStringForms(): array {
		return [
			'string "false"' => ['false'],
			'string "0"' => ['0'],
			'int 0' => [0],
			'bool false' => [false],
			'empty string' => [''],
		];
	}//end provideFalsyStringForms()
}//end class

<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The organisation a count runs under.
 *
 * @covers \OCA\OpenRegister\Service\Object\QueryHandler
 */
class QueryHandlerCountScopeTest extends TestCase {
	public function testACountUnderMultitenancyPassesTheActiveOrganisation(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->expects($this->once())
			->method('countSearchObjects')
			->with(['a' => 1], 'org-uuid-123', true, true, ['x'], 'u-1')
			->willReturn(7);

		$this->assertSame(7, $this->handler($mapper, 'org-uuid-123')->countSearchObjects(['a' => 1], true, true, ['x'], 'u-1'));
	}//end testACountUnderMultitenancyPassesTheActiveOrganisation()

	public function testACountWithoutMultitenancyPassesNoOrganisation(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->expects($this->once())
			->method('countSearchObjects')
			->with([], null, false, false, null, null)
			->willReturn(10);

		$this->assertSame(10, $this->handler($mapper, 'org-uuid-123')->countSearchObjects([], false, false));
	}//end testACountWithoutMultitenancyPassesNoOrganisation()

	private function handler(MagicMapper $mapper, string $organisation): QueryHandler {
		$performance = $this->createStub(PerformanceOptimizationHandler::class);
		$performance->method('getActiveOrganisationForContext')->willReturn($organisation);

		return new QueryHandler(
			$mapper,
			$this->createStub(GetObject::class),
			$this->createStub(RenderObject::class),
			$this->createStub(SearchQueryHandler::class),
			$this->createStub(FacetHandler::class),
			$performance,
			$this->createStub(ContentSearchHandler::class),
			$this->createStub(IAppContainer::class),
			$this->createStub(LoggerInterface::class),
			$this->createStub(IRequest::class)
		);
	}//end handler()
}//end class

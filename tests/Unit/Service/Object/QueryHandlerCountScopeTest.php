<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The organisation a count runs under.
 *
 * @covers \OCA\OpenRegister\Service\Object\QueryHandler
 * @uses \OCA\OpenRegister\Service\Object\SearchPaging
 * @uses \OCA\OpenRegister\Service\Object\SearchResponseReports
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

		$args = [];
		foreach ((new ReflectionClass(QueryHandler::class))->getConstructor()->getParameters() as $param) {
			$type   = $param->getType()->getName();
			$args[] = match (true) {
				$type === MagicMapperInterface::class => $mapper,
				$type === PerformanceOptimizationHandler::class => $performance,
				$param->isOptional() === true => $param->getDefaultValue(),
				default => $this->createStub($type),
			};
		}

		return new QueryHandler(...$args);
	}//end handler()
}//end class

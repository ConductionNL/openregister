<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\Object\ObjectSelection;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\OrganisationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * The organisation a count runs under.
 *
 * @covers \OCA\OpenRegister\Service\Object\QueryHandler
 * @uses \OCA\OpenRegister\Service\Object\SearchPaging
 * @uses \OCA\OpenRegister\Service\Object\SearchResponseReports
 * @uses \OCA\OpenRegister\Service\Object\SearchRenderNeed
 * @uses \OCA\OpenRegister\Service\Object\ObjectSelection
 * @uses \OCA\OpenRegister\Service\Object\QueryHandlers
 * @uses \OCA\OpenRegister\Service\Object\QueryPlatform
 * @uses \OCA\OpenRegister\Service\Object\QueryRefinements
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 */
class QueryHandlerCountScopeTest extends TestCase {
	public function testACountUnderMultitenancyPassesTheActiveOrganisation(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->expects($this->once())
			->method('countSearchObjects')
			->with(['a' => 1], 'org-uuid-123', true, true, ['x'], 'u-1')
			->willReturn(7);

		$this->assertSame(7, $this->handler($mapper, 'org-uuid-123')->countSearchObjects(['a' => 1], true, true, new ObjectSelection(ids: ['x'], uses: 'u-1')));
	}//end testACountUnderMultitenancyPassesTheActiveOrganisation()

	public function testACountWithoutMultitenancyPassesNoOrganisation(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->expects($this->once())
			->method('countSearchObjects')
			->with([], null, false, false, null, null)
			->willReturn(10);

		$this->assertSame(10, $this->handler($mapper, 'org-uuid-123')->countSearchObjects([], false, false));
	}//end testACountWithoutMultitenancyPassesNoOrganisation()

	public function testACountActingForAnOrganisationStaysInsideItWhateverTheFlag(): void {
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->expects($this->once())
			->method('countSearchObjects')
			->with([], 'org-uuid-123', false, true, null, null)
			->willReturn(3);
		$handler = $this->handler($mapper, 'org-uuid-123');

		$this->assertSame(3, OrganisationContext::run('org-uuid-123', static fn (): int => $handler->countSearchObjects([], false, false)));
	}//end testACountActingForAnOrganisationStaysInsideItWhateverTheFlag()

	private function handler(MagicMapper $mapper, string $organisation): QueryHandler {
		$performance = $this->createStub(PerformanceOptimizationHandler::class);
		$performance->method('getActiveOrganisationForContext')->willReturn($organisation);

		// A final class is a constructor bundle: build it from its own parameters.
		$argument = function (ReflectionParameter $param) use (&$argument, $mapper, $performance): mixed {
			$type = $param->getType()->getName();
			return match (true) {
				$type === MagicMapperInterface::class => $mapper,
				$type === PerformanceOptimizationHandler::class => $performance,
				(new ReflectionClass($type))->isFinal() === true => new $type(...array_map($argument, (new ReflectionClass($type))->getConstructor()->getParameters())),
				$param->isOptional() === true => $param->getDefaultValue(),
				default => $this->createStub($type),
			};
		};

		return new QueryHandler(...array_map($argument, (new ReflectionClass(QueryHandler::class))->getConstructor()->getParameters()));
	}//end handler()
}//end class

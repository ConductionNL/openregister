<?php

/**
 * Unit tests for the filter a GraphQL list query hands to its aggregation.
 *
 * A list query may carry BOTH a `filter` and a `groupBy`. The rows it returns
 * are filtered; the group totals must describe the same rows. They did not:
 * resolveGroupBy() built its aggregation input with `'filter' => []` hardcoded,
 * so a filtered list reported group totals computed over the WHOLE schema.
 *
 * Nothing errored. The caller was simply shown a bigger number than the rows it
 * was given — the hardest kind of wrong answer to notice on a dashboard, and
 * the reason these assertions look at the query handed to the runner rather
 * than at whether a response came back.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\GraphQL
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\GraphQL;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The property filter must reach the aggregation, not just the row list.
 *
 * @spec openspec/specs/graphql-api/spec.md
 */
final class GraphQLAggregationFilterTest extends TestCase {

	/**
	 * The AggregationQuery the runner was handed, captured for assertion.
	 *
	 * @var AggregationQuery|null
	 */
	private ?AggregationQuery $captured = null;

	/**
	 * Build a resolver whose AggregationRunner records the query it receives.
	 *
	 * @return GraphQLResolver The resolver under test.
	 */
	private function makeResolver(?AggregationRunner $runner = null): GraphQLResolver {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('buildSearchQuery')->willReturn([]);
		$objectService->method('searchObjectsPaginated')->willReturn(
			['results' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]
		);

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturn(true);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$register = new Register();
		$register->setId(1);
		$register->setSlug('finance');
		// findRegisterForSchema() matches on the register's schema-id LIST, so
		// a register without it resolves to null and the aggregation is skipped
		// entirely — the tests below would then pass for the wrong reason.
		$register->setSchemas([1]);
		$registerMapper->method('findAll')->willReturn([$register]);

		if ($runner === null) {
			$runner = $this->createMock(AggregationRunner::class);
			$runner->method('runAdhoc')->willReturnCallback(
				function (Register $r, Schema $s, AggregationQuery $query): array {
					$this->captured = $query;
					return ['groups' => []];
				}
			);
		}

		return new GraphQLResolver(
			$this->createMock(GetObject::class),
			$objectService,
			$permissionHandler,
			$this->createMock(PropertyRbacHandler::class),
			$this->createMock(RelationHandler::class),
			$this->createMock(AuditTrailMapper::class),
			$registerMapper,
			$this->createMock(LoggerInterface::class),
			$this->createMock(TranslationHandler::class),
			$runner,
			new TimeseriesRequestValidator()
		);
	}

	/**
	 * A schema declaring the properties the queries below use.
	 *
	 * @return Schema The schema under test.
	 */
	private function makeSchema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('gl-line');
		$schema->setProperties(
			[
				'accountNumber' => ['type' => 'string'],
				'amount' => ['type' => 'number'],
				'eliminationFlag' => ['type' => 'boolean'],
			]
		);
		return $schema;
	}

	/**
	 * REGRESSION: the list's property filter must reach the aggregation.
	 *
	 * Before the fix this asserts an empty filter — the groups were computed
	 * over every row in the schema while the edges honoured
	 * `eliminationFlag: false`.
	 *
	 * @return void
	 */
	public function testListFilterReachesTheAggregation(): void {
		$this->makeResolver()->resolveList(
			schema: $this->makeSchema(),
			root: null,
			args: [
				'filter' => ['eliminationFlag' => false],
				'groupBy' => [
					'field' => 'accountNumber',
					'metric' => 'SUM',
					'metricField' => 'amount',
				],
			]
		);

		self::assertNotNull($this->captured, 'the aggregation runner was never reached');
		self::assertSame(
			['eliminationFlag' => false],
			$this->captured->filter,
			'the groups must describe the same rows the edges do'
		);
	}

	/**
	 * An unfiltered list still aggregates over everything.
	 *
	 * Without this the test above could pass by always forwarding something.
	 *
	 * @return void
	 */
	public function testUnfilteredListAggregatesOverEverything(): void {
		$this->makeResolver()->resolveList(
			schema: $this->makeSchema(),
			root: null,
			args: [
				'groupBy' => [
					'field' => 'accountNumber',
					'metric' => 'SUM',
					'metricField' => 'amount',
				],
			]
		);

		self::assertNotNull($this->captured);
		self::assertSame([], $this->captured->filter);
	}

	/**
	 * A DECLARED aggregation is reachable by name, with the query's filter
	 * passed as a narrowing constraint.
	 *
	 * Until now these were REST-only, so a page wanting a declared figure had
	 * to hand-build a URL alongside its GraphQL query.
	 *
	 * @return void
	 */
	public function testDeclaredAggregationIsReachableByName(): void {
		$seen = [];
		$runner = $this->createMock(AggregationRunner::class);
		$runner->method('run')->willReturnCallback(
			function (
				string $registerRef,
				string $schemaRef,
				string $name,
				bool $bypassRbac = false,
				array $parentRow = [],
				array $extraFilter = [],
			) use (&$seen): array {
				$seen = compact('registerRef', 'schemaRef', 'name', 'extraFilter');
				return ['name' => $name, 'metric' => 'sum', 'groups' => []];
			}
		);

		$result = $this->makeResolver(runner: $runner)->resolveList(
			schema: $this->makeSchema(),
			root: null,
			args: [
				'filter' => ['eliminationFlag' => false],
				'aggregation' => 'consolidatedTrialBalance',
			]
		);

		self::assertSame('finance', $seen['registerRef']);
		self::assertSame('gl-line', $seen['schemaRef']);
		self::assertSame('consolidatedTrialBalance', $seen['name']);
		self::assertSame(
			['eliminationFlag' => false],
			$seen['extraFilter'],
			'the query filter must narrow the declared aggregation'
		);
		self::assertSame('consolidatedTrialBalance', $result['aggregation']['name']);

	}//end testDeclaredAggregationIsReachableByName()

	/**
	 * Without the argument, no declared aggregation runs and the connection
	 * carries no `aggregation` field.
	 *
	 * @return void
	 */
	public function testNoDeclaredAggregationWithoutTheArgument(): void {
		$runner = $this->createMock(AggregationRunner::class);
		$runner->expects(self::never())->method('run');

		$result = $this->makeResolver(runner: $runner)->resolveList(
			schema: $this->makeSchema(),
			root: null,
			args: ['filter' => ['eliminationFlag' => false]]
		);

		self::assertArrayNotHasKey('aggregation', $result);

	}//end testNoDeclaredAggregationWithoutTheArgument()

	/**
	 * Paging and free-text search MUST NOT reach the aggregation.
	 *
	 * A group total over "the first 20 rows" is not a total, and it would change
	 * as the user paged. `search` is a relevance query the aggregation engine
	 * does not implement — forwarding it would filter on a property named
	 * `_search`, match nothing, and return an empty result rather than an error.
	 *
	 * @return void
	 */
	public function testPagingAndSearchDoNotReachTheAggregation(): void {
		$this->makeResolver()->resolveList(
			schema: $this->makeSchema(),
			root: null,
			args: [
				'first' => 5,
				'offset' => 10,
				'search' => 'rent',
				'filter' => ['eliminationFlag' => false],
				'groupBy' => [
					'field' => 'accountNumber',
					'metric' => 'SUM',
					'metricField' => 'amount',
				],
			]
		);

		self::assertNotNull($this->captured);
		self::assertSame(['eliminationFlag' => false], $this->captured->filter);
	}
}//end class

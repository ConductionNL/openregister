<?php

/**
 * Golden tests of GraphQLResolver::resolveList: the search it asks for, the connection it
 * answers and the aggregations it adds.
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

use GraphQL\Error\Error;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the list query from arguments to connection.
 *
 * @spec openspec/specs/graphql-api/spec.md
 */
final class GraphQLResolverListGoldenTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * The aggregation runner double.
	 *
	 * @var AggregationRunner&MockObject
	 */
	private AggregationRunner $runner;

	/**
	 * Every call the object service received, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the resolver.
	 *
	 * @param array<string, mixed> $result       What the search answers.
	 * @param bool                 $withRegister Whether a register holds the schema.
	 *
	 * @return GraphQLResolver The resolver.
	 */
	private function resolver(array $result = [], bool $withRegister = true): GraphQLResolver {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnCallback(
			function ($register): ObjectService {
				$this->calls[] = ['setRegister', $register->getId()];
				return $this->objects;
			}
		);
		$this->objects->method('setSchema')->willReturnCallback(
			function ($schema): ObjectService {
				$this->calls[] = ['setSchema', $schema->getId()];
				return $this->objects;
			}
		);
		$this->objects->method('buildSearchQuery')->willReturnCallback(
			function (array $requestParams, $register = null, $schema = null): array {
				$this->calls[] = ['buildSearchQuery', $requestParams, $register, $schema];
				return ['built' => true];
			}
		);
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query, bool $_rbac = true, bool $_multitenancy = true) use ($result): array {
				$this->calls[] = ['search', $query, $_rbac, $_multitenancy];
				return $result;
			}
		);

		$register = new Register();
		$register->setId(7);
		$register->setSlug('finance');
		$register->setSchemas([1]);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$register]);
		if ($withRegister === false) {
			$registers = $this->createMock(RegisterMapper::class);
			$registers->method('findAll')->willReturn([]);
		}

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('filterReadableProperties')->willReturnCallback(
			static function (Schema $schema, array $row): array {
				unset($row['secret']);
				return $row;
			}
		);
		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('resolveTranslationsForRender')->willReturnCallback(
			static fn (array $objectData): array => $objectData
		);
		$this->runner = $this->createMock(AggregationRunner::class);

		return new GraphQLResolver(
			$this->createMock(GetObject::class),
			$this->objects,
			$this->createMock(PermissionHandler::class),
			$rbac,
			$this->createMock(RelationHandler::class),
			$registers,
			$this->createMock(LoggerInterface::class),
			$translations,
			$this->runner,
			new TimeseriesRequestValidator()
		);
	}//end resolver()

	/**
	 * A schema with an amount and a status.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('gl-line');
		$schema->setProperties(
			['amount' => ['type' => 'number'], 'status' => ['type' => 'string'], 'booked' => ['type' => 'string', 'format' => 'date']]
		);

		return $schema;
	}//end schema()

	/**
	 * Run a list query.
	 *
	 * @param GraphQLResolver      $resolver The resolver.
	 * @param array<string, mixed> $args     The arguments.
	 *
	 * @return array<string, mixed> The connection.
	 */
	private function list(GraphQLResolver $resolver, array $args): array {
		return $resolver->resolveList($this->schema(), $args);
	}//end list()

	/**
	 * Decode a cursor.
	 *
	 * @param string $cursor The cursor.
	 *
	 * @return array<string, mixed> The decoded cursor.
	 */
	private function cursor(string $cursor): array {
		return json_decode(base64_decode($cursor), true);
	}//end cursor()

	/**
	 * Arguments and the request params they become.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> The cases.
	 */
	public static function requestParamCases(): array {
		return [
			'nothing' => [[], ['_limit' => 20, '_offset' => 0]],
			'paging' => [['first' => 5, 'offset' => 10], ['_limit' => 5, '_offset' => 10]],
			'search' => [['search' => 'jan'], ['_limit' => 20, '_offset' => 0, '_search' => 'jan']],
			'fuzzy' => [['fuzzy' => true], ['_limit' => 20, '_offset' => 0, '_fuzzy' => 'true']],
			'fuzzy off' => [['fuzzy' => false], ['_limit' => 20, '_offset' => 0]],
			'fuzzy truthy' => [['fuzzy' => 1], ['_limit' => 20, '_offset' => 0]],
			'sort' => [
				['sort' => ['field' => 'amount', 'order' => 'desc']],
				['_limit' => 20, '_offset' => 0, '_order' => '[{"field":"amount","direction":"DESC"}]'],
			],
			'sort without order' => [
				['sort' => ['field' => 'amount']],
				['_limit' => 20, '_offset' => 0, '_order' => '[{"field":"amount","direction":"ASC"}]'],
			],
			'facets' => [['facets' => ['a', 'b']], ['_limit' => 20, '_offset' => 0, '_facets' => 'a,b']],
			'no facets' => [['facets' => []], ['_limit' => 20, '_offset' => 0]],
			'filter' => [
				['filter' => ['status' => 'open', 3 => 'x']],
				['_limit' => 20, '_offset' => 0, 'status' => 'open', 3 => 'x'],
			],
			'filter names a param' => [['filter' => ['_limit' => 1]], ['_limit' => 1, '_offset' => 0]],
			'filter is no map' => [['filter' => 'open'], ['_limit' => 20, '_offset' => 0]],
			'self filter' => [
				['selfFilter' => ['owner' => 'jan', 'organisation' => null]],
				['_limit' => 20, '_offset' => 0, '@self' => ['owner' => 'jan']],
			],
			'self filter all null' => [
				['selfFilter' => ['organisation' => null]],
				['_limit' => 20, '_offset' => 0],
			],
			'self filter is no map' => [['selfFilter' => 'jan'], ['_limit' => 20, '_offset' => 0]],
			'filter and self filter merge' => [
				['filter' => ['@self' => ['a' => 1]], 'selfFilter' => ['b' => 2]],
				['_limit' => 20, '_offset' => 0, '@self' => ['a' => 1, 'b' => 2]],
			],
			'every argument, in order' => [
				[
					'selfFilter' => ['owner' => 'jan'],
					'filter' => ['status' => 'open'],
					'facets' => ['a'],
					'sort' => ['field' => 'amount'],
					'fuzzy' => true,
					'search' => 'jan',
					'offset' => 2,
					'first' => 1,
				],
				[
					'_limit' => 1,
					'_offset' => 2,
					'_search' => 'jan',
					'_fuzzy' => 'true',
					'_order' => '[{"field":"amount","direction":"ASC"}]',
					'_facets' => 'a',
					'status' => 'open',
					'@self' => ['owner' => 'jan'],
				],
			],
		];
	}//end requestParamCases()

	/**
	 * Arguments become the same request params, keys in the same order.
	 *
	 * @param array<string, mixed> $args     The arguments.
	 * @param array<string, mixed> $expected The request params.
	 *
	 * @return void
	 */
	#[DataProvider('requestParamCases')]
	public function testRequestParams(array $args, array $expected): void {
		$this->list($this->resolver(), $args);

		$this->assertSame($expected, $this->calls[2][1]);
	}//end testRequestParams()

	/**
	 * The object service is pointed at the register and schema, then searched with both checks on.
	 *
	 * @return void
	 */
	public function testTheSearchIsRoutedAndChecked(): void {
		$this->list($this->resolver(), []);

		$this->assertSame(
			[
				['setRegister', 7],
				['setSchema', 1],
				['buildSearchQuery', ['_limit' => 20, '_offset' => 0], 7, 1],
				['search', ['built' => true], true, true],
			],
			$this->calls
		);
	}//end testTheSearchIsRoutedAndChecked()

	/**
	 * A schema no register holds is searched without a register.
	 *
	 * @return void
	 */
	public function testASchemaWithoutARegisterIsSearchedWithoutOne(): void {
		$this->list($this->resolver(withRegister: false), []);

		$this->assertSame(
			[
				['setSchema', 1],
				['buildSearchQuery', ['_limit' => 20, '_offset' => 0], null, 1],
				['search', ['built' => true], true, true],
			],
			$this->calls
		);
	}//end testASchemaWithoutARegisterIsSearchedWithoutOne()

	/**
	 * An empty answer is an empty connection with the default paging.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswer(): void {
		$this->assertSame(
			[
				'edges' => [],
				'pageInfo' => [
					'hasNextPage' => false,
					'hasPreviousPage' => false,
					'startCursor' => null,
					'endCursor' => null,
				],
				'totalCount' => 0,
				'facets' => null,
				'facetable' => null,
				'groups' => null,
			],
			$this->list($this->resolver(), [])
		);
	}//end testAnEmptyAnswer()

	/**
	 * Rows and records become edges; anything else is dropped; the rules ran on each.
	 *
	 * @return void
	 */
	public function testEdgesCarryFilteredRows(): void {
		$record = new ObjectEntity();
		$record->setUuid('rec-1');
		$record->setObject(['title' => 'record', 'secret' => 's']);
		$result = [
			'results' => [
				['_uuid' => 'row-1', 'secret' => 's', '_relevance' => 0.5],
				'not a row',
				['@self' => ['uuid' => 'row-2']],
				$record,
				['title' => 'no uuid'],
			],
			'total' => 40,
			'limit' => 4,
			'offset' => 8,
			'facets' => ['f' => 1],
			'facetable' => ['g' => 2],
		];

		$connection = $this->list($this->resolver($result), ['first' => 99, 'offset' => 99]);

		$this->assertSame(
			[
				['_uuid' => 'row-1', '_relevance' => 0.5],
				['@self' => ['uuid' => 'row-2']],
			],
			array_map(static fn (array $edge): array => $edge['node'], array_slice($connection['edges'], 0, 2))
		);
		$this->assertSame('record', $connection['edges'][2]['node']['title']);
		$this->assertArrayNotHasKey('secret', $connection['edges'][2]['node']);
		$this->assertSame(
			[0.5, null, null, null],
			array_map(static fn (array $edge) => $edge['_relevance'], $connection['edges'])
		);
		$this->assertSame(
			[
				['uuid' => 'row-1', 'offset' => 8],
				['uuid' => 'row-2', 'offset' => 9],
				['uuid' => 'rec-1', 'offset' => 10],
				['uuid' => '11', 'offset' => 11],
			],
			array_map(fn (array $edge): array => $this->cursor($edge['cursor']), $connection['edges'])
		);
		$this->assertSame(
			[
				'hasNextPage' => true,
				'hasPreviousPage' => true,
				'startCursor' => $connection['edges'][0]['cursor'],
				'endCursor' => $connection['edges'][3]['cursor'],
			],
			$connection['pageInfo']
		);
		$this->assertSame(40, $connection['totalCount']);
		$this->assertSame(['f' => 1], $connection['facets']);
		$this->assertSame(['g' => 2], $connection['facetable']);
	}//end testEdgesCarryFilteredRows()

	/**
	 * Paging falls back from the answer to the arguments to the defaults.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool, 3: bool}> The cases.
	 */
	public static function pagingCases(): array {
		return [
			'answer wins' => [['limit' => 2, 'offset' => 0, 'total' => 3], ['first' => 9, 'offset' => 9], true, false],
			'arguments next' => [['total' => 3], ['first' => 2, 'offset' => 1], false, true],
			'arguments have room' => [['total' => 4], ['first' => 2, 'offset' => 1], true, true],
			'defaults' => [['total' => 21], [], true, false],
			'defaults exactly full' => [['total' => 20], [], false, false],
		];
	}//end pagingCases()

	/**
	 * The page flags use the same fallbacks.
	 *
	 * @param array<string, mixed> $result   The search answer.
	 * @param array<string, mixed> $args     The arguments.
	 * @param bool                 $next     Whether a next page is expected.
	 * @param bool                 $previous Whether a previous page is expected.
	 *
	 * @return void
	 */
	#[DataProvider('pagingCases')]
	public function testPageFlags(array $result, array $args, bool $next, bool $previous): void {
		$info = $this->list($this->resolver($result), $args)['pageInfo'];

		$this->assertSame($next, $info['hasNextPage']);
		$this->assertSame($previous, $info['hasPreviousPage']);
	}//end testPageFlags()

	/**
	 * Group buckets keep what the engine said.
	 *
	 * @return void
	 */
	public function testGroupBucketsKeepTheirShape(): void {
		$resolver = $this->resolver();
		$this->runner->method('runAdhoc')->willReturn(
			[
				'groups' => [
					['key' => null, 'value' => 3],
					'not a bucket',
					['key' => 0, 'value' => '1.5', 'keys' => ['a'], 'joined' => 'j'],
					['values' => ['sum' => 2]],
				],
			]
		);

		$connection = $this->list($resolver, ['groupBy' => ['field' => 'status']]);

		$this->assertSame(
			[
				['key' => null, 'value' => 3.0, 'keys' => null, 'values' => null, 'joined' => null],
				['key' => '0', 'value' => 1.5, 'keys' => ['a'], 'values' => null, 'joined' => 'j'],
				['key' => null, 'value' => null, 'keys' => null, 'values' => ['sum' => 2], 'joined' => null],
			],
			$connection['groups']
		);
	}//end testGroupBucketsKeepTheirShape()

	/**
	 * Everything the group-by carries reaches the validated query, and so does the row filter.
	 *
	 * @return void
	 */
	public function testGroupByReachesTheRunner(): void {
		$resolver = $this->resolver();
		$seen = [];
		$this->runner->method('runAdhoc')->willReturnCallback(
			function (Register $register, Schema $schema, $query) use (&$seen): array {
				$seen = [$register->getId(), $schema->getId(), $query];
				return [];
			}
		);

		$connection = $this->list(
			$resolver,
			[
				'filter' => ['status' => 'open', 0 => 'dropped', '' => 'dropped'],
				'groupBy' => ['field' => 'status', 'metric' => 'SUM', 'metricField' => 'amount'],
			]
		);

		$this->assertSame([], $connection['groups']);
		$this->assertSame(7, $seen[0]);
		$this->assertSame(1, $seen[1]);
		$this->assertSame('sum', $seen[2]->metric);
		$this->assertSame('amount', $seen[2]->field);
		$this->assertSame(['field' => 'status'], $seen[2]->groupBy);
		$this->assertSame(['status' => 'open'], $seen[2]->filter);
	}//end testGroupByReachesTheRunner()

	/**
	 * Interval, range, composite fields and metrics reach the validator as given.
	 *
	 * @return void
	 */
	public function testAFullGroupByReachesTheValidator(): void {
		$resolver = $this->resolver();
		$seen = null;
		$this->runner->method('runAdhoc')->willReturnCallback(
			function (Register $register, Schema $schema, $query) use (&$seen): array {
				$seen = $query;
				return [];
			}
		);

		$this->list(
			$resolver,
			[
				'groupBy' => [
					'field' => 'status',
					'fields' => ['status', 'amount'],
					'metrics' => [['name' => 'n', 'metric' => 'count']],
				],
			]
		);

		$this->assertSame(['fields' => ['status', 'amount']], $seen->groupBy);
		$this->assertSame([['name' => 'n', 'metric' => 'count']], $seen->metrics);
	}//end testAFullGroupByReachesTheValidator()

	/**
	 * Interval and range reach the validator as given.
	 *
	 * @return void
	 */
	public function testAnIntervalReachesTheValidator(): void {
		$resolver = $this->resolver();
		$seen = null;
		$this->runner->method('runAdhoc')->willReturnCallback(
			function (Register $register, Schema $schema, $query) use (&$seen): array {
				$seen = $query;
				return [];
			}
		);

		$this->list(
			$resolver,
			['groupBy' => ['field' => 'booked', 'interval' => 'month', 'from' => '2026-01-01', 'to' => '2026-02-01']]
		);

		$this->assertSame(
			['field' => 'booked', 'start' => '2026-01-01', 'end' => '2026-02-01', 'gap' => 'month'],
			$seen->dateBucket
		);
	}//end testAnIntervalReachesTheValidator()

	/**
	 * A group-by without a field, or that is no map, asks for nothing.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function idleGroupByCases(): array {
		return [
			'empty field' => [['field' => '']],
			'no field' => [['metric' => 'sum']],
			'no map' => ['status'],
		];
	}//end idleGroupByCases()

	/**
	 * Nothing runs for an idle group-by.
	 *
	 * @param mixed $groupBy The argument.
	 *
	 * @return void
	 */
	#[DataProvider('idleGroupByCases')]
	public function testAnIdleGroupByRunsNothing(mixed $groupBy): void {
		$resolver = $this->resolver();
		$this->runner->expects($this->never())->method('runAdhoc');

		$this->assertNull($this->list($resolver, ['groupBy' => $groupBy])['groups']);
	}//end testAnIdleGroupByRunsNothing()

	/**
	 * Without a register there is nothing to aggregate: the fields stay queryable and empty.
	 *
	 * @return void
	 */
	public function testAggregationsWithoutARegisterAnswerNull(): void {
		$resolver = $this->resolver(withRegister: false);
		$this->runner->expects($this->never())->method('runAdhoc');
		$this->runner->expects($this->never())->method('run');

		$connection = $this->list($resolver, ['groupBy' => ['field' => 'status'], 'aggregation' => 'total']);

		$this->assertNull($connection['groups']);
		$this->assertArrayHasKey('aggregation', $connection);
		$this->assertNull($connection['aggregation']);
	}//end testAggregationsWithoutARegisterAnswerNull()

	/**
	 * A declared aggregation gets the key only when one was asked for.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function idleAggregationCases(): array {
		return [
			'empty' => [''],
			'no string' => [5],
		];
	}//end idleAggregationCases()

	/**
	 * Nothing runs for an idle aggregation argument.
	 *
	 * @param mixed $name The argument.
	 *
	 * @return void
	 */
	#[DataProvider('idleAggregationCases')]
	public function testAnIdleAggregationRunsNothing(mixed $name): void {
		$resolver = $this->resolver();
		$this->runner->expects($this->never())->method('run');

		$this->assertArrayNotHasKey('aggregation', $this->list($resolver, ['aggregation' => $name]));
	}//end testAnIdleAggregationRunsNothing()

	/**
	 * A refused or invalid group-by is a field error, with the message the cause gave.
	 *
	 * @return void
	 */
	public function testARefusedGroupByIsAnError(): void {
		$resolver = $this->resolver();
		$this->runner->method('runAdhoc')->willThrowException(new NotAuthorizedException('no groups for you'));

		$this->expectException(Error::class);
		$this->expectExceptionMessage('no groups for you');
		$this->list($resolver, ['groupBy' => ['field' => 'status']]);
	}//end testARefusedGroupByIsAnError()

	/**
	 * A group-by the validator rejects is a field error and never reaches the runner.
	 *
	 * @return void
	 */
	public function testAnInvalidGroupByIsAnError(): void {
		$resolver = $this->resolver();
		$this->runner->expects($this->never())->method('runAdhoc');

		$this->expectException(Error::class);
		$this->list($resolver, ['groupBy' => ['field' => 'status', 'metric' => 'median']]);
	}//end testAnInvalidGroupByIsAnError()

	/**
	 * Only a refusal is translated: another failure of the ad-hoc run stays what it is.
	 *
	 * @return void
	 */
	public function testAFailingGroupByRunIsNotTranslated(): void {
		$resolver = $this->resolver();
		$this->runner->method('runAdhoc')->willThrowException(new RuntimeException('engine down'));

		$this->expectException(RuntimeException::class);
		$this->list($resolver, ['groupBy' => ['field' => 'status']]);
	}//end testAFailingGroupByRunIsNotTranslated()

	/**
	 * What a declared aggregation can fail with.
	 *
	 * @return array<string, array{0: \Exception}> The cases.
	 */
	public static function declaredFailureCases(): array {
		return [
			'refused' => [new NotAuthorizedException('no figure for you')],
			'unknown' => [new RuntimeException('no such aggregation')],
		];
	}//end declaredFailureCases()

	/**
	 * Both failures of a declared aggregation are field errors, with the message the cause gave.
	 *
	 * @param \Exception $cause The failure.
	 *
	 * @return void
	 */
	#[DataProvider('declaredFailureCases')]
	public function testADeclaredAggregationFailureIsAnError(\Exception $cause): void {
		$resolver = $this->resolver();
		$this->runner->method('run')->willThrowException($cause);

		try {
			$this->list($resolver, ['aggregation' => 'total']);
			$this->fail('expected an error');
		} catch (Error $error) {
			$this->assertSame($cause->getMessage(), $error->getMessage());
		}
	}//end testADeclaredAggregationFailureIsAnError()

	/**
	 * The group-by runs before the declared aggregation, and a list carries both answers.
	 *
	 * @return void
	 */
	public function testBothAggregationsRunAndGroupsComeFirst(): void {
		$resolver = $this->resolver();
		$order = [];
		$this->runner->method('runAdhoc')->willReturnCallback(
			static function () use (&$order): array {
				$order[] = 'groups';
				return [];
			}
		);
		$this->runner->method('run')->willReturnCallback(
			static function () use (&$order): array {
				$order[] = 'declared';
				return ['value' => 1];
			}
		);

		$connection = $this->list($resolver, ['groupBy' => ['field' => 'status'], 'aggregation' => 'total']);

		$this->assertSame(['groups', 'declared'], $order);
		$this->assertSame(['value' => 1], $connection['aggregation']);
		$this->assertSame('aggregation', array_key_last($connection));
	}//end testBothAggregationsRunAndGroupsComeFirst()
}//end class

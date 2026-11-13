<?php

/**
 * A bounded run caps a list's page size and refuses it once the deadline has
 * passed; an ordinary list, carrying no bound, is unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\GraphQL;

use GraphQL\Error\Error;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Search\Service\GraphQL\BoundedRun;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverObjects;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverRights;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverShaping;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\GraphQL\GraphQLResolver
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\BoundedRun
 * @uses \OCA\OpenRegister\Service\GraphQL\GraphQLResolverObjects
 * @uses \OCA\OpenRegister\Service\GraphQL\GraphQLResolverRights
 * @uses \OCA\OpenRegister\Service\GraphQL\GraphQLResolverShaping
 * @uses \OCA\OpenRegister\Service\GraphQL\ListRequest
 * @uses \OCA\OpenRegister\Service\GraphQL\ListConnection
 * @uses \OCA\OpenRegister\Service\GraphQL\ListAggregations
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator
 *
 * @spec openspec/changes/operate-admin-query-console/specs/admin-query-console/spec.md
 */
class GraphQLResolverBoundedTest extends TestCase {

	/** @var array<int, mixed> */
	private array $calls = [];

	/** @var ObjectService&\PHPUnit\Framework\MockObject\MockObject */
	private ObjectService $objects;

	/**
	 * A resolver whose store answers the given rows, recording each query.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows the search returns.
	 *
	 * @return GraphQLResolver The resolver.
	 */
	private function resolver(array $rows): GraphQLResolver {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('buildSearchQuery')->willReturnCallback(
			function (array $requestParams): array {
				$this->calls[] = ['buildSearchQuery', $requestParams];
				return ['built' => true];
			}
		);
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query, bool $_rbac = true, bool $_multitenancy = true) use ($rows): array {
				$this->calls[] = ['search'];
				return ['results' => $rows];
			}
		);

		$register = new Register();
		$register->setId(7);
		$register->setSlug('finance');
		$register->setSchemas([1]);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$register]);

		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('filterReadableProperties')->willReturnCallback(static fn (Schema $schema, array $row): array => $row);
		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('resolveTranslationsForRender')->willReturnCallback(static fn (array $objectData): array => $objectData);

		return new GraphQLResolver(
			new GraphQLResolverObjects($this->createMock(GetObject::class), $this->objects, $this->createMock(RelationHandler::class), $registers),
			new GraphQLResolverRights($this->createMock(PermissionHandler::class), $rbac),
			new GraphQLResolverShaping($translations, $this->createMock(AggregationRunner::class), new TimeseriesRequestValidator()),
			$this->createMock(LoggerInterface::class)
		);
	}//end resolver()

	/**
	 * A schema with one plain property.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('gl-line');
		$schema->setProperties(['amount' => ['type' => 'number']]);

		return $schema;
	}//end schema()

	/**
	 * The _limit of the recorded search query.
	 *
	 * @return int The limit.
	 */
	private function recordedLimit(): int {
		foreach ($this->calls as $call) {
			if ($call[0] === 'buildSearchQuery') {
				return (int) $call[1]['_limit'];
			}
		}

		return -1;
	}//end recordedLimit()

	/**
	 * Whether the store was searched.
	 *
	 * @return bool True when searchObjectsPaginated ran.
	 */
	private function searched(): bool {
		foreach ($this->calls as $call) {
			if ($call[0] === 'search') {
				return true;
			}
		}

		return false;
	}//end searched()

	public function testTheRunCapsTheListsPageSizeToWhatIsLeftAndMarksItTruncated(): void {
		$bounded = new BoundedRun(rowCap: 2, deadline: (microtime(true) + 30));
		$resolver = $this->resolver([['amount' => 1], ['amount' => 2]]);

		$resolver->resolveList($this->schema(), ['first' => 20], [BoundedRun::CONTEXT_KEY => $bounded]);

		$this->assertSame(2, $this->recordedLimit());
		$this->assertTrue($bounded->truncated());
		$this->assertSame(2, $bounded->rowsReturned());
	}//end testTheRunCapsTheListsPageSizeToWhatIsLeftAndMarksItTruncated()

	public function testAPageWithinTheCapIsNotTruncatedAndSpendsOnlyItsRows(): void {
		$bounded = new BoundedRun(rowCap: 1000, deadline: (microtime(true) + 30));
		$resolver = $this->resolver([['amount' => 1], ['amount' => 2], ['amount' => 3]]);

		$resolver->resolveList($this->schema(), ['first' => 20], [BoundedRun::CONTEXT_KEY => $bounded]);

		$this->assertSame(20, $this->recordedLimit());
		$this->assertFalse($bounded->truncated());
		$this->assertSame(3, $bounded->rowsReturned());
	}//end testAPageWithinTheCapIsNotTruncatedAndSpendsOnlyItsRows()

	public function testASecondListSeesOnlyWhatTheFirstLeft(): void {
		$bounded = new BoundedRun(rowCap: 5, deadline: (microtime(true) + 30));
		$schema = $this->schema();

		$first = $this->resolver([['amount' => 1], ['amount' => 2], ['amount' => 3]]);
		$first->resolveList($schema, ['first' => 20], [BoundedRun::CONTEXT_KEY => $bounded]);

		$this->calls = [];
		$second = $this->resolver([['amount' => 4], ['amount' => 5]]);
		$second->resolveList($schema, ['first' => 20], [BoundedRun::CONTEXT_KEY => $bounded]);

		$this->assertSame(2, $this->recordedLimit());
		$this->assertTrue($bounded->truncated());
		$this->assertSame(5, $bounded->rowsReturned());
	}//end testASecondListSeesOnlyWhatTheFirstLeft()

	public function testAPassedDeadlineRefusesTheListBeforeItSearches(): void {
		$bounded = new BoundedRun(rowCap: 1000, deadline: (microtime(true) - 1));
		$resolver = $this->resolver([['amount' => 1]]);

		try {
			$resolver->resolveList($this->schema(), ['first' => 20], [BoundedRun::CONTEXT_KEY => $bounded]);
			$this->fail('Expected a QUERY_TIMEOUT error.');
		} catch (Error $error) {
			$this->assertSame(BoundedRun::TIMEOUT_CODE, $error->getExtensions()['code']);
		}

		$this->assertFalse($this->searched());
	}//end testAPassedDeadlineRefusesTheListBeforeItSearches()

	public function testAListWithoutABoundIsUnchanged(): void {
		$resolver = $this->resolver([['amount' => 1]]);

		$resolver->resolveList($this->schema(), ['first' => 20], []);

		$this->assertSame(20, $this->recordedLimit());
		$this->assertTrue($this->searched());
	}//end testAListWithoutABoundIsUnchanged()
}//end class

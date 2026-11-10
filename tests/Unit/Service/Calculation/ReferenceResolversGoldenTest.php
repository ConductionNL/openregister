<?php

/**
 * Golden (characterisation) tests for the reference and aggregate-reference resolvers.
 *
 * Pins the queries {@see \OCA\OpenRegister\Service\Calculation\ReferenceResolver}
 * and {@see \OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver}
 * send for each declaration shape, and the values they return.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Calculation\ReferringObject;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\DependencyCycleFinder;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Calculation\ReferenceTenantGuard;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ReferenceResolversGoldenTest.
 */
class ReferenceResolversGoldenTest extends TestCase {

	/**
	 * The object service the reference resolver reads through.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * The aggregation runner the aggregate resolver folds through.
	 *
	 * @var AggregationRunner&MockObject
	 */
	private $runner;

	/**
	 * The references under test or mocked.
	 *
	 * @var ReferenceResolver
	 */
	private ReferenceResolver $references;

	/**
	 * The aggregates under test or mocked.
	 *
	 * @var AggregateReferenceResolver
	 */
	private AggregateReferenceResolver $aggregates;

	/**
	 * Wire both resolvers with mocked reads.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findAll'])
			->getMock();
		$organisations = $this->getMockBuilder(OrganisationMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findParentChain'])
			->getMock();
		$organisations->method('findParentChain')->willReturn([]);
		$shared = $this->getMockBuilder(SharedMasterDataService::class)
			->disableOriginalConstructor()
			->onlyMethods(['holdersForResource'])
			->getMock();
		$this->references = new ReferenceResolver(
			$this->objectService,
			new ReferenceTenantGuard($organisations, $shared, $logger),
			$logger
		);
		$this->runner = $this->createMock(AggregationRunner::class);
		$this->aggregates = new AggregateReferenceResolver($this->runner, $logger);

	}//end setUp()

	/**
	 * Declarations that name nothing to resolve resolve to null, unlisted.
	 *
	 * @return void
	 */
	public function testDeclarationsWithNothingToResolve(): void {
		$this->objectService->expects($this->never())->method('find');
		$this->objectService->expects($this->never())->method('findAll');
		$declarations = [
			'' => ['schema' => 's', 'mode' => 'lookup'],
			'notArray' => 'x',
			'noSchema' => ['mode' => 'lookup'],
			'badMode' => ['schema' => 's', 'mode' => 'other'],
			'noField' => ['schema' => 's', 'mode' => 'relatedObject'],
			'floatKey' => ['schema' => 's', 'mode' => 'relatedObject', 'field' => 'f'],
			'emptyKey' => ['schema' => 's', 'mode' => 'relatedObject', 'field' => 'e'],
			'missingKey' => ['schema' => 's', 'mode' => 'relatedObject', 'field' => 'nope'],
			'badFilters' => ['schema' => 's', 'mode' => 'lookup', 'filters' => 'x'],
		];

		$this->assertSame(
			[
				'refs' => [
					'noSchema' => null,
					'badMode' => null,
					'noField' => null,
					'floatKey' => null,
					'emptyKey' => null,
					'missingKey' => null,
					'badFilters' => null,
				],
				'unresolved' => [],
			],
			$this->references->resolveAllWithOutcome(['f' => 1.5, 'e' => ''], $declarations, 'reg', 'org-a')
		);

	}//end testDeclarationsWithNothingToResolve()

	/**
	 * A related object is read by an integer key under the declared register.
	 *
	 * @return void
	 */
	public function testRelatedObjectByIntegerKeyAndRegisterOverride(): void {
		$this->objectService->expects($this->once())->method('find')->willReturnCallback(
			function (...$args): ObjectEntity {
				$args = FindCallShape::find(args: $args);
				$this->assertSame([42, 'other', 'target', false, false], [$args[0], $args[3], $args[4], $args[5], $args[6]]);
				return $this->entity(organisation: 'org-a');
			}
		);
		$declaration = ['r' => ['schema' => 'target', 'mode' => 'relatedObject', 'field' => 'id', 'register' => 'other']];

		$outcome = $this->references->resolveAllWithOutcome(['id' => 42], $declaration, 'reg', 'org-a');

		$self = ['id' => 'ref', 'uuid' => 'ref', 'register' => '1', 'schema' => '2', 'owner' => 'o'];
		$this->assertSame(['id' => 'ref', 'v' => 1, '@self' => $self], $outcome['refs']['r']);
		$this->assertSame([], $outcome['unresolved']);

	}//end testRelatedObjectByIntegerKeyAndRegisterOverride()

	/**
	 * A lookup without effective dating sends no sort and no register when none is known.
	 *
	 * @return void
	 */
	public function testLookupConfigurations(): void {
		$configs = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $rbac, bool $multi) use (&$configs): array {
				$configs[] = [$config, $rbac, $multi];
				return [$this->entity(organisation: 'org-b')];
			}
		);
		$plain = ['schema' => 's', 'mode' => 'lookup', 'filters' => ['code' => '@self.code', 'kind' => 'k']];
		$dated = ['schema' => 's', 'mode' => 'lookup', 'effectiveDate' => ['field' => 'validFrom']];
		$undated = ['schema' => 's', 'mode' => 'lookup', 'effectiveDate' => ['op' => '<=']];

		$outcome = $this->references->resolveAllWithOutcome(
			['code' => 'c', '@self' => []],
			['plain' => $plain, 'dated' => $dated, 'undated' => $undated],
			null,
			'org-a'
		);

		$this->assertSame(['refs' => ['plain' => null, 'dated' => null, 'undated' => null], 'unresolved' => ['plain', 'dated', 'undated']], $outcome);
		$this->assertSame(
			[
				[['filters' => ['schema' => 's', 'code' => 'c', 'kind' => 'k'], 'limit' => 50], false, false],
				[['filters' => ['schema' => 's'], 'limit' => 50, 'sort' => ['validFrom' => 'DESC']], false, false],
				[['filters' => ['schema' => 's'], 'limit' => 50], false, false],
			],
			$configs
		);

	}//end testLookupConfigurations()

	/**
	 * Aggregate declarations: register fallbacks, optional field and groupBy, envelopes.
	 *
	 * @return void
	 */
	public function testAggregateQueriesAndEnvelopes(): void {
		$calls = [];
		$this->runner->method('runAdhocByRef')->willReturnCallback(
			static function (string $registerRef, string $schemaRef, AggregationQuery $query, bool $bypassRbac) use (&$calls): array {
				$calls[] = [$registerRef, $schemaRef, $query->metric, $query->field, $query->filter, $query->groupBy, $bypassRbac];
				return match ($schemaRef) {
					'grouped' => ['groups' => [['key' => 1, 'value' => 2], 'junk', ['value' => 3], ['key' => 'b']]],
					'empty' => ['backend' => 'x'],
					'throws' => throw new RuntimeException('no'),
					default => ['value' => 9],
				};
			}
		);
		$declarations = [
			'scalar' => ['schema' => 'scalar', 'metric' => 'sum', 'field' => 'h', 'filters' => ['x' => '@self.x', 'y' => 2]],
			'grouped' => ['schema' => 'grouped', 'metric' => 'count', 'field' => 3, 'groupBy' => ['status'], 'register' => 'own', 'filters' => 'x'],
			'empty' => ['schema' => 'empty', 'metric' => 'count', 'groupBy' => 'status'],
			'throws' => ['schema' => 'throws', 'metric' => 'count'],
			'noMetric' => ['schema' => 'scalar'],
		];

		$outcome = $this->aggregates->resolveAllWithOutcome(['x' => 'xv', '@self' => ['x' => 'self-x']], $declarations, 'reg');

		$this->assertSame(
			[
				'values' => ['scalar' => 9, 'grouped' => ['1' => 2, 'b' => null], 'empty' => null, 'throws' => null, 'noMetric' => null],
				'failed' => ['throws' => 'no'],
			],
			$outcome
		);
		$this->assertSame(['reg', 'scalar', 'sum', 'h', ['x' => 'self-x', 'y' => 2], null, true], $calls[0]);
		$this->assertSame(['own', 'grouped', 'count', null, [], ['status'], true], $calls[1]);
		$this->assertSame(['reg', 'empty', 'count', null, [], null, true], $calls[2]);
		$this->assertSame(['scalar' => null], $this->aggregates->resolveAll(new ReferringObject([]), ['scalar' => $declarations['scalar']]));

	}//end testAggregateQueriesAndEnvelopes()

	/**
	 * The first cycle in declaration order, closing on its first node.
	 *
	 * @return void
	 */
	public function testDependencyCycleFinder(): void {
		$finder = new DependencyCycleFinder();

		$this->assertNull($finder->find(['a' => ['b', 'x'], 'b' => ['c'], 'c' => []]));
		$this->assertSame(['b', 'c', 'b'], $finder->find(['a' => ['b'], 'b' => ['c'], 'c' => ['b', 'a']]));
		$this->assertSame(['a', 'a'], $finder->find(['a' => ['a'], 'b' => ['b']]));
		$this->assertSame(['b', 'd', 'b'], $finder->find(['a' => ['c'], 'c' => [], 'b' => ['d'], 'd' => ['c', 'b']]));
		$this->assertNull($finder->find([]));

	}//end testDependencyCycleFinder()

	/**
	 * A referenced object.
	 *
	 * @param string $organisation Its organisation.
	 *
	 * @return ObjectEntity The object.
	 */
	private function entity(string $organisation): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ref');
		$entity->setRegister('1');
		$entity->setSchema('2');
		$entity->setOwner('o');
		$entity->setOrganisation($organisation);
		$entity->setObject(['v' => 1]);

		return $entity;

	}//end entity()
}//end class

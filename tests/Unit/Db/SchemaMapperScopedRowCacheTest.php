<?php

/**
 * With a local memory cache, SchemaMapper::findInIds() and findBySlugInIds() probe narrow columns
 * and take the stored row from the cross-request row cache, as find() does.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-write-sub-500ms/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\ICacheFactory;

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaRowCache
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads
 * @uses \OCA\OpenRegister\Db\MappingMapper
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaMapperServices
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaReadTrace
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationFilterOptions
 * @uses \OCA\OpenRegister\Db\Tenancy\TenancyServices
 * @uses \OCA\OpenRegister\Service\WritePhase
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 */
class SchemaMapperScopedRowCacheTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const PROBE = [
		'id' => 7,
		'uuid' => 'uuid-seven',
		'slug' => 'seven',
		'title' => 'Seven',
		'application' => 'app',
		'updated' => '2026-11-16 10:00:00',
	];

	private const ROW = self::PROBE + ['description' => 'The stored row', 'version' => '1.0.0'];

	private const NARROW = '#1 select(id, uuid, slug, title, application, updated)';

	/**
	 * The cache the mapper's row cache writes to.
	 *
	 * @var ArrayCacheDouble
	 */
	private ArrayCacheDouble $store;

	protected function setUp(): void {
		$this->store = new ArrayCacheDouble();
	}

	public function testFindInIdsProbesNarrowColumnsThenReadsTheRowByIdAndStoresIt(): void {
		$this->queuedRows = [[self::PROBE], [self::ROW]];
		$schema = $this->makeMapper()->findInIds(7, [7, 9]);

		self::assertNotNull($schema);
		self::assertSame('The stored row', $schema->getDescription());
		self::assertSame([self::NARROW, '#2 select(*)'], $this->selects());
		self::assertContains('#2 where(eq(id, :p3, NULL))', $this->queryLog);
		self::assertSame(['row:7:2026-11-16 10:00:00'], array_keys($this->store->values));
		self::assertSame(2, $this->executions());
	}

	public function testFindInIdsTakesACachedRowWithoutReadingItAgain(): void {
		$this->store->values['row:7:2026-11-16 10:00:00'] = self::ROW;
		$this->queuedRows = [[], [self::PROBE]];
		$schema = $this->makeMapper()->findInIds('uuid-seven', [7]);

		self::assertSame('The stored row', $schema?->getDescription());
		self::assertSame(2, $this->executions());
		self::assertNotContains('#3 select(*)', $this->selects());
	}

	public function testFindInIdsMovesToTheNextArmWhenTheProbedRowIsGone(): void {
		// Id arm probes a row that is deleted before the read; the uuid and slug arms miss.
		$this->queuedRows = [[self::PROBE], [], [], []];
		$schema = $this->makeMapper()->findInIds(7, [7]);

		self::assertNull($schema);
		self::assertSame(4, $this->executions());
		self::assertSame([], $this->store->values);
	}

	public function testFindBySlugInIdsProbesNarrowColumnsAndCompletesFromTheCache(): void {
		$this->store->values['row:7:2026-11-16 10:00:00'] = self::ROW;
		$this->queuedRows = [[self::PROBE]];
		$schema = $this->makeMapper()->findBySlugInIds('Seven', [7]);

		self::assertSame('The stored row', $schema?->getDescription());
		self::assertSame([self::NARROW], $this->selects());
		self::assertSame(1, $this->executions());
	}

	public function testFindBySlugInIdsMissReadsNothingMore(): void {
		$this->queuedRows = [[]];
		self::assertNull($this->makeMapper()->findBySlugInIds('seven', [7]));
		self::assertSame(1, $this->executions());
	}

	public function testWithoutALocalCacheTheScopedReadsSelectWholeRows(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isLocalCacheAvailable')->willReturn(false);
		$this->queuedRows = [[self::ROW], [self::ROW]];
		$mapper = $this->makeMapper(factory: $factory);

		self::assertSame('The stored row', $mapper->findInIds(7, [7])?->getDescription());
		self::assertSame('The stored row', $mapper->findBySlugInIds('seven', [7])?->getDescription());
		self::assertSame(['#1 select(*)', '#2 select(*)'], $this->selects());
		self::assertSame(2, $this->executions());
	}

	/**
	 * The select clauses the recorded queries set.
	 *
	 * @return array<int, string>
	 */
	private function selects(): array {
		return array_values(array_filter($this->queryLog, static fn (string $line): bool => preg_match('/^#\\d+ select\\(/', $line) === 1));
	}

	private function executions(): int {
		return count(array_filter($this->queryLog, static fn (string $line): bool => str_ends_with($line, 'executeQuery()')));
	}

	private function makeMapper(?ICacheFactory $factory = null): SchemaMapper {
		if ($factory === null) {
			$factory = $this->createMock(ICacheFactory::class);
			$factory->method('isLocalCacheAvailable')->willReturn(true);
			$factory->method('createLocal')->willReturn($this->store);
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new SchemaMapper(
			db: $db,
			tenancy: new TenancyServices(
				organisationMapper: $this->createMock(OrganisationMapper::class),
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class)
			),
			appConfig: $this->createMock(IAppConfig::class),
			services: new SchemaMapperServices(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				validator: $this->createMock(PropertyValidatorHandler::class),
				logger: $this->createMock(LoggerInterface::class),
				cacheFactory: $factory
			)
		);
	}//end makeMapper()
}//end class

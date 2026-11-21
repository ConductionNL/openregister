<?php

/**
 * With a local memory cache, SchemaMapper::find() probes narrow columns and takes the stored row
 * from the cross-request row cache keyed on id and `updated` stamp.
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
use OCP\AppFramework\Db\DoesNotExistException;
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
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaLookup
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaRowCache
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
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
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 */
class SchemaMapperRowCacheTest extends TestCase {
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

	/**
	 * The cache the mapper's row cache writes to.
	 *
	 * @var ArrayCacheDouble
	 */
	private ArrayCacheDouble $store;

	protected function setUp(): void {
		$this->store = new ArrayCacheDouble();
	}

	public function testAColdHostProbesNarrowColumnsThenReadsTheRowByIdAndStoresIt(): void {
		$this->queuedRows = [[], [self::PROBE], [self::ROW]];
		$schema = $this->makeMapper()->find('seven', [], true, false);

		self::assertSame('The stored row', $schema->getDescription());
		self::assertSame(
			[
				'#1 select(id, uuid, slug, title, application, updated)',
				'#2 select(id, uuid, slug, title, application, updated)',
				'#3 select(*)',
			],
			$this->selects()
		);
		self::assertContains('#3 where(eq(id, :p3, NULL))', $this->queryLog);
		self::assertContains('#3 param :p3 = 7, 1', $this->queryLog);
		self::assertSame(['row:7:2026-11-16 10:00:00'], array_keys($this->store->values));
		self::assertSame(3, $this->executions());
	}

	public function testAnotherRequestTakesTheStoredRowFromTheCache(): void {
		$this->queuedRows = [[self::PROBE], [self::ROW]];
		$this->makeMapper()->find(7, [], true, false);
		$this->queryLog = [];

		$this->queuedRows = [[self::PROBE]];
		$schema = $this->makeMapper()->find(7, [], true, false);

		self::assertSame('The stored row', $schema->getDescription());
		self::assertSame(1, $this->executions());
		self::assertNotContains('#4 select(*)', $this->selects());
	}

	public function testAChangedStampReadsTheRowAgain(): void {
		$this->queuedRows = [[self::PROBE], [self::ROW]];
		$this->makeMapper()->find(7, [], true, false);
		$this->queryLog = [];

		$newer = ['updated' => '2026-11-16 11:00:00', 'description' => 'Edited'] + self::ROW;
		$this->queuedRows = [[['updated' => '2026-11-16 11:00:00'] + self::PROBE], [$newer]];
		$schema = $this->makeMapper()->find(7, [], true, false);

		self::assertSame('Edited', $schema->getDescription());
		self::assertSame(2, $this->executions());
	}

	public function testARowGoneBetweenProbeAndReadIsNotFound(): void {
		$this->queuedRows = [[self::PROBE], []];

		$this->expectException(DoesNotExistException::class);
		$this->makeMapper()->find(7, [], true, false);
	}

	public function testClearingTheFindCacheDropsTheStoredRows(): void {
		$this->queuedRows = [[self::PROBE], [self::ROW]];
		$mapper = $this->makeMapper();
		$mapper->find(7, [], true, false);

		$mapper->clearFindCache(schemaId: 7);

		self::assertSame(['row:7:'], $this->store->cleared);
		self::assertSame([], $this->store->values);
	}

	public function testWithoutALocalCacheTheArmsReadWholeRows(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isLocalCacheAvailable')->willReturn(false);
		$this->queuedRows = [[self::ROW]];
		$this->makeMapper(factory: $factory)->find(7, [], true, false);

		self::assertSame(['#1 select(*)'], $this->selects());
		self::assertSame(1, $this->executions());
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

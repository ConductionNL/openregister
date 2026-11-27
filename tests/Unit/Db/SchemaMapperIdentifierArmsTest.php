<?php

/**
 * SchemaMapper::find() and findInIds() read one identifier column per query, and find()'s request
 * cache is shared across RBAC settings but never widens tenancy.
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

/**
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaLookup
 * @uses   \OCA\OpenRegister\Db\IdentifierArms
 * @uses   \OCA\OpenRegister\Db\RequestIdentityMap
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
class SchemaMapperIdentifierArmsTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const ROW = ['id' => 7, 'uuid' => 'uuid-seven', 'slug' => 'seven', 'title' => 'Seven', 'application' => 'app'];

	/**
	 * Log lines the mapper wrote.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logs = [];

	public function testEachQueryMatchesOneColumnAndAHitStopsTheWalk(): void {
		$this->queuedRows = [[], [self::ROW]];
		$schema = $this->makeMapper()->find('seven', [], true, false);

		self::assertSame(7, $schema->getId());
		// A non-numeric identifier that is not shaped like a uuid tries the slug first (IdentifierArms).
		self::assertSame(['#1 where(eq(lower(slug), :p1, NULL))', '#2 where(eq(uuid, :p2, NULL))'], $this->wheres());
		self::assertSame(2, $this->executions());
	}

	public function testAnExactIdNeedsOneQuery(): void {
		$this->queuedRows = [[self::ROW], [self::ROW], [self::ROW]];
		$this->makeMapper()->find('7', [], true, false);

		self::assertSame(['#1 where(eq(id, :p1, NULL))'], $this->wheres());
	}

	public function testAMissTriesEveryColumnAndThrows(): void {
		$this->queuedRows = [[], [], []];
		try {
			$this->makeMapper()->find('12', [], true, false);
			self::fail('A missing schema must throw.');
		} catch (DoesNotExistException $e) {
			self::assertSame("Schema not found (id='12', multitenancy=false, rbac=true)", $e->getMessage());
		}

		self::assertSame(3, $this->executions());
	}

	public function testTheOtherRbacSettingIsServedFromTheCache(): void {
		$this->queuedRows = [[self::ROW]];
		$mapper = $this->makeMapper();
		$first = $mapper->find('seven', [], true, true);
		$this->queryLog = [];

		self::assertSame($first, $mapper->find('seven', [], false, true));
		self::assertSame($first, $mapper->find('uuid-seven', [], false, true));
		self::assertSame(0, $this->executions());
	}

	public function testAnIdFoundUnderTheOrganisationFilterAnswersTheUnfilteredLookup(): void {
		$this->queuedRows = [[self::ROW]];
		$mapper = $this->makeMapper();
		$first = $mapper->find(7, [], true, true);
		$this->queryLog = [];

		self::assertSame($first, $mapper->find(7, [], true, false));
		self::assertSame(0, $this->executions());
	}

	public function testAnUnfilteredHitIsNeverServedToAFilteredLookup(): void {
		$this->queuedRows = [[self::ROW], [self::ROW]];
		$mapper = $this->makeMapper();
		$mapper->find(7, [], true, false);
		$mapper->find('seven', [], true, false);
		$this->queryLog = [];

		$mapper->find(7, [], true, true);

		self::assertSame(1, $this->executions());
		self::assertCount(1, array_filter($this->queryLog, static fn (string $line): bool => str_ends_with($line, ' andWhere(isNull(organisation))')));
	}

	public function testAClearedSchemaIsReadAgain(): void {
		$this->queuedRows = [[self::ROW], [self::ROW]];
		$mapper = $this->makeMapper();
		$mapper->find(7, [], true, true);
		$mapper->clearFindCache(schemaId: 7);
		$this->queryLog = [];

		$mapper->find(7, [], false, false);

		self::assertSame(1, $this->executions());
	}

	public function testFindInIdsWalksTheSameArmsNarrowedToTheSet(): void {
		$this->queuedRows = [[], [self::ROW]];
		$schema = $this->makeMapper()->findInIds('seven', [7, 9]);

		self::assertSame(7, $schema?->getId());
		self::assertSame(['#1 where(eq(lower(slug), :p1, NULL))', '#2 where(eq(uuid, :p3, NULL))'], $this->wheres());
		self::assertSame(
			['#1 andWhere(in(id, :p2, NULL))', '#2 andWhere(in(id, :p4, NULL))'],
			array_values(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, ' andWhere(in(id')))
		);
		self::assertSame(2, $this->executions());
	}

	public function testFindInIdsStopsAtAnExactId(): void {
		$this->queuedRows = [[self::ROW], [self::ROW], [self::ROW]];
		$schema = $this->makeMapper()->findInIds(7, [7]);

		self::assertSame(7, $schema?->getId());
		self::assertSame(['#1 where(eq(id, :p1, NULL))'], $this->wheres());
		self::assertSame(1, $this->executions());
	}

	public function testFindInIdsReturnsNullAfterEveryArmMisses(): void {
		$this->queuedRows = [[], [], []];

		self::assertNull($this->makeMapper()->findInIds('12', [7, 9]));
		self::assertSame(3, $this->executions());
	}

	public function testFindInIdsWithNoUsableIdsReadsNothing(): void {
		self::assertNull($this->makeMapper()->findInIds('seven', [0, -1, 'x']));
		self::assertSame(0, $this->executions());
	}

	/**
	 * The where clauses the recorded queries set.
	 *
	 * @return array<int, string>
	 */
	private function wheres(): array {
		return array_values(array_filter($this->queryLog, static fn (string $line): bool => preg_match('/^#\\d+ where\\(/', $line) === 1));
	}

	private function executions(): int {
		return count(array_filter($this->queryLog, static fn (string $line): bool => str_ends_with($line, 'executeQuery()')));
	}

	private function makeMapper(): SchemaMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['error']);
					$this->logs[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

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
				logger: $logger
			)
		);
	}//end makeMapper()
}//end class

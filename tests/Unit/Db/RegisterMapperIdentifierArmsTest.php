<?php

/**
 * RegisterMapper::find() reads one identifier column per query, stops at the
 * first hit, and only probes before the filters on a miss.
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperServices;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\RegisterMapper
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Db\MappingMapper
 * @uses \OCA\OpenRegister\Db\RegisterLookup
 * @uses \OCA\OpenRegister\Db\RegisterMapperServices
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationFilterOptions
 * @uses \OCA\OpenRegister\Db\Tenancy\TenancyServices
 * @uses \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 */
class RegisterMapperIdentifierArmsTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const ROW = ['id' => 7, 'uuid' => 'uuid-seven', 'slug' => 'seven', 'title' => 'Seven'];

	/**
	 * Log lines the mapper wrote, as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logs = [];

	public function testAnExactIdNeedsOneQueryAndNoProbe(): void {
		$this->queuedRows = [[self::ROW], [self::ROW], [self::ROW]];
		$register = $this->makeMapper()->find('7', false, false);

		self::assertSame(7, $register->getId());
		self::assertSame(['#1 where(eq(id, :p1, NULL))'], $this->wheres());
		self::assertSame(1, $this->executions());
		self::assertNotContains(['debug', '[RegisterMapper] Register exists before filters'], $this->logs);
	}

	public function testEachQueryMatchesOneColumnAndAHitStopsTheWalk(): void {
		$this->queuedRows = [[], [self::ROW], [self::ROW]];
		$register = $this->makeMapper()->find('Seven', false, false);

		self::assertSame(7, $register->getId());
		self::assertSame(['#1 where(eq(uuid, :p1, NULL))', '#2 where(eq(lower(slug), :p2, NULL))'], $this->wheres());
		self::assertContains('#2 param :p2 = seven, 2', $this->queryLog);
		self::assertSame(2, $this->executions());
	}

	public function testEveryArmIsOrderedOldestFirstAndCappedToOneRow(): void {
		$this->queuedRows = [[], [], [self::ROW]];
		$this->makeMapper()->find('7', false, false);

		self::assertSame(
			['#1 orderBy(id, ASC)', '#2 orderBy(id, ASC)', '#3 orderBy(id, ASC)'],
			array_values(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, ' orderBy(')))
		);
		self::assertSame(
			['#1 setMaxResults(1)', '#2 setMaxResults(1)', '#3 setMaxResults(1)'],
			array_values(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, ' setMaxResults(')))
		);
	}

	public function testTheOrganisationFilterIsAppliedToEveryArm(): void {
		$this->queuedRows = [[], [], [self::ROW]];
		$this->makeMapper()->find('7', false, true);

		self::assertSame(
			['#1 andWhere(isNull(organisation))', '#2 andWhere(isNull(organisation))', '#3 andWhere(isNull(organisation))'],
			array_values(array_filter($this->queryLog, static fn (string $line): bool => str_ends_with($line, ' andWhere(isNull(organisation))')))
		);
	}

	public function testAMissProbesUnfilteredAndReportsAFilteredRowAsAnError(): void {
		// Three filtered arms miss; the unfiltered probe's id arm finds the row.
		$this->queuedRows = [[], [], [], [self::ROW]];
		try {
			$this->makeMapper()->find('7', false, true);
			self::fail('A filtered-away register must throw.');
		} catch (DoesNotExistException) {
			// Expected.
		}

		self::assertSame(4, $this->executions());
		// The filter is on the three lookups and not on the probe.
		self::assertSame(
			['#1 andWhere(isNull(organisation))', '#2 andWhere(isNull(organisation))', '#3 andWhere(isNull(organisation))'],
			array_values(array_filter($this->queryLog, static fn (string $line): bool => str_ends_with($line, ' andWhere(isNull(organisation))')))
		);
		self::assertContains('#4 where(eq(id, :p4, NULL))', $this->queryLog);
		self::assertContains(['debug', '[RegisterMapper] Register exists before filters'], $this->logs);
		self::assertContains(['error', '[RegisterMapper] Register not found after filters'], $this->logs);
	}

	public function testANonNumericMissProbesTwoColumnsAndIsAnOrdinaryMiss(): void {
		$this->queuedRows = [[], [], [], []];
		try {
			$this->makeMapper()->find('openconnector', false, false);
			self::fail('A missing register must throw.');
		} catch (DoesNotExistException) {
			// Expected.
		}

		self::assertSame(4, $this->executions());
		self::assertSame([], array_filter($this->logs, static fn (array $log): bool => $log[0] === 'error'));
		self::assertContains(['warning', '[RegisterMapper] Register not resolvable before filters'], $this->logs);
		self::assertContains(['debug', '[RegisterMapper] Register does not exist'], $this->logs);
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

	private function makeMapper(): RegisterMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug', 'info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = [$level, $message];
				}
			);
		}

		return new RegisterMapper(
			db: $db,
			tenancy: new TenancyServices($this->createMock(OrganisationMapper::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class)),
			appConfig: $this->createMock(IAppConfig::class),
			services: new RegisterMapperServices(
				schemaMapper: $this->createMock(SchemaMapper::class),
				container: $this->createMock(ContainerInterface::class),
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $logger
			)
		);
	}//end makeMapper()
}//end class

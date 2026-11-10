<?php

/**
 * Behaviour test for the failure and skip paths of ArchivalRetentionTask.
 *
 * Asserts that the sweep deletes nothing when registers, schemas, the table
 * check or the scan fail, skips schemas without an archival annotation, keeps
 * rows it cannot resolve or that have no uuid, counts a refused delete as not
 * deleted, and survives a missing archivist group or a failing notifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\BackgroundJob\ArchivalRetentionTask;
use OCA\OpenRegister\BackgroundJob\Support\ArchivalRetentionPlatform;
use OCA\OpenRegister\BackgroundJob\Support\ArchivalRetentionSources;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use OCA\OpenRegister\Service\Object\ObjectDeletion;

class ArchivalRetentionTaskEdgeBehaviourTest extends TestCase {

	/** @var RegisterMapperInterface&MockObject Register source. */
	private RegisterMapperInterface $registers;

	/** @var SchemaMapperInterface&MockObject Schema source. */
	private SchemaMapperInterface $schemas;

	/** @var MagicMapperInterface&MockObject Magic table access. */
	private MagicMapperInterface $magic;

	/** @var ObjectOperationsInterface&MockObject Object deletion. */
	private ObjectOperationsInterface $objects;

	/** @var RetentionServiceInterface&MockObject Legal-hold check. */
	private RetentionServiceInterface $holds;

	/** @var ContainerInterface&MockObject Lazy service lookup. */
	private ContainerInterface $container;

	/** @var IDBConnection&MockObject The database. */
	private IDBConnection $db;

	/** @var list<array{string, string}> Log lines as [level, message]. */
	private array $logged = [];

	protected function setUp(): void {
		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->magic = $this->createMock(MagicMapperInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->holds = $this->createMock(RetentionServiceInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->db = $this->createMock(IDBConnection::class);
	}

	private function sweep(): void {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message) use ($level): void {
				$this->logged[] = [$level, $message];
			});
		}

		$task = new ArchivalRetentionTask(
			$this->createMock(ITimeFactory::class),
			new ArchivalRetentionSources($this->db, $this->registers, $this->schemas, $this->magic),
			new ArchivalRetentionPlatform($this->objects, $this->holds, $this->container, $logger)
		);
		$run = new ReflectionMethod($task, 'run');
		$run->setAccessible(true);
		$run->invoke($task, null);
	}

	private function logged(string $level, string $needle): bool {
		foreach ($this->logged as [$at, $message]) {
			if ($at === $level && str_contains($message, $needle) === true) {
				return true;
			}
		}

		return false;
	}

	private static function register(array $schemaIds): Register {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('logs');
		$register->setSchemas($schemaIds);
		return $register;
	}

	private static function schema(mixed $configuration): Schema {
		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('call_log');
		$schema->setConfiguration($configuration);
		return $schema;
	}

	private static function archived(): Schema {
		return self::schema(['x-openregister-archival' => ['retention' => ['default' => 'P30D']]]);
	}

	private function rows(array $rows): void {
		$cursor = $this->createMock(IResult::class);
		$cursor->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($cursor);
		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	private static function expiredRow(?string $uuid): array {
		$row = ['_created' => (new DateTimeImmutable('-90 days'))->format('Y-m-d H:i:s')];
		if ($uuid !== null) {
			$row['_uuid'] = $uuid;
		}

		return $row;
	}

	private function oneArchivedSchema(): void {
		$this->registers->method('findAll')->willReturn([self::register([42])]);
		$this->schemas->method('find')->with(42)->willReturn(self::archived());
		$this->magic->method('tableExistsForRegisterSchema')->willReturn(true);
	}

	public function testAFailingRegisterLoadDeletesNothing(): void {
		$this->registers->method('findAll')->willThrowException(new Exception('db down'));
		$this->schemas->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('deleteObject');
		$this->sweep();
		$this->assertTrue($this->logged('error', 'Failed to load registers: db down'));
	}

	public function testEntriesThatAreNotRegistersAreSkipped(): void {
		$this->registers->method('findAll')->willReturn(['not a register', null]);
		$this->schemas->expects($this->never())->method('find');
		$this->sweep();
		$this->assertSame([], $this->logged);
	}

	public function testAnUnloadableSchemaIsSkippedWithAWarning(): void {
		$this->registers->method('findAll')->willReturn([self::register(['7'])]);
		$this->schemas->method('find')->with(7)->willThrowException(new Exception('gone'));
		$this->magic->expects($this->never())->method('tableExistsForRegisterSchema');
		$this->sweep();
		$this->assertTrue($this->logged('warning', 'Schema #7 not loadable: gone'));
	}

	public function testSchemasWithoutAnArchivalAnnotationAreNotSwept(): void {
		$this->registers->method('findAll')->willReturn([self::register([1, 2])]);
		$this->schemas->method('find')->willReturnOnConsecutiveCalls(
			self::schema(null),
			self::schema(['x-openregister-archival' => 'P30D'])
		);
		$this->magic->expects($this->never())->method('tableExistsForRegisterSchema');
		$this->sweep();
		$this->assertSame([], $this->logged);
	}

	public function testAMissingTableIsNotScanned(): void {
		$this->registers->method('findAll')->willReturn([self::register([42])]);
		$this->schemas->method('find')->willReturn(self::archived());
		$this->magic->method('tableExistsForRegisterSchema')->willReturn(false);
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->sweep();
		$this->assertSame([], $this->logged);
	}

	public function testAFailingTableCheckIsNotScanned(): void {
		$this->registers->method('findAll')->willReturn([self::register([42])]);
		$this->schemas->method('find')->willReturn(self::archived());
		$this->magic->method('tableExistsForRegisterSchema')->willThrowException(new Exception('no info schema'));
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->sweep();
		$this->assertTrue($this->logged('warning', 'Cannot check magic-table existence for schema "call_log": no info schema'));
	}

	public function testAFailingScanIsLoggedAndStillSummarised(): void {
		$this->oneArchivedSchema();
		$this->db->method('getQueryBuilder')->willThrowException(new Exception('table locked'));
		$this->objects->expects($this->never())->method('deleteObject');
		$this->sweep();
		$this->assertTrue($this->logged('error', 'Sweep failed on schema "call_log": table locked'));
		$this->assertTrue($this->logged('info', 'scanned=0 expired=0 deleted=0 held=0 unresolved=0'));
	}

	public function testAnExpiredRowWithoutAUuidIsCountedButNotTouched(): void {
		$this->oneArchivedSchema();
		$this->rows([self::expiredRow(null)]);
		$this->magic->expects($this->never())->method('find');
		$this->sweep();
		$this->assertTrue($this->logged('info', 'scanned=1 expired=1 deleted=0 held=0 unresolved=0'));
	}

	public function testARowWhoseObjectCannotBeReadIsKeptAsUnresolved(): void {
		$this->oneArchivedSchema();
		$this->rows([self::expiredRow('u1')]);
		$this->magic->method('find')->willThrowException(new Exception('corrupt'));
		$this->objects->expects($this->never())->method('deleteObject');
		$this->sweep();
		$this->assertTrue($this->logged('warning', 'Kept row on "call_log" uuid "u1": its legal-hold status could not be read: corrupt'));
		$this->assertTrue($this->logged('info', 'scanned=1 expired=1 deleted=0 held=0 unresolved=1'));
	}

	public function testARefusedOrFailingDeleteIsNotCountedAsDeleted(): void {
		$this->oneArchivedSchema();
		$this->rows([self::expiredRow('u1'), self::expiredRow('u2')]);
		$this->magic->method('find')->willReturn(new ObjectEntity());
		$this->holds->method('hasActiveLegalHold')->willReturn(false);
		$this->objects->method('deleteObject')->willReturnCallback(static function (ObjectDeletion $deletion, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false): bool {
			$uuid = $deletion->uuid;
			if ($uuid === 'u2') {
				throw new Exception('locked');
			}

			return false;
		});
		$this->sweep();
		$this->assertTrue($this->logged('warning', 'Delete failed on schema "call_log" uuid "u2": locked'));
		$this->assertTrue($this->logged('info', 'scanned=2 expired=2 deleted=0 held=0 unresolved=0'));
	}

	public function testAHeldRowWithoutAnArchivistGroupNotifiesNobody(): void {
		$this->oneArchivedSchema();
		$this->rows([self::expiredRow('u1')]);
		$this->magic->method('find')->willReturn(new ObjectEntity());
		$this->holds->method('hasActiveLegalHold')->willReturn(true);
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('notify');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('archivaris')->willReturn(null);
		$this->container->method('get')->willReturnMap([[IGroupManager::class, $groups], [INotificationManager::class, $notifications]]);
		$this->sweep();
		$this->assertTrue($this->logged('info', 'held=1'));
		$this->assertFalse($this->logged('warning', 'Hold notify error'));
	}

	public function testAFailingNotifierDoesNotStopTheSweep(): void {
		$this->oneArchivedSchema();
		$this->rows([self::expiredRow('u1')]);
		$this->magic->method('find')->willReturn(new ObjectEntity());
		$this->holds->method('hasActiveLegalHold')->willReturn(true);
		$this->container->method('get')->willThrowException(new Exception('no notifier'));
		$this->sweep();
		$this->assertTrue($this->logged('warning', 'Hold notify error: no notifier'));
	}
}

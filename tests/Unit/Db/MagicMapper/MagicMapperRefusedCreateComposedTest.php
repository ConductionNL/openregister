<?php

/**
 * A refused create over a taken identifier, through the real save chain over a one-row table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The real saveObjectToRegisterSchemaTable / updateObjectInRegisterSchemaTable /
 * insertObjectInRegisterSchemaTable chain over a fake one-row table whose UPDATE
 * honours the WHERE conditions it is given.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFlaggedEncryption
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRowMetadata
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPropertyDate
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDroppedProperties
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPropertyColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses \OCA\OpenRegister\Db\EmptyPropertyClaim
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 *
 * @spec openspec/changes/create-over-taken-identifier-runs-update-path/specs/object-lifecycle/spec.md
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses \OCA\OpenRegister\Exception\HookStoppedException
 * @uses \OCA\OpenRegister\Exception\ObjectExistsException
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite
 */
class MagicMapperRefusedCreateComposedTest extends TestCase {

	private const UUID = '11111111-2222-3333-4444-555555555555';

	/** @var array{exists: bool, deleted: bool, status: string} */
	public array $row = ['exists' => true, 'deleted' => false, 'status' => 'open'];

	/** Executed statements: ['update'|'insert', matched-rows]. */
	public array $log = [];

	/** Hook run at the Nth findObjectInRegisterSchemaTable call (1-based). */
	public array $onFind = [];

	public int $finds = 0;

	public ?\Closure $onUpdating = null;

	public ?\Closure $onUpdatingSecond = null;

	public array $updatingStatuses = [];

	private MagicMapper $mapper;

	private function qb(): IQueryBuilder {
		$state = (object)['type' => null, 'conds' => [], 'values' => []];
		$expr  = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($c, $v): string => 'eq:' . $c . '=' . $v);
		$expr->method('isNull')->willReturnCallback(static fn ($c): string => 'isNull:' . $c);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('update')->willReturnCallback(function () use ($qb, $state) { $state->type = 'update'; return $qb; });
		$qb->method('insert')->willReturnCallback(function () use ($qb, $state) { $state->type = 'insert'; return $qb; });
		$qb->method('set')->willReturnCallback(function ($c, $v) use ($qb, $state) { $state->values[$c] = $v; return $qb; });
		$qb->method('setValue')->willReturnCallback(function ($c, $v) use ($qb, $state) { $state->values[$c] = $v; return $qb; });
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($v) => is_scalar($v) ? (string)$v : 'x');
		$qb->method('expr')->willReturn($expr);
		$qb->method('where')->willReturnCallback(function ($c) use ($qb, $state) { $state->conds[] = $c; return $qb; });
		$qb->method('andWhere')->willReturnCallback(function ($c) use ($qb, $state) { $state->conds[] = $c; return $qb; });
		$qb->method('executeStatement')->willReturnCallback(function () use ($state): int {
			if ($state->type === 'insert') {
				if ($this->row['exists'] === true) {
					$this->log[] = ['insert', 'unique-violation'];
					throw new class('dup') extends DbException {
						public function getReason(): ?int {
							return DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
						}
					};
				}

				$this->row = ['exists' => true, 'deleted' => false, 'status' => 'new'];
				$this->log[] = ['insert', 1];
				return 1;
			}

			$match = $this->row['exists'];
			foreach ($state->conds as $c) {
				if ($c === 'isNull:_deleted' && $this->row['deleted'] === true) {
					$match = false;
				}

				if (str_starts_with($c, 'eq:_status=') && substr($c, 11) !== $this->row['status']) {
					$match = false;
				}
			}

			if ($match === true) {
				$this->row['deleted'] = false;
				if (array_key_exists('_deleted', $state->values) === true && $state->values['_deleted'] !== null && $state->values['_deleted'] !== 'x') {
					$this->row['deleted'] = true;
				}
			}

			$this->log[] = ['update', (int)$match];
			return (int)$match;
		});
		return $qb;
	}

	protected function setUp(): void {
		$cache = new \ReflectionProperty(MagicNumberColumnSizes::class, 'cache');
		$cache->setValue(null, ['oc_openregister_table_3_7' => [], 'openregister_table_3_7' => []]);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			if ($event instanceof ObjectCreatingEvent && (($event->getObject()->getObject()['status'] ?? null) === 'archived')) {
				$event->setErrors(['message' => 'refused-create']);
				$event->stopPropagation();
			}

			if ($event instanceof ObjectUpdatingEvent) {
				$this->updatingStatuses[] = $event->getNewObject()->getObject()['status'] ?? null;
				if ($this->onUpdating !== null) {
					$hook = $this->onUpdating;
					$this->onUpdating = $this->onUpdatingSecond;
					$this->onUpdatingSecond = null;
					$hook();
				}
			}
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->qb());
		$db->method('lastInsertId')->willReturn(9);

		$services  = [
			DateTimeNormalizer::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverter::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => ($services[$id] ?? null));

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs([
				$db,
				$this->createMock(SchemaMapper::class),
				$this->createMock(RegisterMapper::class),
				$this->createMock(IConfig::class),
				$events,
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(IUserManager::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(LoggerInterface::class),
				$this->createMock(AppSettingsInterface::class),
				$container,
			])
			->onlyMethods(['ensureTableForRegisterSchema', 'getTableNameForRegisterSchema', 'findObjectInRegisterSchemaTable', 'convertRowToObjectEntity'])
			->getMock();
		$mapper->method('ensureTableForRegisterSchema')->willReturn(true);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_3_7');
		$mapper->method('findObjectInRegisterSchemaTable')->willReturnCallback(function (): ?array {
			$this->finds++;
			if (isset($this->onFind[$this->finds]) === true) {
				($this->onFind[$this->finds])();
			}

			if ($this->row['exists'] === false) {
				return null;
			}

			$ret = ['_id' => 5, '_uuid' => self::UUID, '_deleted' => ($this->row['deleted'] ? '{"by":"x"}' : null)];
			if ($this->finds === 3 && $this->afterFind3 !== null) {
				($this->afterFind3)();
			}

			return $ret;
		});
		$mapper->method('convertRowToObjectEntity')->willReturnCallback(function (array $row): ObjectEntity {
			$e = new ObjectEntity();
			$e->setId(5);
			$e->setUuid(self::UUID);
			$e->setObject(['status' => 'open']);
			if (empty($row['_deleted']) === false) {
				$e->setDeleted(['deletedBy' => 'x']);
			}

			return $e;
		});
		$this->mapper = $mapper;
	}

	private function create(string $status): void {
		$e = new ObjectEntity();
		$e->setUuid(self::UUID);
		$e->setRegister('3');
		$e->setSchema('7');
		$e->setObject(['status' => $status]);
		$r = new Register();
		$r->setId(3);
		$s = new Schema();
		$s->setId(7);
		$this->mapper->insert(entity: $e, register: $r, schema: $s);
	}

	private function assertRefusedAndStaysDeletedOrGone(string $expect): void {
		try {
			$this->create('archived');
			$this->fail('refused create wrote: ' . json_encode($this->log) . ' row=' . json_encode($this->row));
		} catch (HookStoppedException $e) {
			$this->assertSame('refused-create', $e->getMessage());
		}

		if ($expect === 'gone') {
			$this->assertFalse($this->row['exists'], 'row re-inserted: ' . json_encode($this->log));
		} else {
			$this->assertTrue($this->row['deleted'], 'row resurrected: ' . json_encode($this->log));
		}
	}

	/** Baseline: a refused create over a live row is written as an update. */
	public function testRefusedCreateOverLiveRowUpdates(): void {
		$this->create('archived');
		$this->assertSame([['update', 1]], $this->log);
		$this->assertFalse($this->row['deleted']);
	}

	/** Delete moments, by find call: 1 = updateRefusedCreate read, 2 = updateTakenIdentifier re-read, 3 = save's existence read. */
	public static function deleteMoments(): array {
		$out = [];
		foreach ([1, 2, 3] as $n) {
			$out["soft before find $n"] = [$n, 'soft'];
			$out["hard before find $n"] = [$n, 'hard'];
		}

		return $out;
	}

	/** @dataProvider deleteMoments */
	public function testDeleteBeforeAnyRead(int $find, string $kind): void {
		$this->onFind[$find] = function () use ($kind): void {
			if ($kind === 'soft') {
				$this->row['deleted'] = true;
			} else {
				$this->row['exists'] = false;
			}
		};
		$this->assertRefusedAndStaysDeletedOrGone($kind === 'soft' ? 'deleted' : 'gone');
	}

	/** Delete inside ObjectUpdatingEvent (RW76's finding). */
	public function testSoftDeleteInUpdatingListener(): void {
		$this->onUpdating = function (): void { $this->row['deleted'] = true; };
		$this->assertRefusedAndStaysDeletedOrGone('deleted');
	}

	public function testHardDeleteInUpdatingListener(): void {
		$this->onUpdating = function (): void { $this->row['exists'] = false; };
		$this->assertRefusedAndStaysDeletedOrGone('gone');
	}

	/** Delete after save's existence read, right before the UPDATE runs (the last moment). */
	public function testSoftDeleteAfterLastRead(): void {
		$this->afterFind3 = function (): void { $this->row['deleted'] = true; };
		$this->assertRefusedAndStaysDeletedOrGone('deleted');
	}

	public function testHardDeleteAfterLastRead(): void {
		$this->afterFind3 = function (): void { $this->row['exists'] = false; };
		$this->assertRefusedAndStaysDeletedOrGone('gone');
	}

	public ?\Closure $afterFind3 = null;

	/** Plain lost claim (create not refused) over a soft-deleted row: still writes, as before. */
	public function testPlainLostClaimOverSoftDeletedRowStillWrites(): void {
		$this->row['deleted'] = true;
		$this->create('closed');
		$this->assertSame('update', $this->log[0][0]);
		$this->assertSame(1, $this->log[0][1]);
	}

	/** After a refused create, a later ordinary write of the same uuid is unconditional again. */
	public function testEntryClearedAfterRefusedWrite(): void {
		$this->create('archived');
		$this->row['deleted'] = true;
		$this->log = [];
		$this->create('closed');
		$this->assertSame([['update', 1]], $this->log);
	}

	/** And after a refusal thrown from the live-only write. */
	public function testEntryClearedAfterThrownLiveOnlyWrite(): void {
		$this->onUpdating = function (): void { $this->row['deleted'] = true; };
		try {
			$this->create('archived');
		} catch (HookStoppedException) {
		}

		$this->log = [];
		$this->create('closed');
		$this->assertSame([['update', 1]], $this->log);
	}

	/** A plain write of the same uuid nested inside a refused create's ObjectUpdatingEvent is not made live-only. */
	public function testNestedPlainWriteSameUuidIsUnconditional(): void {
		$inner = null;
		$this->onUpdating = function () use (&$inner): void {
			$this->row['deleted'] = true;
			$this->log = [];
			try {
				$this->create('closed');
				$inner = $this->log;
			} catch (HookStoppedException $e) {
				$inner = 'refused';
			}

			$this->row['deleted'] = true;
		};
		$this->assertRefusedAndStaysDeletedOrGone('deleted');
		$this->assertSame([['update', 1]], $inner, 'the nested plain write took the outer refusal');
	}

	/** Nested refused create of the SAME uuid inside the outer's ObjectUpdatingEvent, then a soft delete. */
	public function testNestedRefusedCreateSameUuidThenSoftDelete(): void {
		$this->onUpdating = function (): void {
			// inner refused create (its own ObjectUpdatingEvent runs onUpdatingSecond)
			$this->create('archived');
			$this->row['deleted'] = true;
		};
		$this->onUpdatingSecond = null;
		$this->assertRefusedAndStaysDeletedOrGone('deleted');
	}
}

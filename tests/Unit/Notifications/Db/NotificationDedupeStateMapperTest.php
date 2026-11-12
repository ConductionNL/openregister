<?php

/**
 * Unit tests for NotificationDedupeStateMapper: lookup, refresh-vs-insert upsert, unique-race recovery and quiet deletes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use DateTime;
use OCA\OpenRegister\Notifications\Db\DedupeSighting;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeKey;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationQueryTrait
 * @uses   \OCA\OpenRegister\Notifications\Db\NotificationDedupeState
 * @uses   \OCA\OpenRegister\Notifications\Db\NotificationDedupeKey
 */
final class NotificationDedupeStateMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function database(bool $failing = false): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($failing) {
				if ($failing === true) {
					throw new RuntimeException('database down');
				}
				return $this->recordingBuilder();
			}
		);
		return $db;
	}

	private function log(): string {
		return implode("\n", $this->queryLog);
	}

	private static function uniqueViolation(): DbException {
		return new class('dup') extends DbException {
			public function getReason(): ?int {
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};
	}

	/**
	 * Mapper whose insert/update throw the given exceptions; null delegates to the parent.
	 */
	private function mapper(?\Throwable $onInsert = null, ?\Throwable $onUpdate = null, bool $failing = false): NotificationDedupeStateMapper {
		$mapper = new class($this->database($failing)) extends NotificationDedupeStateMapper {
			public ?\Throwable $onInsert = null;
			public ?\Throwable $onUpdate = null;
			public function insert(Entity $entity): Entity {
				if ($this->onInsert !== null) {
					throw $this->onInsert;
				}
				return parent::insert($entity);
			}
			public function update(Entity $entity): Entity {
				if ($this->onUpdate !== null) {
					throw $this->onUpdate;
				}
				return parent::update($entity);
			}
		};
		$mapper->onInsert = $onInsert;
		$mapper->onUpdate = $onUpdate;
		return $mapper;
	}

	private static function row(array $over = []): array {
		return array_merge(
			['id' => 5, 'schema_id' => 3, 'rule_key' => 'r1', 'object_uuid' => 'u-1', 'fingerprint' => 'old', 'dispatched_at' => null, 'seen_at' => null],
			$over
		);
	}

	public function testFindOneFiltersOnSchemaRuleAndObjectInThatOrder(): void {
		$this->queuedRows = [[self::row()]];
		$mapper = $this->mapper();

		$found = $mapper->findByKey(new NotificationDedupeKey(3, 'r1', 'u-1'));

		$this->assertSame(5, $found?->getId());
		$log = $this->log();
		$this->assertStringContainsString('param :p1 = 3, 1', $log);
		$this->assertStringContainsString('where(eq(schema_id, :p1, NULL))', $log);
		$this->assertStringContainsString('andWhere(eq(rule_key, :p2, NULL))', $log);
		$this->assertStringContainsString('andWhere(eq(object_uuid, :p3, NULL))', $log);
		$this->assertStringContainsString('setMaxResults(1)', $log);
	}

	public function testFindOneReturnsNullWhenNothingMatches(): void {
		$this->queuedRows = [[]];
		$this->assertNull($this->mapper()->findByKey(new NotificationDedupeKey(3, 'r1', 'u-1')));
	}

	public function testUpsertRefreshesAnExistingRowAndStampsDispatchWhenDispatched(): void {
		$this->queuedRows = [[self::row()]];
		$now = new DateTime('2026-05-06 07:08:09');

		$state = $this->mapper()->upsert(new NotificationDedupeKey(3, 'r1', 'u-1'), new DedupeSighting('new', $now, true));

		$this->assertSame('new', $state->getFingerprint());
		$this->assertSame($now, $state->getSeenAt());
		$this->assertSame($now, $state->getDispatchedAt());
		$this->assertStringContainsString('update(openregister_notification_dedupe', $this->log());
	}

	public function testUpsertRefreshWithoutDispatchKeepsTheDispatchStamp(): void {
		$this->queuedRows = [[self::row()]];
		$now = new DateTime('2026-05-06 07:08:09');

		$state = $this->mapper()->upsert(new NotificationDedupeKey(3, 'r1', 'u-1'), new DedupeSighting('new', $now, false));

		$this->assertSame($now, $state->getSeenAt());
		$this->assertNull($state->getDispatchedAt());
	}

	public function testUpsertRefreshSwallowsAFailedUpdateAndReturnsTheMutatedRow(): void {
		$this->queuedRows = [[self::row()]];
		$now = new DateTime('2026-05-06 07:08:09');

		$state = $this->mapper(null, new RuntimeException('locked'))->upsert(new NotificationDedupeKey(3, 'r1', 'u-1'), new DedupeSighting('fp', $now, false));

		$this->assertSame(5, $state->getId());
		$this->assertSame('fp', $state->getFingerprint());
	}

	public function testUpsertInsertsAFreshRowStampedWithNow(): void {
		$this->queuedRows = [[]];
		$now = new DateTime('2026-05-06 07:08:09');

		$state = $this->mapper()->upsert(new NotificationDedupeKey(4, 'r2', 'u-2'), new DedupeSighting('fp', $now, false));

		$this->assertSame(4, $state->getSchemaId());
		$this->assertSame('r2', $state->getRuleKey());
		$this->assertSame('u-2', $state->getObjectUuid());
		$this->assertSame('fp', $state->getFingerprint());
		$this->assertSame($now, $state->getDispatchedAt());
		$this->assertSame($now, $state->getSeenAt());
		$this->assertStringContainsString('insert(openregister_notification_dedupe)', $this->log());
	}

	public function testUpsertUniqueRaceReturnsTheWinningRow(): void {
		$this->queuedRows = [[], [self::row(['id' => 9])]];

		$state = $this->mapper(self::uniqueViolation())->upsert(new NotificationDedupeKey(3, 'r1', 'u-1'), new DedupeSighting('fp', new DateTime(), false));

		$this->assertSame(9, $state->getId());
	}

	public function testUpsertUniqueRaceWithoutAWinnerRethrows(): void {
		$this->queuedRows = [[], []];
		$violation = self::uniqueViolation();

		try {
			$this->mapper($violation)->upsert(new NotificationDedupeKey(3, 'r1', 'u-1'), new DedupeSighting('fp', new DateTime(), false));
			$this->fail('Expected the unique violation to surface');
		} catch (DbException $e) {
			$this->assertSame($violation, $e);
		}
	}

	public function testUpsertOtherDatabaseErrorsAreRethrownWithoutARetry(): void {
		$this->queuedRows = [[], [self::row(['id' => 9])]];
		$error = new DbException('disk full');

		try {
			$this->mapper($error)->upsert(new NotificationDedupeKey(3, 'r1', 'u-1'), new DedupeSighting('fp', new DateTime(), false));
			$this->fail('Expected the database error to surface');
		} catch (DbException $e) {
			$this->assertSame($error, $e);
		}
		$this->assertSame(1, substr_count($this->log(), 'executeQuery()'));
	}

	public function testDeleteByObjectDeletesOnTheObjectUuid(): void {
		$mapper = $this->mapper();

		$this->assertSame(1, $mapper->deleteByObject('u-1'));
		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_notification_dedupe', $log);
		$this->assertStringContainsString('where(eq(object_uuid, :p1, NULL))', $log);
	}

	public function testDeleteByRuleDeletesOnSchemaAndRule(): void {
		$mapper = $this->mapper();

		$this->assertSame(1, $mapper->deleteByRule(3, 'r1'));
		$log = $this->log();
		$this->assertStringContainsString('where(eq(schema_id, :p1, NULL))', $log);
		$this->assertStringContainsString('andWhere(eq(rule_key, :p2, NULL))', $log);
	}

	public function testDeleteSeenBeforeUsesAStrictCutoff(): void {
		$mapper = $this->mapper();

		$this->assertSame(1, $mapper->deleteSeenBefore(new DateTime('2026-01-02 03:04:05')));
		$log = $this->log();
		$this->assertStringContainsString('param :p1 = 2026-01-02 03:04:05', $log);
		$this->assertStringContainsString('lt(seen_at, :p1', $log);
	}

	public function testDeletesReportZeroWhenTheDatabaseFails(): void {
		$mapper = $this->mapper(null, null, true);

		$this->assertSame(0, $mapper->deleteByObject('u-1'));
		$this->assertSame(0, $mapper->deleteByRule(3, 'r1'));
		$this->assertSame(0, $mapper->deleteSeenBefore(new DateTime()));
		$this->assertSame([], $mapper->listRuleKeysForSchema(3));
	}

	public function testListRuleKeysForSchemaReturnsDistinctKeysAsStrings(): void {
		$this->queuedRows = [[['rule_key' => 'a'], ['rule_key' => 7], ['other' => 'x']]];
		$mapper = $this->mapper();

		$this->assertSame(['a', '7', ''], $mapper->listRuleKeysForSchema(3));
		$log = $this->log();
		$this->assertStringContainsString('selectDistinct(rule_key)', $log);
		$this->assertStringContainsString('where(eq(schema_id, :p1, NULL))', $log);
	}
}

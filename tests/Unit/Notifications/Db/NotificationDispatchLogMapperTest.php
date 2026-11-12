<?php

/**
 * Unit tests for NotificationDispatchLogMapper: duplicate window, unique-violation mapping and pruning.
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
use DateTimeZone;
use OCA\OpenRegister\Notifications\Db\DispatchIdempotencyKey;
use OCA\OpenRegister\Notifications\Db\DuplicateDispatchException;
use OCA\OpenRegister\Notifications\Db\NotificationDispatchLogMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationDispatchLogMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationQueryTrait
 * @uses   \OCA\OpenRegister\Notifications\Db\NotificationDispatchLog
 * @uses   \OCA\OpenRegister\Notifications\Db\DuplicateDispatchException
 */
final class NotificationDispatchLogMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function database(): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return $db;
	}

	private function log(): string {
		return implode("\n", $this->queryLog);
	}

	private function throwingInsert(DbException $e): NotificationDispatchLogMapper {
		$mapper = new class($this->database()) extends NotificationDispatchLogMapper {
			public ?DbException $error = null;
			public function insert(Entity $entity): Entity {
				throw $this->error;
			}
		};
		$mapper->error = $e;
		return $mapper;
	}

	public function testIsDuplicateWhenARecentRowExists(): void {
		$this->queuedRows = [[['c' => '1']]];
		$mapper = new NotificationDispatchLogMapper($this->database());
		$before = (new DateTime('now', new DateTimeZone('UTC')))->modify('-60 seconds')->format('Y-m-d H:i:s');

		$this->assertTrue($mapper->isDuplicate(new DispatchIdempotencyKey('slug', 'key'), 60));
		$after = (new DateTime('now', new DateTimeZone('UTC')))->modify('-60 seconds')->format('Y-m-d H:i:s');
		$log = $this->log();
		$this->assertStringContainsString('where(eq(notification_slug, :p1, NULL))', $log);
		$this->assertStringContainsString('andWhere(eq(idempotency_key, :p2, NULL))', $log);
		$this->assertStringContainsString('gte(dispatched_at, :p3', $log);
		$this->assertMatchesRegularExpression('/param :p3 = (\S+ \S+), 2/', $log);
		preg_match('/param :p3 = (\S+ \S+), 2/', $log, $m);
		$this->assertGreaterThanOrEqual($before, $m[1]);
		$this->assertLessThanOrEqual($after, $m[1]);
	}

	public function testIsNotDuplicateWhenNoRowInTheWindow(): void {
		$this->queuedRows = [[['c' => '0']]];
		$this->assertFalse((new NotificationDispatchLogMapper($this->database()))->isDuplicate(new DispatchIdempotencyKey('slug', 'key')));
	}

	public function testRecordInsertsSlugKeyAndUtcTimestamp(): void {
		$log = (new NotificationDispatchLogMapper($this->database()))->record('slug', 'key');

		$this->assertSame('slug', $log->getNotificationSlug());
		$this->assertSame('key', $log->getIdempotencyKey());
		$this->assertSame('UTC', $log->getDispatchedAt()->getTimezone()->getName());
		$this->assertStringContainsString('insert(openregister_notif_dispatch_log)', $this->log());
	}

	public function testRecordMapsAUniqueViolationToDuplicateDispatch(): void {
		$violation = new class('dup') extends DbException {
			public function getReason(): ?int {
				return self::REASON_UNIQUE_CONSTRAINT_VIOLATION;
			}
		};

		try {
			$this->throwingInsert($violation)->record('slug', 'key');
			$this->fail('Expected DuplicateDispatchException');
		} catch (DuplicateDispatchException $e) {
			$this->assertSame('Duplicate dispatch: (slug=slug, key=key) already recorded', $e->getMessage());
			$this->assertSame($violation, $e->getPrevious());
		}
	}

	public function testRecordRethrowsOtherDatabaseErrors(): void {
		$error = new DbException('disk full');

		try {
			$this->throwingInsert($error)->record('slug', 'key');
			$this->fail('Expected DbException');
		} catch (DuplicateDispatchException $e) {
			$this->fail('Only a unique violation is a duplicate');
		} catch (DbException $e) {
			$this->assertSame($error, $e);
		}
	}

	public function testPruneExpiredDeletesBeforeTheWindow(): void {
		$mapper = new NotificationDispatchLogMapper($this->database());

		$this->assertSame(1, $mapper->pruneExpired(3600));
		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_notif_dispatch_log', $log);
		$this->assertStringContainsString('lt(dispatched_at, :p1', $log);
	}
}

<?php

/**
 * Unit tests for NotificationReadStateMapper: idempotent mark-read, insert race, unread and read checks.
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
use OCA\OpenRegister\Notifications\Db\NotificationReadStateEntity;
use OCA\OpenRegister\Notifications\Db\NotificationReadStateMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationReadStateMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationReadStateEntity
 */
final class NotificationReadStateMapperTest extends TestCase {
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

	public function testMarkReadReturnsAnExistingReadStateWithoutInserting(): void {
		$this->queuedRows = [[['id' => 2, 'user_id' => 'alice', 'notification_id' => 'n-1']]];
		$mapper = new NotificationReadStateMapper($this->database());

		$this->assertSame(2, $mapper->markRead('alice', 'n-1')->getId());
		$log = $this->log();
		$this->assertStringContainsString('where(eq(user_id, :p1, NULL))', $log);
		$this->assertStringContainsString('andWhere(eq(notification_id, :p2, NULL))', $log);
		$this->assertStringContainsString('setMaxResults(1)', $log);
		$this->assertStringNotContainsString('insert(', $log);
	}

	public function testMarkReadInsertsAStampedRowWhenUnread(): void {
		$this->queuedRows = [[]];
		$mapper = new NotificationReadStateMapper($this->database());

		$state = $mapper->markRead('alice', 'n-1');

		$this->assertSame('alice', $state->getUserId());
		$this->assertSame('n-1', $state->getNotificationId());
		$this->assertInstanceOf(DateTime::class, $state->getReadAt());
		$this->assertStringContainsString('insert(openregister_notification_readstate)', $this->log());
	}

	public function testMarkReadRaceReturnsTheParallelWinner(): void {
		$this->queuedRows = [[], [['id' => 8, 'user_id' => 'alice', 'notification_id' => 'n-1']]];
		$mapper = new class($this->database()) extends NotificationReadStateMapper {
			public function insert(Entity $entity): Entity {
				throw new DbException('dup');
			}
		};

		$this->assertSame(8, $mapper->markRead('alice', 'n-1')->getId());
	}

	public function testMarkUnreadDeletesThePairAndReportsIt(): void {
		$mapper = new NotificationReadStateMapper($this->database());

		$this->assertTrue($mapper->markUnread('alice', 'n-1'));
		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_notification_readstate', $log);
		$this->assertStringContainsString('andWhere(eq(notification_id, :p2, NULL))', $log);
	}

	public function testIsReadReflectsWhetherARowExists(): void {
		$this->queuedRows = [[['id' => 1, 'user_id' => 'a', 'notification_id' => 'n']], []];
		$mapper = new NotificationReadStateMapper($this->database());

		$this->assertTrue($mapper->isRead('a', 'n'));
		$this->assertFalse($mapper->isRead('a', 'n'));
	}

	public function testEntitySerialisesReadAtAsAtom(): void {
		$e = new NotificationReadStateEntity();
		$e->setUserId('a');
		$e->setNotificationId('n');
		$this->assertSame(['id' => null, 'userId' => 'a', 'notificationId' => 'n', 'readAt' => null], $e->jsonSerialize());
		$e->setReadAt(new DateTime('2026-01-02T03:04:05+00:00'));
		$this->assertSame('2026-01-02T03:04:05+00:00', $e->jsonSerialize()['readAt']);
	}
}

<?php

/**
 * Unit tests for NotificationBroadcastMapper and the NotificationBroadcast entity's active window.
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
use OCA\OpenRegister\Notifications\Db\BroadcastMessage;
use OCA\OpenRegister\Notifications\Db\BroadcastWindow;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcast;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastMapper;
use OCA\OpenRegister\Notifications\Service\Notification\OutgoingBroadcast;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationBroadcastMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationQueryTrait
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationBroadcast
 * @uses   \OCA\OpenRegister\Notifications\Db\BroadcastMessage
 * @uses   \OCA\OpenRegister\Notifications\Db\BroadcastWindow
 */
final class NotificationBroadcastMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(): NotificationBroadcastMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return new NotificationBroadcastMapper($db);
	}

	private function log(): string {
		return implode("\n", $this->queryLog);
	}

	public function testRecordInsertsTheMessageWindowAndSender(): void {
		$mapper = $this->mapper();
		$starts = new DateTime('2026-01-01 00:00:00');
		$ends = new DateTime('2026-01-31 00:00:00');

		$entity = $mapper->record('b-1', new OutgoingBroadcast(new BroadcastMessage('Maintenance', 'Tonight'), 'admin', new BroadcastWindow($starts, $ends)));

		$this->assertSame('b-1', $entity->getUuid());
		$this->assertSame('Maintenance', $entity->getSubject());
		$this->assertSame('Tonight', $entity->getBody());
		$this->assertSame('admin', $entity->getSender());
		$this->assertSame($starts, $entity->getStartsAt());
		$this->assertSame($ends, $entity->getEndsAt());
		$this->assertInstanceOf(DateTime::class, $entity->getCreated());
		$this->assertStringContainsString('insert(openregister_notif_broadcast)', $this->log());
	}

	public function testFindByUuidReturnsTheFirstMatch(): void {
		$this->queuedRows = [[['id' => 3, 'uuid' => 'b-1', 'subject' => 'S']]];
		$mapper = $this->mapper();

		$this->assertSame('S', $mapper->findByUuid('b-1')?->getSubject());
		$log = $this->log();
		$this->assertStringContainsString('where(eq(uuid, :p1, NULL))', $log);
		$this->assertStringContainsString('setMaxResults(1)', $log);
	}

	public function testFindByUuidReturnsNullWhenMissing(): void {
		$this->queuedRows = [[]];
		$this->assertNull($this->mapper()->findByUuid('nope'));
	}

	public function testFindAllBroadcastsPagesNewestFirst(): void {
		$this->queuedRows = [[['id' => 1, 'uuid' => 'a'], ['id' => 2, 'uuid' => 'b']]];
		$mapper = $this->mapper();

		$rows = $mapper->findAllBroadcasts(10, 20);

		$this->assertSame(['a', 'b'], array_map(fn (NotificationBroadcast $b) => $b->getUuid(), $rows));
		$log = $this->log();
		$this->assertStringContainsString('orderBy(created, DESC)', $log);
		$this->assertStringContainsString('setMaxResults(10)', $log);
		$this->assertStringContainsString('setFirstResult(20)', $log);
	}

	public function testFindAllBroadcastsWithoutPagingSetsNoLimitOrOffset(): void {
		$this->queuedRows = [[]];
		$mapper = $this->mapper();

		$this->assertSame([], $mapper->findAllBroadcasts());
		$log = $this->log();
		$this->assertStringNotContainsString('setMaxResults', $log);
		$this->assertStringNotContainsString('setFirstResult', $log);
	}

	public function testFindUnseenForBoundsTheWindowAndExcludesReceiptsOfThatUser(): void {
		$this->queuedRows = [[['id' => 7, 'uuid' => 'live']]];
		$mapper = $this->mapper();
		$asOf = new DateTime('2026-02-03 04:05:06');

		$rows = $mapper->findUnseenFor('alice', $asOf);

		$this->assertSame('live', $rows[0]->getUuid());
		$log = $this->log();
		$this->assertStringContainsString('lte(b.starts_at, :p1', $log);
		$this->assertStringContainsString('gte(b.ends_at, :p2', $log);
		$this->assertStringContainsString('param :p3 = alice', $log);
		$this->assertStringContainsString('NOT EXISTS (SELECT 1 FROM `*PREFIX*openregister_notif_bc_receipt` r WHERE r.`broadcast_uuid` = b.`uuid` AND r.`user_id` = :p3)', $log);
		$this->assertStringContainsString('orderBy(b.starts_at, ASC)', $log);
	}

	public function testDeleteByUuidReportsADeletedRow(): void {
		$mapper = $this->mapper();

		$this->assertTrue($mapper->deleteByUuid('b-1'));
		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_notif_broadcast', $log);
		$this->assertStringContainsString('where(eq(uuid, :p1, NULL))', $log);
	}

	public function testBroadcastIsActiveOnlyInsideAClosedWindow(): void {
		$b = new NotificationBroadcast();
		$this->assertFalse($b->isActiveAt(new DateTime('2026-01-15')));

		$b->setStartsAt(new DateTime('2026-01-01'));
		$this->assertFalse($b->isActiveAt(new DateTime('2026-01-15')));

		$b->setEndsAt(new DateTime('2026-01-31'));
		$this->assertTrue($b->isActiveAt(new DateTime('2026-01-15')));
		$this->assertTrue($b->isActiveAt(new DateTime('2026-01-01')));
		$this->assertTrue($b->isActiveAt(new DateTime('2026-01-31')));
		$this->assertFalse($b->isActiveAt(new DateTime('2025-12-31 23:59:59')));
		$this->assertFalse($b->isActiveAt(new DateTime('2026-01-31 00:00:01')));
	}

	public function testBroadcastSerialisesAtomDatesAndItsCurrentActivity(): void {
		$b = new NotificationBroadcast();
		$b->setUuid('b-1');
		$b->setSubject('S');
		$b->setSender('admin');
		$b->setStartsAt(new DateTime('2000-01-01T00:00:00+00:00'));
		$b->setEndsAt(new DateTime('2001-01-01T00:00:00+00:00'));

		$json = $b->jsonSerialize();

		$this->assertSame('2000-01-01T00:00:00+00:00', $json['startsAt']);
		$this->assertSame('2001-01-01T00:00:00+00:00', $json['endsAt']);
		$this->assertNull($json['created']);
		$this->assertNull($json['body']);
		$this->assertFalse($json['active']);
		$this->assertSame('b-1', $json['uuid']);
	}
}

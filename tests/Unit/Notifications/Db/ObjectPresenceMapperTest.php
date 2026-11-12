<?php

/**
 * Unit tests for ObjectPresenceMapper: presence lookup, freshness window and stale pruning.
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
use OCA\OpenRegister\Notifications\Db\ObjectPresence;
use OCA\OpenRegister\Notifications\Db\ObjectPresenceMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\ObjectPresenceMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationQueryTrait
 * @covers \OCA\OpenRegister\Notifications\Db\ObjectPresence
 */
final class ObjectPresenceMapperTest extends TestCase {
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

	public function testFindOneScopesOnUserAndObject(): void {
		$this->queuedRows = [[['id' => 3, 'user_id' => 'alice', 'object_uuid' => 'o-1']]];
		$mapper = new ObjectPresenceMapper($this->database());

		$this->assertSame('o-1', $mapper->findOne('alice', 'o-1')?->getObjectUuid());
		$log = $this->log();
		$this->assertStringContainsString('where(eq(user_id, :p1, NULL))', $log);
		$this->assertStringContainsString('andWhere(eq(object_uuid, :p2, NULL))', $log);
	}

	public function testFindOneReturnsNullWhenAbsent(): void {
		$this->queuedRows = [[]];
		$this->assertNull((new ObjectPresenceMapper($this->database()))->findOne('alice', 'o-1'));
	}

	public function testFindPresentKeepsOnlyRecentViewersInArrivalOrder(): void {
		$this->queuedRows = [[['id' => 1, 'user_id' => 'a', 'object_uuid' => 'o-1'], ['id' => 2, 'user_id' => 'b', 'object_uuid' => 'o-1']]];
		$mapper = new ObjectPresenceMapper($this->database());

		$rows = $mapper->findPresent('o-1', new DateTime('2026-01-01 00:00:00'));

		$this->assertSame(['a', 'b'], array_map(fn (ObjectPresence $p) => $p->getUserId(), $rows));
		$log = $this->log();
		$this->assertStringContainsString('where(eq(object_uuid, :p1, NULL))', $log);
		$this->assertStringContainsString('gte(last_seen, :p2', $log);
		$this->assertStringContainsString('orderBy(arrived_at, ASC)', $log);
	}

	public function testRemoveOneDeletesThePair(): void {
		$mapper = new ObjectPresenceMapper($this->database());

		$this->assertTrue($mapper->removeOne('alice', 'o-1'));
		$this->assertStringContainsString('delete(openregister_presence', $this->log());
	}

	public function testFindStaleIsBoundedByCutoffAndLimit(): void {
		$this->queuedRows = [[]];
		$mapper = new ObjectPresenceMapper($this->database());

		$this->assertSame([], $mapper->findStale(new DateTime('2026-01-01'), 25));
		$log = $this->log();
		$this->assertStringContainsString('lt(last_seen, :p1', $log);
		$this->assertStringContainsString('setMaxResults(25)', $log);
	}

	public function testPruneStaleDeletesBeforeTheCutoffAndReturnsTheCount(): void {
		$mapper = new ObjectPresenceMapper($this->database());

		$this->assertSame(1, $mapper->pruneStale(new DateTime('2026-01-01')));
		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_presence', $log);
		$this->assertStringContainsString('lt(last_seen, :p1', $log);
	}

	public function testPresenceSerialisesIsoDates(): void {
		$p = new ObjectPresence();
		$p->setUserId('a');
		$p->setObjectUuid('o');
		$this->assertSame(['user' => 'a', 'object' => 'o', 'arrivedAt' => null, 'lastSeen' => null], $p->jsonSerialize());
		$p->setArrivedAt(new DateTime('2026-01-02T03:04:05+00:00'));
		$p->setLastSeen(new DateTime('2026-01-02T03:05:05+00:00'));
		$this->assertSame('2026-01-02T03:04:05+00:00', $p->jsonSerialize()['arrivedAt']);
		$this->assertSame('2026-01-02T03:05:05+00:00', $p->jsonSerialize()['lastSeen']);
	}
}

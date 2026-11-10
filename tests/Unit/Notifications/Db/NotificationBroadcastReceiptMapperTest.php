<?php

/**
 * Unit tests for NotificationBroadcastReceiptMapper: idempotent acknowledge, insert race and receipt count.
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
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastReceipt;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastReceiptMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationBroadcastReceiptMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationQueryTrait
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationBroadcastReceipt
 */
final class NotificationBroadcastReceiptMapperTest extends TestCase {
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

	private function failingInsert(): NotificationBroadcastReceiptMapper {
		return new class($this->database()) extends NotificationBroadcastReceiptMapper {
			public function insert(Entity $entity): Entity {
				throw new RuntimeException('dup');
			}
		};
	}

	public function testAcknowledgeReturnsAnExistingReceiptWithoutInserting(): void {
		$this->queuedRows = [[['id' => 4, 'broadcast_uuid' => 'b-1', 'user_id' => 'alice']]];
		$mapper = new NotificationBroadcastReceiptMapper($this->database());

		$this->assertSame(4, $mapper->acknowledge('b-1', 'alice')->getId());
		$log = $this->log();
		$this->assertStringContainsString('where(eq(broadcast_uuid, :p1, NULL))', $log);
		$this->assertStringContainsString('andWhere(eq(user_id, :p2, NULL))', $log);
		$this->assertStringNotContainsString('insert(', $log);
	}

	public function testAcknowledgeInsertsWithTheGivenSeenAt(): void {
		$this->queuedRows = [[]];
		$seen = new DateTime('2026-04-05 06:07:08');
		$mapper = new NotificationBroadcastReceiptMapper($this->database());

		$given = new NotificationBroadcastReceipt();
		$given->setBroadcastUuid('b-1');
		$given->setUserId('alice');
		$given->setSeenAt($seen);
		$receipt = $mapper->acknowledgeReceipt($given);

		$this->assertSame('b-1', $receipt->getBroadcastUuid());
		$this->assertSame('alice', $receipt->getUserId());
		$this->assertSame($seen, $receipt->getSeenAt());
		$this->assertStringContainsString('insert(openregister_notif_bc_receipt)', $this->log());
	}

	public function testAcknowledgeDefaultsSeenAtToNow(): void {
		$this->queuedRows = [[]];
		$receipt = (new NotificationBroadcastReceiptMapper($this->database()))->acknowledge('b-1', 'alice');

		$this->assertInstanceOf(DateTime::class, $receipt->getSeenAt());
		$this->assertEqualsWithDelta(time(), $receipt->getSeenAt()->getTimestamp(), 5);
	}

	public function testAcknowledgeRaceReturnsTheWinner(): void {
		$this->queuedRows = [[], [['id' => 9, 'broadcast_uuid' => 'b-1', 'user_id' => 'alice']]];

		$this->assertSame(9, $this->failingInsert()->acknowledge('b-1', 'alice')->getId());
	}

	public function testAcknowledgeFailureWithoutAWinnerRethrows(): void {
		$this->queuedRows = [[], []];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('dup');
		$this->failingInsert()->acknowledge('b-1', 'alice');
	}

	public function testCountForCountsReceiptsOfTheBroadcast(): void {
		$this->queuedRows = [[['COUNT(*)' => '12']]];
		$mapper = new NotificationBroadcastReceiptMapper($this->database());

		$this->assertSame(12, $mapper->countFor('b-1'));
		$log = $this->log();
		$this->assertStringContainsString('select(COUNT(*))', $log);
		$this->assertStringContainsString('where(eq(broadcast_uuid, :p1', $log);
	}

	public function testReceiptSerialisesSeenAtAsAtom(): void {
		$r = new NotificationBroadcastReceipt();
		$r->setBroadcastUuid('b');
		$r->setUserId('u');
		$this->assertSame(['id' => null, 'broadcastUuid' => 'b', 'userId' => 'u', 'seenAt' => null], $r->jsonSerialize());
		$r->setSeenAt(new DateTime('2026-01-02T03:04:05+00:00'));
		$this->assertSame('2026-01-02T03:04:05+00:00', $r->jsonSerialize()['seenAt']);
	}
}

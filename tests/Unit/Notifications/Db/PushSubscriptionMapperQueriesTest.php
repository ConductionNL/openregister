<?php

/**
 * The queries PushSubscriptionMapper sends to read and remove subscriptions.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Notifications\Db\PushSubscription;
use OCA\OpenRegister\Notifications\Db\PushSubscriptionMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\PushSubscriptionMapper
 * @uses \OCA\OpenRegister\Notifications\Db\PushSubscription
 */
final class PushSubscriptionMapperQueriesTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper over a recording database.
	 *
	 * @return PushSubscriptionMapper
	 */
	private function mapper(): PushSubscriptionMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return new PushSubscriptionMapper($db);
	}//end mapper()

	/**
	 * The recorded query, one call per line.
	 *
	 * @return string
	 */
	private function log(): string {
		return implode("\n", $this->queryLog);
	}//end log()

	/**
	 * A subscription row as the database returns it.
	 *
	 * @param int    $id       The row id.
	 * @param string $endpoint The push endpoint.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(int $id, string $endpoint): array {
		return ['id' => $id, 'user_id' => 'alice', 'endpoint' => $endpoint, 'p256dh' => 'k', 'auth' => 'a', 'user_agent' => 'UA', 'created_at' => '2026-01-01 10:00:00'];
	}//end row()

	public function testFindByUserReturnsEveryRowOfThatUser(): void {
		$this->queuedRows = [[self::row(1, 'https://push.example/1'), self::row(2, 'https://push.example/2')]];

		$rows = $this->mapper()->findByUser('alice');

		$this->assertSame(['https://push.example/1', 'https://push.example/2'], array_map(static fn (PushSubscription $s): ?string => $s->getEndpoint(), $rows));
		$log = $this->log();
		$this->assertStringContainsString('#1 from(openregister_push_subscriptions, NULL)', $log);
		$this->assertStringContainsString('#1 param :p1 = alice, 2', $log);
		$this->assertStringContainsString('where(eq(user_id, :p1, NULL))', $log);
	}//end testFindByUserReturnsEveryRowOfThatUser()

	public function testFindByUserAndEndpointReturnsTheOneRow(): void {
		$this->queuedRows = [[self::row(4, 'https://push.example/e')]];

		$found = $this->mapper()->findByUserAndEndpoint('alice', 'https://push.example/e');

		$this->assertInstanceOf(PushSubscription::class, $found);
		$this->assertSame(4, $found->getId());
		$log = $this->log();
		$this->assertStringContainsString('where(eq(user_id, :p1, NULL))', $log);
		$this->assertStringContainsString('andWhere(eq(endpoint, :p2, NULL))', $log);
		$this->assertStringContainsString('#1 param :p2 = https://push.example/e, 2', $log);
		$this->assertStringContainsString('setMaxResults(1)', $log);
	}//end testFindByUserAndEndpointReturnsTheOneRow()

	public function testFindByUserAndEndpointWithoutARowIsNull(): void {
		$this->queuedRows = [[]];

		$this->assertNull($this->mapper()->findByUserAndEndpoint('alice', 'https://push.example/none'));
	}//end testFindByUserAndEndpointWithoutARowIsNull()

	public function testDeleteByUserAndEndpointIsScopedToBoth(): void {
		$this->assertSame(1, $this->mapper()->deleteByUserAndEndpoint('alice', 'https://push.example/e'));
		$log = $this->log();
		$this->assertStringContainsString('#1 delete(openregister_push_subscriptions, NULL)', $log);
		$this->assertStringContainsString('where(eq(user_id, :p1, NULL))', $log);
		$this->assertStringContainsString('andWhere(eq(endpoint, :p2, NULL))', $log);
		$this->assertStringContainsString('#1 executeStatement()', $log);
	}//end testDeleteByUserAndEndpointIsScopedToBoth()

	public function testDeleteByEndpointRemovesItForEveryUser(): void {
		$this->assertSame(1, $this->mapper()->deleteByEndpoint('https://push.example/gone'));
		$log = $this->log();
		$this->assertStringContainsString('#1 delete(openregister_push_subscriptions, NULL)', $log);
		$this->assertStringContainsString('where(eq(endpoint, :p1, NULL))', $log);
		$this->assertStringNotContainsString('user_id', $log);
	}//end testDeleteByEndpointRemovesItForEveryUser()
}//end class

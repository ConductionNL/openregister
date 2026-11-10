<?php

/**
 * Unit tests for NotificationSubscriptionMapper: idempotent subscribe, nullable scoping and uid lookup.
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
use InvalidArgumentException;
use OCA\OpenRegister\Notifications\Db\NotificationSubscription;
use OCA\OpenRegister\Notifications\Db\NotificationSubscriptionMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Notifications\Db\SubscriptionKey;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationSubscriptionMapper
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationSubscription
 */
final class NotificationSubscriptionMapperTest extends TestCase {
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

	public function testSubscribeWithoutAnyScopeIsRefusedBeforeTouchingTheDatabase(): void {
		$mapper = new NotificationSubscriptionMapper($this->database());

		try {
			$mapper->subscribeTo(key: new SubscriptionKey(userId: 'alice', registerId: null, schemaId: null));
			$this->fail('Expected InvalidArgumentException');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('at least one of registerId / schemaId', $e->getMessage());
		}
		$this->assertSame([], $this->queryLog);
	}

	public function testSubscribeReturnsTheExistingRowWithoutInserting(): void {
		$this->queuedRows = [[['id' => 4, 'user_id' => 'alice', 'register_id' => 2, 'schema_id' => null, 'created' => '2026-01-02 03:04:05']]];
		$mapper = new NotificationSubscriptionMapper($this->database());

		$result = $mapper->subscribeTo(key: new SubscriptionKey(userId: 'alice', registerId: 2, schemaId: null));

		$this->assertSame(4, $result->getId());
		$this->assertSame(2, $result->getRegisterId());
		$this->assertNull($result->getSchemaId());
		$log = $this->log();
		$this->assertStringContainsString('eq(user_id, :p1, NULL)', $log);
		$this->assertStringContainsString('eq(register_id, :p2, NULL)', $log);
		$this->assertStringContainsString('isNull(schema_id)', $log);
		$this->assertStringContainsString('setMaxResults(1)', $log);
		$this->assertStringNotContainsString('insert(', $log);
	}

	public function testSubscribeInsertsANewScopedRowWhenNoneExists(): void {
		$this->queuedRows = [[]];
		$mapper = new NotificationSubscriptionMapper($this->database());

		$result = $mapper->subscribeTo(key: new SubscriptionKey(userId: 'bob', registerId: null, schemaId: 9));

		$this->assertSame('bob', $result->getUserId());
		$this->assertNull($result->getRegisterId());
		$this->assertSame(9, $result->getSchemaId());
		$this->assertInstanceOf(DateTime::class, $result->getCreated());
		$log = $this->log();
		$this->assertStringContainsString('isNull(register_id)', $log);
		$this->assertStringContainsString('insert(openregister_notification_subscriptions)', $log);
	}

	public function testSubscribeRaceReturnsTheRowThatWonTheInsert(): void {
		$this->queuedRows = [[], [['id' => 11, 'user_id' => 'bob', 'register_id' => 1, 'schema_id' => 2]]];
		$mapper = new class($this->database()) extends NotificationSubscriptionMapper {
			public function insert(Entity $entity): Entity {
				throw new DbException('duplicate key');
			}
		};

		$result = $mapper->subscribeTo(key: new SubscriptionKey(userId: 'bob', registerId: 1, schemaId: 2));

		$this->assertSame(11, $result->getId());
		$this->assertSame(2, substr_count($this->log(), 'executeQuery()'));
	}

	public function testSubscribeRaceWithoutAWinnerSurfacesDoesNotExist(): void {
		$this->queuedRows = [[], []];
		$mapper = new class($this->database()) extends NotificationSubscriptionMapper {
			public function insert(Entity $entity): Entity {
				throw new DbException('duplicate key');
			}
		};

		$this->expectException(DoesNotExistException::class);
		$mapper->subscribeTo(key: new SubscriptionKey(userId: 'bob', registerId: 1, schemaId: 2));
	}

	public function testUnsubscribeDeletesTheExactScopeAndReportsTheDeletion(): void {
		$mapper = new NotificationSubscriptionMapper($this->database());

		$this->assertTrue($mapper->unsubscribeFrom(key: new SubscriptionKey(userId: 'alice', registerId: 3, schemaId: null)));
		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_notification_subscriptions, NULL)', $log);
		$this->assertStringContainsString('eq(register_id, :p2, NULL)', $log);
		$this->assertStringContainsString('isNull(schema_id)', $log);
		$this->assertStringContainsString('executeStatement()', $log);
	}

	public function testFindByUserReturnsEntitiesNewestFirst(): void {
		$this->queuedRows = [[
			['id' => 1, 'user_id' => 'alice', 'register_id' => 1, 'schema_id' => null],
			['id' => 2, 'user_id' => 'alice', 'register_id' => null, 'schema_id' => 5],
		]];
		$mapper = new NotificationSubscriptionMapper($this->database());

		$rows = $mapper->findByUser('alice');

		$this->assertSame([1, 2], array_map(fn (NotificationSubscription $s) => $s->getId(), $rows));
		$this->assertStringContainsString('orderBy(created, DESC)', $this->log());
	}

	public function testFindSubscribedUidsMatchesExactRegisterOnlyAndSchemaOnlyScopes(): void {
		$this->queuedRows = [[['user_id' => 'alice'], ['user_id' => 42]]];
		$mapper = new NotificationSubscriptionMapper($this->database());

		$this->assertSame(['alice', '42'], $mapper->findSubscribedUids(3, 7));
		$log = $this->log();
		$this->assertStringContainsString('selectDistinct(user_id)', $log);
		$this->assertStringContainsString('isNull(schema_id)', $log);
		$this->assertStringContainsString('isNull(register_id)', $log);
		$this->assertStringContainsString('orX(', $log);
	}

	public function testFindSubscribedUidsWithNoRowsIsEmpty(): void {
		$this->queuedRows = [[]];
		$mapper = new NotificationSubscriptionMapper($this->database());

		$this->assertSame([], $mapper->findSubscribedUids(3, 7));
	}

	public function testEntitySerialisesWithAtomCreatedAndNullWhenUnset(): void {
		$entity = new NotificationSubscription();
		$entity->setUserId('alice');
		$entity->setRegisterId(1);
		$this->assertSame(
			['id' => null, 'userId' => 'alice', 'registerId' => 1, 'schemaId' => null, 'created' => null],
			$entity->jsonSerialize()
		);

		$entity->setCreated(new DateTime('2026-03-04T05:06:07+00:00'));
		$this->assertSame('2026-03-04T05:06:07+00:00', $entity->jsonSerialize()['created']);
	}
}

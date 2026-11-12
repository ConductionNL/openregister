<?php

/**
 * Unit tests for QueuedNotificationMapper: queue order, id lookup and failure tolerance.
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

use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper
 * @uses   \OCA\OpenRegister\Notifications\Db\QueuedNotification
 */
final class QueuedNotificationMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(bool $failing = false): QueuedNotificationMapper {
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
		return new QueuedNotificationMapper($db);
	}

	private function log(): string {
		return implode("\n", $this->queryLog);
	}

	public function testFindAllReturnsTheQueueOldestFirst(): void {
		$this->queuedRows = [[['id' => 1], ['id' => 2]]];
		$mapper = $this->mapper();

		$this->assertSame([1, 2], array_map(fn (QueuedNotification $q) => $q->getId(), $mapper->findAll()));
		$this->assertStringContainsString('orderBy(created_at, ASC)', $this->log());
	}

	public function testFindByIdLoadsOneRow(): void {
		$this->queuedRows = [[['id' => 9]]];
		$mapper = $this->mapper();

		$this->assertSame(9, $mapper->findById(9)?->getId());
		$log = $this->log();
		$this->assertStringContainsString('param :p1 = 9, 1', $log);
		$this->assertStringContainsString('where(eq(id, :p1, NULL))', $log);
		$this->assertStringContainsString('setMaxResults(1)', $log);
	}

	public function testFindByIdReturnsNullWhenMissing(): void {
		$this->queuedRows = [[]];
		$this->assertNull($this->mapper()->findById(9));
	}

	public function testDeleteByIdIssuesTheDelete(): void {
		$mapper = $this->mapper();

		$mapper->deleteById(9);
		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_', $log);
		$this->assertStringContainsString('where(eq(id, :p1, NULL))', $log);
		$this->assertStringContainsString('executeStatement()', $log);
	}

	public function testDatabaseFailuresDegradeToEmptyResults(): void {
		$mapper = $this->mapper(true);

		$this->assertSame([], $mapper->findAll());
		$this->assertNull($mapper->findById(1));
		$mapper->deleteById(1);
		$this->assertSame([], $this->queryLog);
	}
}

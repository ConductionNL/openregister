<?php

/**
 * Unit tests for ScheduledReportMapper queries.
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

use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\ScheduledReportMapper
 * @uses   \OCA\OpenRegister\Notifications\Db\ScheduledReport
 */
final class ScheduledReportMapperTest extends TestCase {
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

	public function testFindLoadsById(): void {
		$this->queuedRows = [[['id' => 5, 'owner' => 'alice', 'name' => 'Weekly']]];
		$mapper = new ScheduledReportMapper($this->database());

		$this->assertSame('Weekly', $mapper->find(5)->getName());
		$log = $this->log();
		$this->assertStringContainsString('param :p1 = 5, 1', $log);
		$this->assertStringContainsString('where(eq(id, :p1, NULL))', $log);
	}

	public function testFindThrowsWhenMissing(): void {
		$this->queuedRows = [[]];
		$this->expectException(DoesNotExistException::class);
		(new ScheduledReportMapper($this->database()))->find(5);
	}

	public function testFindByOwnerOrdersById(): void {
		$this->queuedRows = [[['id' => 1, 'owner' => 'alice'], ['id' => 2, 'owner' => 'alice']]];
		$mapper = new ScheduledReportMapper($this->database());

		$this->assertSame([1, 2], array_map(fn (ScheduledReport $r) => $r->getId(), $mapper->findByOwner('alice')));
		$log = $this->log();
		$this->assertStringContainsString('where(eq(owner, :p1, NULL))', $log);
		$this->assertStringContainsString('orderBy(id, ASC)', $log);
	}

	public function testFindAllHasNoFilter(): void {
		$this->queuedRows = [[['id' => 1]]];
		$mapper = new ScheduledReportMapper($this->database());

		$this->assertCount(1, $mapper->findAll());
		$this->assertStringNotContainsString('where(', $this->log());
	}

	public function testFindEnabledFiltersOnABooleanTrue(): void {
		$this->queuedRows = [[]];
		$mapper = new ScheduledReportMapper($this->database());

		$this->assertSame([], $mapper->findEnabled());
		$log = $this->log();
		$this->assertStringContainsString('param :p1 = true, boolean', $log);
		$this->assertStringContainsString('where(eq(enabled, :p1, NULL))', $log);
	}
}

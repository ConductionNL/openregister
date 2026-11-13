<?php

/**
 * Unit tests for TaskSubjectLocationBackfill: which subjects it loads, how it
 * batches them, what it writes per located subject and how it counts.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Repair\TaskSubjectLocationBackfill;
use OCA\OpenRegister\Service\Task\TaskSubjectLocatorInterface;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Repair\TaskSubjectLocationBackfill
 */
final class TaskSubjectLocationBackfillTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var TaskSubjectLocatorInterface&MockObject */
	private TaskSubjectLocatorInterface $locator;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$this->locator = $this->createMock(TaskSubjectLocatorInterface::class);
	}

	public function testLoadReadsTheDistinctSubjectsOfUnlocatedTasksAsStrings(): void {
		$this->queuedRows = [[7, 'u-2', 'u-3']];

		$backfill = TaskSubjectLocationBackfill::load($this->db, $this->locator);

		$this->assertSame([['7', 'u-2'], ['u-3']], $backfill->batches(2));
		$this->assertSame([['7', 'u-2', 'u-3']], $backfill->batches(500));
		$log = implode("\n", $this->queryLog);
		$this->assertStringContainsString('from(openregister_tasks', $log);
		$this->assertStringContainsString('isNotNull(object_uuid)', $log);
		$this->assertStringContainsString('orX(isNull(register_id), isNull(schema_id))', $log);
		$this->assertStringContainsString('selectDistinct(object_uuid)', $log);
		$this->assertSame(0, $backfill->located);
	}

	public function testNothingUnlocatedMeansNoBatches(): void {
		$this->queuedRows = [[]];

		$this->assertSame([], TaskSubjectLocationBackfill::load($this->db, $this->locator)->batches(500));
	}

	public function testEachLocatedSubjectIsWrittenToItsUnlocatedTasksAndCounted(): void {
		$this->locator->expects($this->once())->method('locateMany')->with(['u-1', 'u-2', 'u-3'])
			->willReturn(['u-1' => ['registerId' => 4, 'schemaId' => 8], 'u-3' => ['registerId' => 5, 'schemaId' => 9]]);
		$backfill = new TaskSubjectLocationBackfill($this->db, $this->locator);

		$backfill->locateBatch(['u-1', 'u-2', 'u-3']);

		$this->assertSame(2, $backfill->located);
		$log = implode("\n", $this->queryLog);
		$this->assertSame(2, substr_count($log, 'update(openregister_tasks'));
		$this->assertSame(2, substr_count($log, 'executeStatement()'));
		$this->assertMatchesRegularExpression('/param (:p\d+) = 4, 1\n.*set\(register_id, \1/s', $log);
		$this->assertMatchesRegularExpression('/param (:p\d+) = 9, 1\n.*set\(schema_id, \1/s', $log);
		$this->assertMatchesRegularExpression('/param (:p\d+) = u-3, 2\n.*eq\(object_uuid, \1/s', $log);
		$this->assertSame(2, substr_count($log, 'andWhere(orX(isNull(register_id), isNull(schema_id))'));
		$this->assertStringNotContainsString('u-2', $log);
	}

	public function testTheTotalIsTheUnlocatedCountPlusWhatWasLocated(): void {
		$this->locator->method('locateMany')->willReturn(['u-1' => ['registerId' => 1, 'schemaId' => 2]]);
		$this->queuedRows = [[['n' => '6']]];
		$backfill = new TaskSubjectLocationBackfill($this->db, $this->locator);
		$backfill->locateBatch(['u-1']);

		$this->assertSame(7, $backfill->total());
		$this->assertStringContainsString('select(count(*, ))', implode("\n", $this->queryLog));
	}
}

<?php

/**
 * ProcessingLogMapper appends, batches, counts, prunes and pages the processing log.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\ProcessingLogEntry;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\ProcessingLogReadScope;
use OCA\OpenRegister\Db\ProcessingLog\ProcessingLogSubject;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\ProcessingLogMapper
 * @covers \OCA\OpenRegister\Db\ProcessingLogEntry
 * @uses   \OCA\OpenRegister\Db\ProcessingLogReadScope
 */
class ProcessingLogMapperWritesAndCountsTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * The connection behind the mapper.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * A mapper over the recording query builder.
	 *
	 * @return ProcessingLogMapper
	 */
	private function mapper(): ProcessingLogMapper {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$this->db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
		return new ProcessingLogMapper($this->db);
	}//end mapper()

	/**
	 * The whole query log as one string.
	 *
	 * @return string
	 */
	private function log(): string {
		return implode("\n", $this->queryLog);
	}//end log()

	/**
	 * An entry without uuid, created or count gets all three filled before the insert.
	 *
	 * @return void
	 */
	public function testInsertFillsUuidCreatedAndCount(): void {
		$entry = new ProcessingLogEntry();
		$entry->setObjectCount(null);

		$saved = $this->mapper()->insert($entry);

		$this->assertSame($entry, $saved);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$saved->getUuid());
		$this->assertInstanceOf(DateTime::class, $saved->getCreated());
		$this->assertSame(1, $saved->getObjectCount());
		$this->assertStringContainsString('insert(openregister_processing_log)', $this->log());
		$this->assertStringContainsString('= ' . $saved->getUuid() . ', ', $this->log());
	}//end testInsertFillsUuidCreatedAndCount()

	/**
	 * An empty uuid counts as missing and is replaced.
	 *
	 * @return void
	 */
	public function testInsertReplacesAnEmptyUuid(): void {
		$entry = new ProcessingLogEntry();
		$entry->setUuid('');

		$this->mapper()->insert($entry);

		$this->assertNotSame('', $entry->getUuid());
		$this->assertSame(36, strlen((string)$entry->getUuid()));
	}//end testInsertReplacesAnEmptyUuid()

	/**
	 * Values the caller set are kept as given.
	 *
	 * @return void
	 */
	public function testInsertKeepsGivenUuidCreatedAndCount(): void {
		$created = new DateTime('2026-03-04T05:06:07+00:00');
		$entry = new ProcessingLogEntry();
		$entry->setUuid('given-uuid');
		$entry->setCreated($created);
		$entry->setObjectCount(7);

		$this->mapper()->insert($entry);

		$this->assertSame('given-uuid', $entry->getUuid());
		$this->assertSame($created, $entry->getCreated());
		$this->assertSame(7, $entry->getObjectCount());
	}//end testInsertKeepsGivenUuidCreatedAndCount()

	/**
	 * An empty batch writes nothing and opens no transaction.
	 *
	 * @return void
	 */
	public function testAnEmptyBatchOpensNoTransaction(): void {
		$mapper = $this->mapper();
		$this->db->expects($this->never())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');

		$this->assertSame(0, $mapper->insertBatch([]));
		$this->assertSame([], $this->queryLog);
	}//end testAnEmptyBatchOpensNoTransaction()

	/**
	 * A batch inserts every entry inside one committed transaction.
	 *
	 * @return void
	 */
	public function testABatchInsertsEveryEntryAndCommits(): void {
		$mapper = $this->mapper();
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$first = new ProcessingLogEntry();
		$second = new ProcessingLogEntry();
		$third = new ProcessingLogEntry();

		$this->assertSame(3, $mapper->insertBatch([$first, $second, $third]));
		$this->assertSame(3, substr_count($this->log(), 'insert(openregister_processing_log)'));
		$this->assertNotNull($third->getUuid());
	}//end testABatchInsertsEveryEntryAndCommits()

	/**
	 * A failing insert rolls the batch back and rethrows.
	 *
	 * @return void
	 */
	public function testAFailingInsertRollsTheBatchBack(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$calls = 0;
		$this->db->method('getQueryBuilder')->willReturnCallback(
			function () use (&$calls) {
				$calls++;
				if ($calls === 2) {
					throw new RuntimeException('disk full');
				}

				return $this->recordingBuilder();
			}
		);
		$mapper = new ProcessingLogMapper($this->db);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('disk full');

		$mapper->insertBatch([new ProcessingLogEntry(), new ProcessingLogEntry()]);
	}//end testAFailingInsertRollsTheBatchBack()

	/**
	 * Counting groups by activity and scopes to the organisation and register.
	 *
	 * @return void
	 */
	public function testCountByActivityScopesAndMapsRows(): void {
		$this->queuedRows = [[
			['activity_id' => 'act-1', 'cnt' => '4'],
			['activity_id' => null, 'cnt' => 2],
		]];

		$counts = $this->mapper()->countByActivity('org-1', 'reg-9');

		$this->assertSame(['act-1' => 4, '' => 2], $counts);
		$log = $this->log();
		$this->assertStringContainsString('groupBy(activity_id)', $log);
		$this->assertStringContainsString('eq(organisation_id', $log);
		$this->assertStringContainsString('= org-1, ', $log);
		$this->assertStringContainsString('eq(register_id', $log);
		$this->assertStringContainsString('= reg-9, ', $log);
	}//end testCountByActivityScopesAndMapsRows()

	/**
	 * Without an organisation or register the count spans everything.
	 *
	 * @return void
	 */
	public function testCountByActivityWithoutScopeAddsNoCondition(): void {
		$this->queuedRows = [[]];

		$this->assertSame([], $this->mapper()->countByActivity(null, ''));
		$this->assertStringNotContainsString('andWhere', $this->log());
	}//end testCountByActivityWithoutScopeAddsNoCondition()

	/**
	 * The retention prune deletes strictly before the cutoff and returns the row count.
	 *
	 * @return void
	 */
	public function testDeleteCreatedBeforeUsesAStrictCutoff(): void {
		$cutoff = new DateTime('2025-01-01T00:00:00+00:00');

		$this->assertSame(1, $this->mapper()->deleteCreatedBefore($cutoff));

		$log = $this->log();
		$this->assertStringContainsString('delete(openregister_processing_log', $log);
		$this->assertStringContainsString('expr.lt(created', $log);
		$this->assertStringContainsString('executeStatement()', $log);
	}//end testDeleteCreatedBeforeUsesAStrictCutoff()

	/**
	 * Every filled column filter becomes an equality, as a string; empty ones do not.
	 *
	 * @return void
	 */
	public function testFindFilteredAppliesFilledColumnFiltersAndPaging(): void {
		$this->mapper()->findListed(
			new ListQuery(limit: 25, offset: 50, filters: ['register_id' => 3, 'schema_id' => '', 'activity_id' => 'act-2', 'actor' => null, 'action' => 'export']),
			new ProcessingLogReadScope(null, null, 'org-4', true)
		);

		$log = $this->log();
		$this->assertStringContainsString('eq(register_id', $log);
		$this->assertStringContainsString('param :p1 = 3, 2', $log);
		$this->assertStringNotContainsString('schema_id', $log);
		$this->assertStringContainsString('eq(activity_id', $log);
		$this->assertStringNotContainsString('eq(actor', $log);
		$this->assertStringContainsString('eq(action', $log);
		$this->assertStringContainsString('eq(organisation_id', $log);
		$this->assertStringNotContainsString('confidential', $log);
		$this->assertStringContainsString('orderBy(created, DESC)', $log);
		$this->assertStringContainsString('setMaxResults(25)', $log);
		$this->assertStringContainsString('setFirstResult(50)', $log);
	}//end testFindFilteredAppliesFilledColumnFiltersAndPaging()

	/**
	 * A default read excludes confidential entries and has no period.
	 *
	 * @return void
	 */
	public function testADefaultReadExcludesConfidentialEntries(): void {
		$this->mapper()->findForSubject(new ProcessingLogSubject('BSN', '111'));

		$log = $this->log();
		$this->assertStringContainsString('eq(confidential', $log);
		$this->assertStringNotContainsString('gte(created', $log);
		$this->assertStringNotContainsString('lte(created', $log);
		$this->assertStringNotContainsString('organisation_id', $log);
		$this->assertStringContainsString('orderBy(created, ASC)', $log);
	}//end testADefaultReadExcludesConfidentialEntries()

	/**
	 * A period bounds the read on both sides, inclusively.
	 *
	 * @return void
	 */
	public function testAPeriodBoundsTheReadOnBothSides(): void {
		$scope = new ProcessingLogReadScope(new DateTime('2026-01-01T00:00:00+00:00'), new DateTime('2026-02-01T00:00:00+00:00'));
		$this->mapper()->findListed(new ListQuery(limit: 100, offset: 0), $scope);

		$log = $this->log();
		$this->assertStringContainsString('gte(created', $log);
		$this->assertStringContainsString('lte(created', $log);
	}//end testAPeriodBoundsTheReadOnBothSides()

	/**
	 * The entry serialises every field, with created as ISO 8601.
	 *
	 * @return void
	 */
	public function testAnEntrySerialisesEveryField(): void {
		$entry = new ProcessingLogEntry();
		$entry->setUuid('u-1');
		$entry->setActivityId('act-1');
		$entry->setActor('anna');
		$entry->setSubjectIds([['type' => 'BSN', 'value' => '111']]);
		$entry->setConfidential(true);
		$entry->setCreated(new DateTime('2026-05-06T07:08:09+00:00'));

		$json = $entry->jsonSerialize();

		$this->assertSame('u-1', $json['uuid']);
		$this->assertSame('act-1', $json['activityId']);
		$this->assertSame('read', $json['action']);
		$this->assertSame('ui', $json['channel']);
		$this->assertSame('anna', $json['actor']);
		$this->assertSame(1, $json['objectCount']);
		$this->assertSame([['type' => 'BSN', 'value' => '111']], $json['subjectIds']);
		$this->assertTrue($json['confidential']);
		$this->assertSame('2026-05-06T07:08:09+00:00', $json['created']);
		$this->assertCount(18, $json);
	}//end testAnEntrySerialisesEveryField()

	/**
	 * An entry without a created stamp serialises created as null.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutCreatedSerialisesNull(): void {
		$json = (new ProcessingLogEntry())->jsonSerialize();

		$this->assertNull($json['created']);
		$this->assertNull($json['uuid']);
		$this->assertFalse($json['confidential']);
	}//end testAnEntryWithoutCreatedSerialisesNull()
	/**
	 * A numeric subject value filters as its string form, on both storage shapes.
	 *
	 * @return void
	 */
	public function testANumericSubjectValueFiltersAsAString(): void {
		$this->mapper()->findListed(new ListQuery(limit: 100, offset: 0, filters: ['subject_id_value' => 111]));

		$log = $this->log();
		$this->assertStringContainsString('param :p1 = 111, 2', $log);
		$this->assertStringContainsString('eq(subject_id_value, :p1', $log);
		$this->assertStringNotContainsString('subject_id_type', $log);
		$this->assertStringContainsString('andWhere(orX(', $log);
	}//end testANumericSubjectValueFiltersAsAString()

	/**
	 * A per-subject extract reduces every list entry it returns and leaves single reads whole.
	 *
	 * @return void
	 */
	public function testThePerSubjectExtractReducesEveryListEntry(): void {
		$this->queuedRows = [[
			['id' => 1, 'subject_ids' => '[{"type":"BSN","value":"111"},{"type":"BSN","value":"333"}]', 'object_uuids' => '["a"]'],
			['id' => 2, 'object_uuid' => 'b', 'subject_id_type' => 'BSN', 'subject_id_value' => '111', 'query_params' => '{"q":"x"}'],
		]];

		$entries = $this->mapper()->findForSubject(new ProcessingLogSubject('BSN', '111'));

		$this->assertCount(2, $entries);
		$this->assertSame([['type' => 'BSN', 'value' => '111']], $entries[0]->getSubjectIds());
		$this->assertNull($entries[0]->getObjectUuids());
		$this->assertSame('111', $entries[0]->getSubjectIdValue());
		$this->assertSame(['q' => 'x'], $entries[1]->getQueryParams());
		$this->assertNull($entries[1]->getSubjectIds());
	}//end testThePerSubjectExtractReducesEveryListEntry()
}//end class

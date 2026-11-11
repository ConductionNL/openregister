<?php

/**
 * ProcessingLogMapper::findForSubject covers list reads and reduces them to the one subject.
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

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Db\ProcessingLog\ProcessingLogSubject;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ProcessingLogMapper
 * @uses   \OCA\OpenRegister\Db\ProcessingLogEntry
 */
class ProcessingLogMapperFindBySubjectTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(): ProcessingLogMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
		return new ProcessingLogMapper($db);
	}

	public function testTheQueryAlsoMatchesTheExactElementOfSubjectIds(): void {
		$this->mapper()->findForSubject(new ProcessingLogSubject('BSN', '111'));

		$log = implode("\n", $this->queryLog);
		$this->assertStringContainsString('%{"type":"BSN","value":"111"}%', str_replace('\\"', '"', $log));
		$this->assertStringContainsString('expr.like(subject_ids', $log);
		$this->assertStringContainsString('expr.orX(', $log);
	}

	public function testTheFilterAlsoSearchesSubjectIds(): void {
		$this->mapper()->findListed(new ListQuery(limit: 100, offset: 0, filters: ['subject_id_type' => 'BSN', 'subject_id_value' => '111']));

		$log = str_replace('\\"', '"', implode("\n", $this->queryLog));
		$this->assertStringContainsString('%{"type":"BSN","value":"111"}%', $log);
		$this->assertStringContainsString('expr.like(subject_ids', $log);
		$this->assertStringContainsString('expr.orX(', $log);
	}

	public function testAFilterOnTheValueAloneEndsAtTheElement(): void {
		$this->mapper()->findListed(new ListQuery(limit: 100, offset: 0, filters: ['subject_id_value' => '111']));

		$this->assertStringContainsString('%"value":"111"}%', str_replace('\\"', '"', implode("\n", $this->queryLog)));
	}

	public function testAFilterOnTheTypeAloneStartsAtTheElement(): void {
		$this->mapper()->findListed(new ListQuery(limit: 100, offset: 0, filters: ['subject_id_type' => 'BSN']));

		$this->assertStringContainsString('%{"type":"BSN",%', str_replace('\\"', '"', implode("\n", $this->queryLog)));
	}

	public function testNoSubjectFilterLeavesSubjectIdsOutOfTheQuery(): void {
		$this->mapper()->findListed(new ListQuery(limit: 100, offset: 0, filters: ['actor' => 'admin', 'subject_id_type' => '', 'subject_id_value' => null]));

		$this->assertStringNotContainsString('subject_id', implode("\n", $this->queryLog));
	}

	public function testAFilteredListEntryStaysWhole(): void {
		$this->queuedRows = [[[
			'id' => 4,
			'object_count' => 2,
			'subject_ids' => '[{"type":"BSN","value":"111"},{"type":"BSN","value":"222"}]',
			'object_uuids' => '["uuid-a","uuid-b"]',
		]]];

		$entry = $this->mapper()->findListed(new ListQuery(limit: 100, offset: 0, filters: ['subject_id_type' => 'BSN', 'subject_id_value' => '111']))[0];

		$this->assertCount(2, $entry->getSubjectIds());
		$this->assertSame(['uuid-a', 'uuid-b'], $entry->getObjectUuids());
	}

	public function testAListEntryComesBackReducedToTheOneSubject(): void {
		$this->queuedRows = [[[
			'id' => 4,
			'object_count' => 2,
			'subject_ids' => '[{"type":"BSN","value":"111"},{"type":"BSN","value":"222"}]',
			'object_uuids' => '["uuid-a","uuid-b"]',
			'query_params' => '{"_search":"jansen"}',
		]]];

		$entry = $this->mapper()->findForSubject(new ProcessingLogSubject('BSN', '111'))[0];

		$this->assertSame([['type' => 'BSN', 'value' => '111']], $entry->getSubjectIds());
		$this->assertSame('111', $entry->getSubjectIdValue());
		$this->assertSame('BSN', $entry->getSubjectIdType());
		$this->assertNull($entry->getObjectUuids());
		$this->assertNull($entry->getQueryParams());
		$this->assertSame(2, $entry->getObjectCount());
		$this->assertStringNotContainsString('222', (string)json_encode($entry));
		$this->assertStringNotContainsString('jansen', (string)json_encode($entry));
	}

	public function testASingleReadEntryIsLeftAlone(): void {
		$this->queuedRows = [[[
			'id' => 5,
			'object_uuid' => 'uuid-a',
			'subject_id_type' => 'BSN',
			'subject_id_value' => '111',
		]]];

		$entry = $this->mapper()->findForSubject(new ProcessingLogSubject('BSN', '111'))[0];

		$this->assertSame('uuid-a', $entry->getObjectUuid());
		$this->assertNull($entry->getSubjectIds());
	}
}

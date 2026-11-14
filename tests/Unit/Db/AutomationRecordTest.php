<?php

/**
 * Unit tests for AutomationRecord, AutomationRecordFilter and AutomationRecordMapper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordFilter;
use OCA\OpenRegister\Db\AutomationRecordMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The record serialises without recipients, the filter normalises request
 * parameters, and the mapper filters by key prefix and prunes by age.
 *
 * @covers \OCA\OpenRegister\Db\AutomationRecord
 * @covers \OCA\OpenRegister\Db\AutomationRecordFilter
 * @covers \OCA\OpenRegister\Db\AutomationRecordMapper
 */
final class AutomationRecordTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A database whose query builders record every call.
	 *
	 * @return IDBConnection The database.
	 */
	private function database(): IDBConnection {
		$this->queryLog       = [];
		$this->builderCount   = 0;
		$this->parameterCount = 0;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $text): string => addcslashes($text, '%_\\'));

		return $db;
	}//end database()

	public function testARecordSerialisesItsCountsAndNoRecipients(): void {
		$record = new AutomationRecord();
		$record->setKind(AutomationRecord::KIND_NOTIFICATION);
		$record->setAutomationKey('aut-7f3a');
		$record->setName('case-assigned');
		$record->setObjectUuid('obj-1');
		$record->setOutcome('partial');
		$record->setChannels('{"email":{"sent":2,"failed":1}}');
		$record->setRecordedAt(new DateTime('2026-11-13 10:00:00', new DateTimeZone('UTC')));

		$json = $record->jsonSerialize();

		$this->assertSame('aut-7f3a', $json['key']);
		$this->assertSame('case-assigned', $json['name']);
		$this->assertSame('obj-1', $json['object']);
		$this->assertSame(['email' => ['sent' => 2, 'failed' => 1]], $json['channels']);
		$this->assertSame('2026-11-13T10:00:00+00:00', $json['recordedAt']);
		$this->assertArrayNotHasKey('recipient', $json);
		$this->assertArrayNotHasKey('recipients', $json);
	}

	public function testUnreadableChannelsReadAsNone(): void {
		$record = new AutomationRecord();
		$this->assertSame([], $record->channelCounts());

		$record->setChannels('not json');
		$this->assertSame([], $record->channelCounts());
	}

	public function testTheFilterNormalisesRequestParameters(): void {
		$filter = AutomationRecordFilter::fromParams(
			[
				'kind'   => 'lifecycle-action',
				'key'    => ' aut- ',
				'schema' => '12',
				'object' => 'obj-1',
				'since'  => '2026-11-01T10:00:00+01:00',
				'until'  => 'not a date',
				'limit'  => '9999',
				'offset' => '-4',
			]
		);

		$this->assertSame('lifecycle-action', $filter->kind);
		$this->assertSame('aut-', $filter->keyPrefix);
		$this->assertSame('12', $filter->schema);
		$this->assertSame('obj-1', $filter->object);
		$this->assertSame('2026-11-01 09:00:00', $filter->since?->format('Y-m-d H:i:s'));
		$this->assertNull($filter->until);
		$this->assertSame(AutomationRecordFilter::MAX_LIMIT, $filter->limit);
		$this->assertSame(0, $filter->offset);
	}

	public function testAnUnknownKindAndAMissingLimitFallBack(): void {
		$filter = AutomationRecordFilter::fromParams(['kind' => 'webhook', 'limit' => '0', 'key' => ['array']]);

		$this->assertSame('', $filter->kind);
		$this->assertSame('', $filter->keyPrefix);
		$this->assertSame(AutomationRecordFilter::DEFAULT_LIMIT, $filter->limit);

		$paged = $filter->page(limit: 10, offset: 20);
		$this->assertSame([10, 20, ''], [$paged->limit, $paged->offset, $paged->kind]);
	}

	public function testFindFilteredAppliesEveryFilterNewestFirst(): void {
		$this->queuedRows = [[['id' => 3, 'kind' => 'notification', 'automation_key' => 'aut-1', 'name' => 'n', 'outcome' => 'sent', 'recorded_at' => '2026-11-13 10:00:00']]];
		$mapper = new AutomationRecordMapper($this->database());

		$records = $mapper->findFiltered(
			new AutomationRecordFilter(
				kind: 'notification',
				keyPrefix: 'aut_',
				schema: '12',
				object: 'obj-1',
				since: new DateTime('2026-11-01 10:00:00', new DateTimeZone('Europe/Amsterdam')),
				until: new DateTime('2026-11-02 10:00:00', new DateTimeZone('UTC')),
				limit: 25,
				offset: 50
			)
		);

		$this->assertCount(1, $records);
		$this->assertSame('aut-1', $records[0]->getAutomationKey());
		$log = implode("\n", $this->queryLog);
		$this->assertStringContainsString('from(openregister_automation_records, NULL)', $log);
		$this->assertStringContainsString('eq(kind, :p1', $log);
		$this->assertStringContainsString('eq(schema_id, :p2', $log);
		$this->assertStringContainsString('eq(object_uuid, :p3', $log);
		$this->assertStringContainsString('like(automation_key, :p4', $log);
		$this->assertStringContainsString('param :p4 = aut\\_%', $log);
		$this->assertStringContainsString('param :p5 = 2026-11-01 09:00:00', $log);
		$this->assertStringContainsString('param :p6 = 2026-11-02 10:00:00', $log);
		$this->assertStringContainsString('orderBy(recorded_at, DESC)', $log);
		$this->assertStringContainsString('setMaxResults(25)', $log);
		$this->assertStringContainsString('setFirstResult(50)', $log);
	}

	public function testAnEmptyFilterAddsNoConditions(): void {
		$this->queuedRows = [[]];
		$this->assertSame([], (new AutomationRecordMapper($this->database()))->findFiltered(new AutomationRecordFilter()));

		$log = implode("\n", $this->queryLog);
		$this->assertStringNotContainsString('andWhere', $log);
	}

	public function testDeleteOlderThanCutsAtTheUtcMoment(): void {
		$mapper = new AutomationRecordMapper($this->database());

		$this->assertSame(1, $mapper->deleteOlderThan(new DateTime('2026-08-15 12:00:00', new DateTimeZone('Europe/Amsterdam'))));
		$log = implode("\n", $this->queryLog);
		$this->assertStringContainsString('delete(openregister_automation_records', $log);
		$this->assertStringContainsString('lt(recorded_at, :p1', $log);
		$this->assertStringContainsString('param :p1 = 2026-08-15 10:00:00', $log);
	}
}

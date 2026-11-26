<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailCriteria;
use OCA\OpenRegister\Db\AuditTrailPageQueryInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Audit\AuditReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The audit report lists the trail newest first with the spec's columns and a summary counted from
 * the same rows (rapportage-bi-export, audit report generation).
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
 */
#[CoversClass(AuditReport::class)]
#[UsesClass(AuditTrail::class)]
#[UsesClass(AuditTrailCriteria::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectFindOptions::class)]
final class AuditReportTest extends TestCase {

	/** @var array<int, array{filters: array<string, mixed>, ceiling: int}> */
	private array $collected = [];

	/** @var array<int, array<int, mixed>> */
	private array $finds = [];

	/**
	 * @param array<int, AuditTrail>  $entries   What the trail answers.
	 * @param array<string, string>   $names     Object names by uuid; an absent uuid is a gone object.
	 * @param bool                    $truncated Whether the trail says it was cut.
	 */
	private function report(array $entries, array $names = [], bool $truncated = false): AuditReport {
		$trail = $this->createMock(AuditTrailPageQueryInterface::class);
		$trail->method('collect')->willReturnCallback(
			function (AuditTrailCriteria $criteria, int $ceiling) use ($entries, $truncated): array {
				$this->collected[] = ['filters' => $criteria->filters, 'ceiling' => $ceiling];
				return ['results' => $entries, 'truncated' => $truncated];
			}
		);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true) use ($names): ?ObjectEntity {
				$this->finds[] = [$id, $_rbac, $_multitenancy];
				if ($id === 'boom') {
					throw new RuntimeException('gone');
				}

				if (isset($names[$id]) === false) {
					return null;
				}

				$object = new ObjectEntity();
				$object->setName($names[$id]);

				return $object;
			}
		);

		return new AuditReport(trail: $trail, objects: $objects);
	}

	private static function entry(int $id, string $created, string $action, string $object, string $user = 'medewerker-1', ?array $changed = null): AuditTrail {
		$entry = new AuditTrail();
		$entry->setId($id);
		$entry->setCreated(new DateTime($created));
		$entry->setAction($action);
		$entry->setObjectUuid($object);
		$entry->setUser($user);
		$entry->setIpAddress('10.0.0.'.$id);
		$entry->setChanged($changed);

		return $entry;
	}

	public function testTheFiltersReachTheTrailAndBlankOnesAreLeftOut(): void {
		$this->report(entries: [])->build(registerId: 7, userId: 'medewerker-1', from: '2026-03-01', to: '2026-03-31');
		$this->report(entries: [])->build(registerId: null, userId: '  ', from: null, to: '');

		$this->assertSame(
			[
				['filters' => ['register' => '7', 'actor' => 'medewerker-1', 'from' => '2026-03-01', 'to' => '2026-03-31'], 'ceiling' => AuditReport::MAX_ENTRIES],
				['filters' => [], 'ceiling' => AuditReport::MAX_ENTRIES],
			],
			$this->collected
		);
	}

	public function testEachEntryIsOneRowWithTheSpecColumnsNewestFirst(): void {
		$report = $this->report(
			entries: [
				self::entry(id: 1, created: '2026-03-02T10:00:00+00:00', action: 'create', object: 'obj-a', changed: ['title' => ['new' => 'x'], 'status' => ['new' => 'y']]),
				self::entry(id: 3, created: '2026-03-05T09:00:00+00:00', action: 'read', object: 'obj-b'),
				self::entry(id: 2, created: '2026-03-05T09:00:00+00:00', action: 'update', object: 'obj-a'),
			],
			names: ['obj-a' => 'Melding 1', 'obj-b' => 'Melding 2']
		)->build(registerId: null, userId: null, from: null, to: null);

		$this->assertSame(['read', 'update', 'create'], array_column($report['entries'], 'action'), 'Newest first; equal moments keep the newest id first.');
		$this->assertSame(
			[
				'timestamp'  => '2026-03-02T10:00:00+00:00',
				'action'     => 'create',
				'objectUuid' => 'obj-a',
				'objectName' => 'Melding 1',
				'userId'     => 'medewerker-1',
				'ipAddress'  => '10.0.0.1',
				'changes'    => 'title, status',
			],
			$report['entries'][2]
		);
		$this->assertSame(AuditReport::COLUMNS, array_keys($report['entries'][0]));
		$this->assertFalse($report['truncated']);
	}

	public function testTheSummaryCountsActionsAndTheMostActiveObjectsFromTheListedRows(): void {
		$report = $this->report(
			entries: [
				self::entry(id: 1, created: '2026-03-01', action: 'read', object: 'obj-a'),
				self::entry(id: 2, created: '2026-03-02', action: 'read', object: 'obj-b'),
				self::entry(id: 3, created: '2026-03-03', action: 'update', object: 'obj-b'),
				self::entry(id: 4, created: '2026-03-04', action: 'read', object: 'obj-b'),
				self::entry(id: 5, created: '2026-03-05', action: 'delete', object: ''),
			],
			names: ['obj-a' => 'A', 'obj-b' => 'B']
		)->build(registerId: null, userId: null, from: null, to: null);

		$this->assertSame(5, $report['summary']['total']);
		$this->assertSame(['read' => 3, 'delete' => 1, 'update' => 1], $report['summary']['actions']);
		$this->assertSame(
			[
				['objectUuid' => 'obj-b', 'objectName' => 'B', 'count' => 3],
				['objectUuid' => 'obj-a', 'objectName' => 'A', 'count' => 1],
			],
			$report['summary']['mostActiveObjects']
		);
	}

	public function testTheMostActiveListIsCappedAndTiesAreOrderedByUuid(): void {
		$entries = [];
		foreach (range(1, 12) as $n) {
			$entries[] = self::entry(id: $n, created: '2026-03-01', action: 'read', object: sprintf('obj-%02d', 13 - $n));
		}

		$most = $this->report(entries: $entries)->build(registerId: null, userId: null, from: null, to: null)['summary']['mostActiveObjects'];

		$this->assertCount(AuditReport::MOST_ACTIVE_LIMIT, $most);
		$this->assertSame('obj-01', $most[0]['objectUuid']);
		$this->assertSame('obj-10', $most[9]['objectUuid']);
	}

	public function testEachObjectNameIsReadOnceWithoutRbacAndAGoneObjectHasNone(): void {
		$report = $this->report(
			entries: [
				self::entry(id: 1, created: '2026-03-01', action: 'read', object: 'obj-a'),
				self::entry(id: 2, created: '2026-03-02', action: 'read', object: 'obj-a'),
				self::entry(id: 3, created: '2026-03-03', action: 'delete', object: 'gone'),
				self::entry(id: 4, created: '2026-03-04', action: 'read', object: 'boom'),
			],
			names: ['obj-a' => 'A']
		)->build(registerId: null, userId: null, from: null, to: null);

		$this->assertSame([['obj-a', false, false], ['boom', false, false], ['gone', false, false]], $this->finds);
		$this->assertSame(['', '', 'A', 'A'], array_column($report['entries'], 'objectName'));
	}

	public function testATruncatedTrailIsSaid(): void {
		$report = $this->report(entries: [self::entry(id: 1, created: '2026-03-01', action: 'read', object: 'obj-a')], truncated: true)
			->build(registerId: null, userId: null, from: null, to: null);

		$this->assertTrue($report['truncated']);
	}

	public function testAnEntryWithoutChangesOrMomentHasEmptyCells(): void {
		$entry = new AuditTrail();
		$entry->setId(9);
		$entry->setAction('read');

		$row = $this->report(entries: [$entry])->build(registerId: null, userId: null, from: null, to: null)['entries'][0];

		$this->assertSame(['', '', '', ''], [$row['timestamp'], $row['objectUuid'], $row['changes'], $row['objectName']]);
		$this->assertSame([], $this->finds, 'No object, no lookup.');
	}
}

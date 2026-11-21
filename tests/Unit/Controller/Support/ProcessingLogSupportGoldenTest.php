<?php

/**
 * Characterisation of two processing-log controller helpers: ProcessingLogPolicy's refusal of
 * an extract period longer than the configured maximum, and ProcessingActivityAuditCounts'
 * per-activity audit query.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use DateTime;
use OCA\OpenRegister\Controller\Support\ProcessingActivityAuditCounts;
use OCA\OpenRegister\Controller\Support\ProcessingLogPolicy;
use OCA\OpenRegister\Tests\Unit\Db\RecordsQueries;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Golden range refusal and audit count query.
 *
 * @covers \OCA\OpenRegister\Controller\Support\ProcessingLogPolicy
 * @covers \OCA\OpenRegister\Controller\Support\ProcessingActivityAuditCounts
 */
final class ProcessingLogSupportGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * A policy whose configured maximum range is this many days.
	 *
	 * @param int $maxDays The configured maximum.
	 *
	 * @return ProcessingLogPolicy
	 */
	private function policy(int $maxDays): ProcessingLogPolicy {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default) => ($app === 'openregister' && $key === 'processing_export_max_range_days') ? $maxDays : -1
		);

		return new ProcessingLogPolicy($config);
	}//end policy()

	/**
	 * An open-ended or in-range period is allowed.
	 *
	 * @return void
	 */
	public function testAllowedRanges(): void {
		$from = new DateTime('2026-01-01T00:00:00+00:00');

		$this->assertNull($this->policy(maxDays: 10)->rangeRefusal(null, null));
		$this->assertNull($this->policy(maxDays: 10)->rangeRefusal($from, null));
		$this->assertNull($this->policy(maxDays: 10)->rangeRefusal(null, $from));
		$this->assertNull($this->policy(maxDays: 10)->rangeRefusal($from, new DateTime('2026-01-11T23:59:59+00:00')));
		$this->assertNull($this->policy(maxDays: 10)->rangeRefusal($from, new DateTime('2025-01-01T00:00:00+00:00')));
	}//end testAllowedRanges()

	/**
	 * A period longer than the maximum is refused with 422, the reason and the maximum.
	 *
	 * @return void
	 */
	public function testTooLongRangeIsRefused(): void {
		$refusal = $this->policy(maxDays: 10)->rangeRefusal(
			new DateTime('2026-01-01T00:00:00+00:00'),
			new DateTime('2026-01-12T00:00:00+00:00')
		);

		$this->assertNotNull($refusal);
		$this->assertSame(422, $refusal->getStatus());
		$this->assertSame(['error' => 'Requested period exceeds the maximum extract range', 'maxDays' => 10], $refusal->getData());
	}//end testTooLongRangeIsRefused()

	/**
	 * The counts query groups the audit rows of the asked activities by activity and action.
	 *
	 * @return void
	 */
	public function testAuditCountsQuery(): void {
		$this->results[] = [
			['processing_activity_id' => 'a-1', 'action' => 'read', 'cnt' => '3'],
			['processing_activity_id' => 'a-1', 'action' => 'update', 'cnt' => 2],
		];
		$counts = new ProcessingActivityAuditCounts($this->recordingDb());

		$this->assertSame([], $counts->byActivity(['', 5]));
		$this->assertSame([], $this->queries);

		$folded = $counts->byActivity(['a-1', '', 'a-2']);
		$this->assertSame(
			'select(processing_activity_id, action) selectAlias(count(*, ), cnt) from(openregister_audit_trails) where(in(processing_activity_id, :p0)) groupBy(processing_activity_id, action) executeQuery()',
			$this->sql()
		);
		$this->assertSame([['a-1', 'a-2'], IQueryBuilder::PARAM_STR_ARRAY], $this->params[':p0']);
		$this->assertSame(['a-1' => ['totalEvents' => 5, 'byAction' => ['read' => 3, 'update' => 2]]], $folded);
	}//end testAuditCountsQuery()
}//end class

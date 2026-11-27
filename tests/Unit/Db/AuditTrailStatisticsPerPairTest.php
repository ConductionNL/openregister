<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/built-in-dashboards/spec.md#requirement-dashboardservice-must-assemble-or-canned-statistics-and-chart-payloads-with-fail-soft-semantics
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The dashboard's grouped audit read: one query grouped by register and schema,
 * for the registers asked, keyed by the stored values as the per-scope query compares them.
 */
class AuditTrailStatisticsPerPairTest extends TestCase {
	use RecordingQueryBuilderTrait;

	public function testOneGroupedQueryKeyedByStoredRegisterAndSchema(): void {
		$this->queuedRows = [[
			['register' => '5', 'schema' => '9', 'total' => '3', 'size' => '30'],
			['register' => '5', 'schema' => null, 'total' => '1', 'size' => null],
			['register' => '6', 'schema' => '9', 'total' => '2', 'size' => '4'],
		]];

		$totals = $this->mapper()->statisticsTotalsPerPair([5, 6]);

		$this->assertSame(
			[
				'5' => ['9' => ['total' => 3, 'size' => 30], '' => ['total' => 1, 'size' => 0]],
				'6' => ['9' => ['total' => 2, 'size' => 4]],
			],
			$totals
		);
		$this->assertSame(1, $this->builderCount, 'one query for every register asked');
		$log = implode("\n", $this->queryLog);
		$this->assertStringContainsString('groupBy', $log);
		$this->assertStringContainsString('register', $log);
		$this->assertStringContainsString('"5","6"', $log, 'register ids are compared as the stored strings');
	}

	public function testNoRegistersReadsNothing(): void {
		$this->assertSame([], $this->mapper()->statisticsTotalsPerPair([]));
		$this->assertSame(0, $this->builderCount);
	}

	private function mapper(): AuditTrailMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());

		return new AuditTrailMapper(
			$db,
			new AuditTrailMapperServices($this->createMock(ContainerInterface::class), new NullLogger(), $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);
	}//end mapper()
}//end class

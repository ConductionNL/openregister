<?php

/**
 * Unit tests for PublishedDigestProbes — the console lists the published files that changed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\RepairRefusedException;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairService;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\PublishedDigestProbes;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Operations\PublishedDigestProbes
 * @covers \OCA\OpenRegister\Service\Operations\ConsistencyCheckService
 * @covers \OCA\OpenRegister\Service\Operations\ConsistencyRepairService
 * @uses   \OCA\OpenRegister\Exception\RepairRefusedException
 */
final class PublishedDigestProbesTest extends TestCase {

	/**
	 * The calls each query builder received, as "method args".
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The rows the probe query answers.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Whether the connection was asked to write.
	 *
	 * @var bool
	 */
	private bool $wrote = false;

	private const ROW = ['id' => 7, 'file_id' => 42, 'object_uuid' => 'object-1', 'sha256' => 'recorded', 'mismatch_sha256' => 'current', 'mismatch_at' => '2026-11-26 03:00:00'];

	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		return $db;
	}

	private function builder(): IQueryBuilder {
		$from = '';
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['isNull', 'isNotNull'] as $op) {
			$expr->method($op)->willReturnCallback(static fn (string $column): string => $op . '(' . $column . ')');
		}
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$args) use ($qb, $method, &$from) {
				$this->calls[] = $method . ' ' . implode(',', array_map('strval', array_filter($args, static fn ($a): bool => $a !== null)));
				if ($method === 'from') {
					$from = $args[0];
				}
				return $qb;
			});
		}
		$qb->method('getSQL')->willReturnCallback(static fn (): string => 'SELECT * FROM ' . $from);
		$qb->method('setMaxResults')->willReturn($qb);
		$qb->method('executeQuery')->willReturnCallback(function () {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn($this->rows);
			return $result;
		});
		$qb->method('executeStatement')->willReturnCallback(function (): int {
			$this->wrote = true;
			return 0;
		});
		return $qb;
	}

	private function check(): ConsistencyCheckService {
		return new ConsistencyCheckService($this->db(), null, null, new PublishedDigestProbes());
	}

	public function testTheProbeIsAddedToTheShippedSet(): void {
		$slugs = $this->check()->slugs();

		$this->assertContains('orphan-relations', $slugs);
		$this->assertSame('published-file-changed', end($slugs));
	}

	public function testAnExplicitProbeListIsNotExtended(): void {
		$service = new ConsistencyCheckService($this->db(), [], null, new PublishedDigestProbes());

		$this->assertSame([], $service->slugs());
	}

	public function testItListsTheOpenRecordsMarkedAsChanged(): void {
		$this->rows = [self::ROW];

		$finding = $this->check()->checkOne('published-file-changed');

		$this->assertSame(1, $finding['count']);
		$this->assertSame([self::ROW], $finding['objects']);
		$this->assertNull($finding['unavailable']);
		$this->assertSame('Published files that changed after publication', $finding['title']);
		$this->assertSame(
			[
				'select id,file_id,object_uuid,sha256,mismatch_sha256,mismatch_at',
				'from openregister_file_digests',
				'where isNull(unpublished_at)',
				'andWhere isNotNull(mismatch_at)',
			],
			$this->calls
		);
	}

	public function testTheRepairPlanNamesTheDecisionRatherThanAnAction(): void {
		$this->assertSame(
			[
				'slug' => 'published-file-changed',
				'table' => 'openregister_file_digests',
				'action' => 'Republish or unpublish each file; which of the two is decided per file.',
				'kind' => 'decide-per-file',
				'column' => 'file_id',
			],
			$this->check()->repairPlan('published-file-changed')
		);
	}

	public function testApplyingTheRepairIsRefusedAndWritesNothing(): void {
		$this->rows = [self::ROW];
		$recorder = $this->createMock(JobRunRecorder::class);
		$recorder->expects($this->never())->method('recordAct');
		$repair = new ConsistencyRepairService($this->db(), $this->check(), $recorder);

		try {
			$repair->apply('published-file-changed', 'admin');
			$this->fail('A changed published file has no repair to apply.');
		} catch (RepairRefusedException $refusal) {
			$this->assertSame('decide-per-file', $refusal->getReason());
			$this->assertSame('The "published-file-changed" check has no repair to apply: republish or unpublish each file it lists.', $refusal->getMessage());
		}

		$this->assertFalse($this->wrote);
	}
}

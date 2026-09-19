<?php

/**
 * Unit tests for ConsistencyCheckService — the check that cannot write.
 *
 * "The check changes nothing" is a promise until something enforces it, and a
 * promise is not a property a test can fail on. What can be failed on is the
 * refusal: a probe handing the check a DELETE must be stopped BEFORE it
 * executes, and the refusal must name the probe. Delete the guard in the
 * service and `testAProbeThatWouldWriteIsRefusedBeforeItRuns` goes red.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Exception\ConsistencyCheckWouldWriteException;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ConsistencyCheckServiceTest extends TestCase {

	/**
	 * How many times a query was executed during a test.
	 *
	 * @var integer
	 */
	private int $executed = 0;

	/**
	 * A query builder that reports the SQL it is told to report.
	 *
	 * @param string             $sql  What the query looks like.
	 * @param array<int, mixed>  $rows What executing it would return.
	 *
	 * @return IQueryBuilder The double.
	 */
	private function query(string $sql, array $rows = []): IQueryBuilder {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getSQL')->willReturn($sql);
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($result): IResult {
				$this->executed++;

				return $result;
			}
		);

		return $qb;
	}

	/**
	 * The service, over one probe.
	 *
	 * @param IQueryBuilder $qb   The query that probe hands over.
	 * @param string        $slug The probe slug.
	 *
	 * @return ConsistencyCheckService The service under test.
	 */
	private function service(IQueryBuilder $qb, string $slug = 'a-probe'): ConsistencyCheckService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new ConsistencyCheckService(
			$db,
			[
				$slug => [
					'title' => 'A probe',
					'description' => 'What it looks for.',
					'repair' => 'Delete the rows.',
					'table' => 'openregister_things',
					'query' => static fn (IQueryBuilder $builder): IQueryBuilder => $builder,
				],
			]
		);
	}

	/**
	 * A probe whose query would write is refused, and never executes.
	 *
	 * The "never executes" half is the one that matters: a refusal raised
	 * after the statement ran would report a clean conscience over changed
	 * data.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testAProbeThatWouldWriteIsRefusedBeforeItRuns(): void {
		$service = $this->service($this->query('DELETE FROM openregister_things WHERE id = 1'));

		$refused = null;

		try {
			$service->check();
		} catch (ConsistencyCheckWouldWriteException $refusal) {
			$refused = $refusal;
		}

		$this->assertNotNull($refused, 'A DELETE was accepted as a consistency check.');
		$this->assertSame('a-probe', $refused->getProbe());
		$this->assertSame(0, $this->executed, 'The refused query still executed.');
	}

	/**
	 * An UPDATE is refused the same way a DELETE is.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testAnUpdateIsRefusedToo(): void {
		$this->expectException(ConsistencyCheckWouldWriteException::class);

		$this->service($this->query('UPDATE openregister_things SET name = ?'))->check();
	}

	/**
	 * A reading probe reports the rows it objects to, and names them.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testAReadingProbeNamesTheObjectsItFound(): void {
		$service = $this->service(
			$this->query(
				'SELECT id FROM openregister_things',
				[['id' => 7, 'target_uuid' => 'gone'], ['id' => 9, 'target_uuid' => 'also-gone']]
			)
		);

		$report = $service->check();

		$this->assertSame(1, $report['checked']);
		$this->assertSame(1, $report['inconsistent']);
		$this->assertSame(2, $report['findings'][0]['count']);
		$this->assertSame('gone', $report['findings'][0]['objects'][0]['target_uuid']);
	}

	/**
	 * A clean instance reports the probes it ran, not an empty report.
	 *
	 * Zero findings and "the check did not run" must not look the same, which
	 * is why `checked` is carried beside `inconsistent`.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testACleanInstanceStillReportsWhatWasChecked(): void {
		$report = $this->service($this->query('SELECT id FROM openregister_things'))->check();

		$this->assertSame(1, $report['checked']);
		$this->assertSame(0, $report['inconsistent']);
		$this->assertSame(0, $report['findings'][0]['count']);
	}

	/**
	 * The shipped probes are a real list, and each carries what a repair of it
	 * would do.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 *
	 * @return void
	 */
	public function testTheShippedProbesEachDescribeTheirRepair(): void {
		$service = new ConsistencyCheckService($this->createMock(IDBConnection::class));

		$this->assertNotEmpty($service->slugs());

		foreach ($service->slugs() as $slug) {
			$plan = $service->repairPlan($slug);

			$this->assertNotNull($plan, 'The probe "'.$slug.'" has no repair plan.');
			$this->assertNotSame('', (string)$plan['action']);
			$this->assertNotSame('', (string)$plan['table']);
		}
	}
}

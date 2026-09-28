<?php

/**
 * Tests for the audit trail's import job queries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/demo-data-purge-by-batch/specs/data-import-export/spec.md#requirement-the-job-id-of-an-app-import-that-created-objects-must-be-recorded-per-app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditTrail
 */
class AuditTrailImportJobQueriesTest extends TestCase {
	/**
	 * Every equality the query was asked for, as column => parameter value.
	 *
	 * @var array<string, mixed>
	 */
	private array $equals = [];

	/**
	 * A mapper whose query builder records its filters and counts $count rows.
	 *
	 * @param int $count What the count query returns.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(int $count): AuditTrailMapper {
		$this->equals = [];

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn((string)$count);

		$lastParameter = null;
		$expression = $this->createMock(IExpressionBuilder::class);
		$expression->method('eq')->willReturnCallback(
			function (string $column) use (&$lastParameter): string {
				$this->equals[$column] = $lastParameter;
				return $column . ' = ?';
			}
		);

		$functions = $this->createMock(IFunctionBuilder::class);

		$query = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere'] as $method) {
			$query->method($method)->willReturnSelf();
		}

		$query->method('func')->willReturn($functions);
		$query->method('expr')->willReturn($expression);
		$query->method('createNamedParameter')->willReturnCallback(
			function (mixed $value) use (&$lastParameter): string {
				$lastParameter = $value;
				return ':p';
			}
		);
		$query->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($query);

		return new AuditTrailMapper(
			$db,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * The count filters on the job id and, by default, on the create action.
	 *
	 * @return void
	 */
	public function testCountByImportJobIdFiltersOnTheJobAndTheCreateAction(): void {
		$mapper = $this->mapper(count: 405);

		$this->assertSame(405, $mapper->countByImportJobId(importJobId: 'job-demo'));
		$this->assertSame('job-demo', $this->equals['import_job_id']);
		$this->assertSame('create', $this->equals['action']);
	}

	/**
	 * A null action counts every row of the job.
	 *
	 * @return void
	 */
	public function testCountByImportJobIdWithANullActionCountsEveryAction(): void {
		$mapper = $this->mapper(count: 12);

		$this->assertSame(12, $mapper->countByImportJobId(importJobId: 'job-demo', action: null));
		$this->assertArrayNotHasKey('action', $this->equals);
	}

	/**
	 * The object UUIDs of a job are distinct, in order, and never empty.
	 *
	 * @return void
	 */
	public function testObjectUuidsByImportJobIdAreDistinctAndNeverEmpty(): void {
		$rows = [];
		foreach (['obj-a', 'obj-b', 'obj-a', '', null] as $uuid) {
			$row = new AuditTrail();
			$row->setObjectUuid($uuid);
			$rows[] = $row;
		}

		$mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByImportJobId'])
			->getMock();
		$mapper->expects($this->once())
			->method('findByImportJobId')
			->with('job-demo', 'create')
			->willReturn($rows);

		$this->assertSame(['obj-a', 'obj-b'], $mapper->objectUuidsByImportJobId(importJobId: 'job-demo'));
	}
}

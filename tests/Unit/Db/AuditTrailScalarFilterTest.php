<?php

/**
 * A scalar audit-trail filter answers a NULL check, an action prefix, a comma list or equality.
 *
 * Runs AuditTrailMapper::findAll() over a migrated in-memory SQLite table, so the
 * assertion is on the rows each AuditTrailFilterTerm selects.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Behaviour of scalar filters in findAll().
 */
class AuditTrailScalarFilterTest extends TestCase {

	private MigratedSqliteDatabase $database;

	private AuditTrailMapper $mapper;

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase(test: $this, tables: ['openregister_audit_trails']);
		$this->mapper   = new AuditTrailMapper(
			db: $this->database->idbConnection(),
			services: new AuditTrailMapperServices(container: $this->createMock(ContainerInterface::class), logger: new NullLogger(), userSession: $this->createMock(IUserSession::class), request: $this->createMock(IRequest::class))
		);

		$rows = [
			['action' => 'create', 'user' => 'ann', 'session' => 's1'],
			['action' => 'portaliq.sent', 'user' => 'cas', 'session' => null],
			['action' => 'portaliq.read', 'user' => 'bob', 'session' => 's2'],
			['action' => 'update', 'user' => 'ann.smith', 'session' => 's3'],
			['action' => 'portaliqx', 'user' => 'bob', 'session' => 's4'],
		];
		foreach ($rows as $index => $row) {
			$id = $index + 1;
			$this->database->insert(
				'openregister_audit_trails',
				[
					'id'          => $id,
					'uuid'        => sprintf('cccccccc-cccc-4ccc-8ccc-%012d', $id),
					'object_uuid' => 'obj',
					'action'      => $row['action'],
					'user'        => $row['user'],
					'session'     => $row['session'],
					'created'     => sprintf('2026-10-14 11:%02d:00', $id),
					'changed'     => '{}',
				]
			);
		}
	}

	/**
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, int>
	 */
	private function ids(array $filters): array {
		$rows = $this->mapper->findTrail(ListQuery::unbounded($filters, ['id' => 'ASC']));
		return array_map(static fn (AuditTrail $row): int => (int)$row->getId(), $rows);
	}

	public function testIsNullSelectsRowsWithoutTheValue(): void {
		$this->assertSame([2], $this->ids(['session' => 'IS NULL']));
	}

	public function testIsNotNullSelectsRowsWithTheValue(): void {
		$this->assertSame([1, 3, 4, 5], $this->ids(['session' => 'IS NOT NULL']));
	}

	public function testAnActionPrefixSelectsTheAppsActionsOnly(): void {
		$this->assertSame([2, 3], $this->ids(['action' => 'portaliq.*']));
	}

	public function testAPrefixOnAnotherColumnIsAPlainValue(): void {
		$this->assertSame([], $this->ids(['user' => 'portaliq.*']));
	}

	public function testACommaListSelectsEachValue(): void {
		$this->assertSame([1, 4], $this->ids(['action' => 'create, update']));
	}

	public function testAPlainValueIsEquality(): void {
		$this->assertSame([3, 5], $this->ids(['user' => 'bob']));
	}

	public function testAnUnderscoreFilterAndAnUnknownColumnAreIgnored(): void {
		$this->assertSame([1, 2, 3, 4, 5], $this->ids(['_search' => 'x', 'nonsense' => 'y']));
	}
}

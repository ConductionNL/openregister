<?php

/**
 * A history can leave out actions and actors in the query, before the limit.
 *
 * A history card that leaves out `read` entries and the System actor needs the
 * store to drop them before it pages: an object read 2,500 times holds no
 * other entry in its newest 500 rows, so a filter applied after the page is
 * read shows nothing. `field[notIn]=a,b` drops the rows whose column is one of
 * the values, case-insensitively.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-not-in-filter/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditTrail
 */
class AuditTrailNotInFilterTest extends TestCase {

	private const OBJECT = 'e0b306d2-c523-5938-9b5c-3474d292dc01';

	/**
	 * The migrated in-memory database.
	 *
	 * @var MigratedSqliteDatabase
	 */
	private MigratedSqliteDatabase $database;

	/**
	 * The mapper under test.
	 *
	 * @var AuditTrailMapper
	 */
	private AuditTrailMapper $mapper;

	/**
	 * Seven rows on one object: reads, System updates and what people did.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase(test: $this, tables: ['openregister_audit_trails']);

		$this->mapper = new AuditTrailMapper(
			db: $this->database->idbConnection(),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			request: $this->createMock(originalClassName: IRequest::class),
			logger: new NullLogger()
		);

		$rows = [
			['action' => 'file.downloaded', 'user' => 'fatma.kaya'],
			['action' => 'read', 'user' => 'fatma.kaya'],
			['action' => 'update', 'user' => 'System'],
			['action' => 'READ', 'user' => 'pieter.jansen'],
			['action' => 'create', 'user' => 'fatma.kaya'],
			['action' => 'update', 'user' => ''],
			['action' => 'read', 'user' => 'system'],
		];
		foreach ($rows as $index => $row) {
			$id = $index + 1;
			$this->database->insert(
				'openregister_audit_trails',
				[
					'id' => $id,
					'uuid' => sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', $id),
					'object_uuid' => self::OBJECT,
					'action' => $row['action'],
					'user' => $row['user'],
					'created' => sprintf('2026-10-14 11:%02d:00', $id),
					'changed' => '{}',
				]
			);
		}
	}//end setUp()

	/**
	 * The ids of the rows a filter answers, in id order.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param int|null             $limit   The page size.
	 *
	 * @return array<int, int>
	 */
	private function ids(array $filters, ?int $limit = null): array {
		$rows = $this->mapper->findAll(limit: $limit, filters: $filters, sort: ['id' => 'ASC']);

		return array_map(fn (AuditTrail $row): int => (int) $row->getId(), $rows);
	}//end ids()

	/**
	 * `action[notIn]=read` drops every read row, whatever its case.
	 *
	 * @return void
	 */
	public function testNotInDropsTheActionsCaseInsensitively(): void {
		$this->assertSame(expected: [1, 3, 5, 6], actual: $this->ids(filters: ['action' => ['notIn' => 'read']]));
	}//end testNotInDropsTheActionsCaseInsensitively()

	/**
	 * `user[notIn]=System` drops the System actor and keeps rows without a user.
	 *
	 * @return void
	 */
	public function testNotInKeepsRowsWithoutTheValue(): void {
		$this->assertSame(expected: [1, 2, 4, 5, 6], actual: $this->ids(filters: ['user' => ['notIn' => 'System']]));
	}//end testNotInKeepsRowsWithoutTheValue()

	/**
	 * Both exclusions apply before the limit, so the first page holds what is left.
	 *
	 * @return void
	 */
	public function testTheExclusionsApplyBeforeTheLimit(): void {
		$filters = [
			'object_uuid' => self::OBJECT,
			'action' => ['notIn' => ['read', 'update']],
			'user' => ['notIn' => 'System'],
		];

		$this->assertSame(expected: [1, 5], actual: $this->ids(filters: $filters, limit: 2));
	}//end testTheExclusionsApplyBeforeTheLimit()

	/**
	 * A comma-separated value names several values, as the equality filter does.
	 *
	 * @return void
	 */
	public function testACommaSeparatedValueNamesSeveral(): void {
		$this->assertSame(expected: [1, 5], actual: $this->ids(filters: ['action' => ['notIn' => 'read, update']]));
	}//end testACommaSeparatedValueNamesSeveral()

	/**
	 * A list without an operator is an in-list, never the string "Array".
	 *
	 * @return void
	 */
	public function testAPlainListIsAnInList(): void {
		$this->assertSame(expected: [3, 6], actual: $this->ids(filters: ['action' => ['update']]));
	}//end testAPlainListIsAnInList()

	/**
	 * An operator the mapper does not know is refused, not read as no filter.
	 *
	 * @return void
	 */
	public function testAnUnknownOperatorIsRefused(): void {
		$this->expectException(exception: InvalidArgumentException::class);

		$this->mapper->findAll(filters: ['action' => ['like' => 'read']]);
	}//end testAnUnknownOperatorIsRefused()
}//end class

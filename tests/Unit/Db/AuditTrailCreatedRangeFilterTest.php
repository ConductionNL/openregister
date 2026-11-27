<?php

/**
 * The instance-wide audit list bounds the creation time.
 *
 * The audit page filters on a date range. `created` as `['gte' => ..., 'lte'
 * => ..., 'lt' => ...]` keeps the rows inside the bounds, on a real (SQLite)
 * table; any other operator is refused rather than dropped, because a dropped
 * bound answers the whole trail.
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
 * @spec openspec/changes/audit-and-dashboard-screens-stated/specs/enhanced-audit-trail/spec.md#requirement-the-audit-trail-page-browses-filters-and-exports-entries-req-ads-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\AppInfo\ClassAliasMap
 * @uses \OCA\OpenRegister\Db\AuditTrailFilterTerm
 */
class AuditTrailCreatedRangeFilterTest extends TestCase {

	/**
	 * The mapper under test.
	 *
	 * @var AuditTrailMapper
	 */
	private AuditTrailMapper $mapper;

	/**
	 * Four rows on four days, two of them on schema 46.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$database = new MigratedSqliteDatabase(test: $this, tables: ['openregister_audit_trails']);

		$this->mapper = new AuditTrailMapper(
			db: $database->idbConnection(),
			services: new AuditTrailMapperServices(container: $this->createMock(originalClassName: ContainerInterface::class), logger: new NullLogger(), userSession: $this->createMock(originalClassName: IUserSession::class), request: $this->createMock(originalClassName: IRequest::class))
		);

		$rows = [
			['created' => '2026-11-23 09:00:00', 'schema' => 46],
			['created' => '2026-11-24 23:59:59', 'schema' => 7],
			['created' => '2026-11-25 00:00:00', 'schema' => 46],
			['created' => '2026-11-26 12:30:00', 'schema' => 7],
		];
		foreach ($rows as $index => $row) {
			$id = $index + 1;
			$database->insert(
				'openregister_audit_trails',
				[
					'id' => $id,
					'uuid' => sprintf('cccccccc-cccc-4ccc-8ccc-%012d', $id),
					'object_uuid' => 'e0b306d2-c523-5938-9b5c-3474d292dc01',
					'action' => 'update',
					'user' => 'admin',
					'schema' => $row['schema'],
					'created' => $row['created'],
					'changed' => '{}',
				]
			);
		}
	}//end setUp()

	/**
	 * The ids of the rows a filter answers, in id order.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, int>
	 */
	private function ids(array $filters): array {
		$rows = $this->mapper->findTrail(new ListQuery(limit: ListQuery::UNBOUNDED, filters: $filters, sort: ['id' => 'ASC']));

		return array_map(fn (AuditTrail $row): int => (int) $row->getId(), $rows);
	}//end ids()

	/**
	 * Both bounds are inclusive with gte and lte.
	 *
	 * @return void
	 */
	public function testGteAndLteKeepTheRowsInsideTheBounds(): void {
		$this->assertSame([2, 3], $this->ids(['created' => ['gte' => '2026-11-24 00:00:00', 'lte' => '2026-11-25 00:00:00']]));
	}//end testGteAndLteKeepTheRowsInsideTheBounds()

	/**
	 * Lt excludes its bound.
	 *
	 * @return void
	 */
	public function testLtExcludesItsBound(): void {
		$this->assertSame([1, 2], $this->ids(['created' => ['lt' => '2026-11-25 00:00:00']]));
	}//end testLtExcludesItsBound()

	/**
	 * A range and a column filter narrow together.
	 *
	 * @return void
	 */
	public function testARangeCombinesWithAColumnFilter(): void {
		$this->assertSame([3], $this->ids(['schema' => 46, 'created' => ['gte' => '2026-11-24 00:00:00']]));
	}//end testARangeCombinesWithAColumnFilter()

	/**
	 * A list of values on `created` stays a list filter, as before.
	 *
	 * @return void
	 */
	public function testAListOfValuesIsStillAListFilter(): void {
		$this->assertSame([4], $this->ids(['created' => ['2026-11-26 12:30:00']]));
	}//end testAListOfValuesIsStillAListFilter()

	/**
	 * An operator the range does not know is refused, not dropped.
	 *
	 * @return void
	 */
	public function testAnUnknownOperatorIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported audit trail filter operator for created: after');

		$this->ids(['created' => ['after' => '2026-11-24 00:00:00']]);
	}//end testAnUnknownOperatorIsRefused()
}//end class

<?php

/**
 * ContentReportMapper query behaviour tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\ContentReportMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Empty identifiers short-circuit, filters apply only when given, writes stamp.
 */
class ContentReportMapperQueriesTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var ContentReportMapper
	 */
	private ContentReportMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new ContentReportMapper($this->recordingDb());
	}//end setUp()

	/**
	 * find() binds the id as an integer.
	 *
	 * @return void
	 */
	public function testFindBindsTheIdAsAnInteger(): void {
		$this->results[] = [['id' => 1, 'reason' => 'spam']];

		$this->assertSame('spam', $this->mapper->find(1)->getReason());
		$this->assertSame('select(*) from(openregister_content_reports) where(eq(id, :p0)) executeQuery()', $this->sql());
		$this->assertSame([1, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testFindBindsTheIdAsAnInteger()

	/**
	 * An empty uuid answers null without touching the database.
	 *
	 * @return void
	 */
	public function testFindByEmptyUuidIsNullWithoutQuery(): void {
		$this->assertNull($this->mapper->findByUuid(''));
		$this->assertSame([], $this->queries);
	}//end testFindByEmptyUuidIsNullWithoutQuery()

	/**
	 * findByUuid() returns the matching report.
	 *
	 * @return void
	 */
	public function testFindByUuidReturnsTheReport(): void {
		$this->results[] = [['id' => 2, 'uuid' => 'r-1']];

		$this->assertSame(2, $this->mapper->findByUuid('r-1')?->getId());
		$this->assertSame('select(*) from(openregister_content_reports) where(eq(uuid, :p0)) executeQuery()', $this->sql());
	}//end testFindByUuidReturnsTheReport()

	/**
	 * findByUuid() of an unknown uuid answers null.
	 *
	 * @return void
	 */
	public function testFindByUnknownUuidIsNull(): void {
		$this->assertNull($this->mapper->findByUuid('r-missing'));
		$this->assertCount(1, $this->queries);
	}//end testFindByUnknownUuidIsNull()

	/**
	 * An empty object uuid lists nothing without a query.
	 *
	 * @return void
	 */
	public function testFindByEmptyObjectUuidIsEmptyWithoutQuery(): void {
		$this->assertSame([], $this->mapper->findByObjectUuid(''));
		$this->assertSame([], $this->queries);
	}//end testFindByEmptyObjectUuidIsEmptyWithoutQuery()

	/**
	 * findByObjectUuid() lists the object's reports newest first.
	 *
	 * @return void
	 */
	public function testFindByObjectUuidListsNewestFirst(): void {
		$this->results[] = [['id' => 5], ['id' => 4]];

		$reports = $this->mapper->findByObjectUuid('obj-1');

		$this->assertSame([5, 4], array_map(static fn (ContentReport $r): int => $r->getId(), $reports));
		$this->assertSame(
			'select(*) from(openregister_content_reports) where(eq(object_uuid, :p0)) orderBy(id, DESC) executeQuery()',
			$this->sql()
		);
	}//end testFindByObjectUuidListsNewestFirst()

	/**
	 * findAll() with no filters, empty filters or a zero limit adds no clause.
	 *
	 * @return void
	 */
	public function testFindAllIgnoresEmptyFilters(): void {
		$this->mapper->findAll(ListQuery::unbounded());
		$this->mapper->findAll(new ListQuery(0, null, ['status' => '', 'organisation_id' => '']));

		$expected = 'select(*) from(openregister_content_reports) orderBy(id, DESC) executeQuery()';
		$this->assertSame($expected, $this->sql(0));
		$this->assertSame($expected, $this->sql(1));
		$this->assertSame([], $this->params);
	}//end testFindAllIgnoresEmptyFilters()

	/**
	 * findAll() applies status, organisation and a positive limit.
	 *
	 * @return void
	 */
	public function testFindAllAppliesEveryFilter(): void {
		$this->mapper->findAll(new ListQuery(25, null, ['status' => 'open', 'organisation_id' => 'org-1']));

		$this->assertSame(
			'select(*) from(openregister_content_reports) orderBy(id, DESC) andWhere(eq(status, :p0)) andWhere(eq(organisation_id, :p1)) setMaxResults(25) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['open', 'org-1'], $this->boundValues());
	}//end testFindAllAppliesEveryFilter()

	/**
	 * insert() mints a uuid and stamps created and updated on a new report.
	 *
	 * @return void
	 */
	public function testInsertMintsUuidAndStamps(): void {
		$report = new ContentReport();
		$report->setReason('abuse');

		$saved = $this->mapper->insert($report);

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $saved->getUuid());
		$this->assertInstanceOf(DateTime::class, $saved->getCreated());
		$this->assertSame($saved->getCreated(), $saved->getUpdated());
		$this->assertSame(42, $saved->getId());
		$this->assertContains($saved->getUuid(), $this->boundValues());
	}//end testInsertMintsUuidAndStamps()

	/**
	 * insert() replaces an empty uuid but keeps a set one and its created stamp.
	 *
	 * @return void
	 */
	public function testInsertKeepsSuppliedUuidAndCreated(): void {
		$created = new DateTime('2026-03-01T00:00:00+00:00');
		$kept    = new ContentReport();
		$kept->setUuid('r-kept');
		$kept->setCreated($created);
		$blank = new ContentReport();
		$blank->setUuid('');

		$this->mapper->insert($kept);
		$this->mapper->insert($blank);

		$this->assertSame('r-kept', $kept->getUuid());
		$this->assertSame($created, $kept->getCreated());
		$this->assertNotSame($created, $kept->getUpdated());
		$this->assertNotSame('', $blank->getUuid());
		$this->assertSame(36, strlen((string) $blank->getUuid()));
	}//end testInsertKeepsSuppliedUuidAndCreated()

	/**
	 * update() restamps updated before writing.
	 *
	 * @return void
	 */
	public function testUpdateRestampsUpdated(): void {
		$old    = new DateTime('2020-01-01T00:00:00+00:00');
		$report = new ContentReport();
		$report->setId(9);
		$report->setUpdated($old);
		$report->resetUpdatedFields();

		$saved = $this->mapper->update($report);

		$this->assertNotSame($old, $saved->getUpdated());
		$this->assertStringStartsWith('update(openregister_content_reports) set(updated, :p0) where(eq(id, :p1))', $this->sql());
	}//end testUpdateRestampsUpdated()
}//end class

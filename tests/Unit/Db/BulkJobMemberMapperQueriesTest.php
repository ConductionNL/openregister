<?php

/**
 * BulkJobMemberMapper query behaviour tests.
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
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Db\OutcomeFilter;
use OCA\OpenRegister\Db\QueryPage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Members list in id order, optionally by outcome and page; create and save stamp.
 */
class BulkJobMemberMapperQueriesTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var BulkJobMemberMapper
	 */
	private BulkJobMemberMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new BulkJobMemberMapper($this->recordingDb());
	}//end setUp()

	/**
	 * findByJob() without a filter lists every member of the job in id order.
	 *
	 * @return void
	 */
	public function testFindByJobWithoutFilterListsAllMembers(): void {
		$this->results[] = [['id' => 1, 'object_uuid' => 'o-1'], ['id' => 2, 'object_uuid' => 'o-2']];

		$members = $this->mapper->findByJob(7);

		$this->assertSame(['o-1', 'o-2'], array_map(static fn (BulkJobMember $m): ?string => $m->getObjectUuid(), $members));
		$this->assertSame(
			'select(*) from(openregister_bulk_job_members) where(eq(job_id, :p0)) orderBy(id, ASC) executeQuery()',
			$this->sql()
		);
		$this->assertSame([7, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testFindByJobWithoutFilterListsAllMembers()

	/**
	 * findByJob() applies the outcome and page of a filter.
	 *
	 * @return void
	 */
	public function testFindByJobAppliesTheFilter(): void {
		$this->mapper->findByJob(7, new OutcomeFilter('refused', new QueryPage(limit: 50, offset: 100)));

		$this->assertSame(
			'select(*) from(openregister_bulk_job_members) where(eq(job_id, :p0)) orderBy(id, ASC) andWhere(eq(outcome, :p1)) setMaxResults(50) setFirstResult(100) executeQuery()',
			$this->sql()
		);
		$this->assertSame('refused', $this->bound(':p1'));
	}//end testFindByJobAppliesTheFilter()

	/**
	 * findByJobAndObject() returns the first member row.
	 *
	 * @return void
	 */
	public function testFindByJobAndObjectReturnsTheMember(): void {
		$this->results[] = [['id' => 4, 'object_uuid' => 'o-4']];

		$member = $this->mapper->findByJobAndObject(7, 'o-4');

		$this->assertSame(4, $member?->getId());
		$this->assertSame(
			'select(*) from(openregister_bulk_job_members) where(eq(job_id, :p0)) andWhere(eq(object_uuid, :p1)) setMaxResults(1) executeQuery()',
			$this->sql()
		);
	}//end testFindByJobAndObjectReturnsTheMember()

	/**
	 * findByJobAndObject() of an object the job never held is null.
	 *
	 * @return void
	 */
	public function testFindByJobAndObjectOfUnknownObjectIsNull(): void {
		$this->assertNull($this->mapper->findByJobAndObject(7, 'o-none'));
	}//end testFindByJobAndObjectOfUnknownObjectIsNull()

	/**
	 * createFromArray() stamps created and updated together when none is given.
	 *
	 * @return void
	 */
	public function testCreateFromArrayStampsBothDates(): void {
		$member = $this->mapper->createFromArray(['jobId' => 7, 'objectUuid' => 'o-9']);

		$this->assertInstanceOf(DateTime::class, $member->getCreated());
		$this->assertSame($member->getCreated(), $member->getUpdated());
		$this->assertSame(42, $member->getId());
		$this->assertStringStartsWith('insert(openregister_bulk_job_members)', $this->sql());
		$this->assertContains('o-9', $this->boundValues());
	}//end testCreateFromArrayStampsBothDates()

	/**
	 * createFromArray() keeps a supplied created stamp.
	 *
	 * @return void
	 */
	public function testCreateFromArrayKeepsSuppliedCreated(): void {
		$created = new DateTime('2026-01-01T00:00:00+00:00');

		$member = $this->mapper->createFromArray(['jobId' => 7, 'created' => $created]);

		$this->assertSame($created, $member->getCreated());
		$this->assertNotSame($created, $member->getUpdated());
	}//end testCreateFromArrayKeepsSuppliedCreated()

	/**
	 * save() restamps updated and writes by id.
	 *
	 * @return void
	 */
	public function testSaveRestampsUpdated(): void {
		$old    = new DateTime('2020-01-01T00:00:00+00:00');
		$member = new BulkJobMember();
		$member->setId(12);
		$member->setUpdated($old);
		$member->resetUpdatedFields();

		$saved = $this->mapper->save($member);

		$this->assertNotSame($old, $saved->getUpdated());
		$this->assertStringStartsWith('update(openregister_bulk_job_members) set(updated, :p0) where(eq(id, :p1))', $this->sql());
		$this->assertSame(12, $this->bound(':p1'));
	}//end testSaveRestampsUpdated()
}//end class

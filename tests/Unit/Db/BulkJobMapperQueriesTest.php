<?php

/**
 * BulkJobMapper query behaviour tests.
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
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Lookups, the per-state tally, filtered listings and the create/save stamps.
 */
class BulkJobMapperQueriesTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var BulkJobMapper
	 */
	private BulkJobMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new BulkJobMapper($this->recordingDb());
	}//end setUp()

	/**
	 * find() binds the id as an integer.
	 *
	 * @return void
	 */
	public function testFindBindsTheIdAsAnInteger(): void {
		$this->results[] = [['id' => 3, 'action' => 'publish']];

		$job = $this->mapper->find(3);

		$this->assertSame('publish', $job->getAction());
		$this->assertSame('select(*) from(openregister_bulk_jobs) where(eq(id, :p0)) executeQuery()', $this->sql());
		$this->assertSame([3, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testFindBindsTheIdAsAnInteger()

	/**
	 * findByUuid() filters on uuid and throws when nothing matches.
	 *
	 * @return void
	 */
	public function testFindByUuidThrowsWhenMissing(): void {
		$this->expectException(DoesNotExistException::class);

		try {
			$this->mapper->findByUuid('u-1');
		} finally {
			$this->assertSame('select(*) from(openregister_bulk_jobs) where(eq(uuid, :p0)) executeQuery()', $this->sql());
			$this->assertSame('u-1', $this->bound(':p0'));
		}
	}//end testFindByUuidThrowsWhenMissing()

	/**
	 * countByState() tallies per state and drops rows without a state.
	 *
	 * @return void
	 */
	public function testCountByStateSkipsBlankStates(): void {
		$this->results[] = [
			['state' => 'running', 'job_count' => '2'],
			['state' => null, 'job_count' => '9'],
			['state' => '', 'job_count' => '4'],
			['state' => 'done'],
		];

		$counts = $this->mapper->countByState();

		$this->assertSame(['running' => 2, 'done' => 0], $counts);
		$this->assertSame(
			'select(state) selectAlias(COUNT(*), job_count) from(openregister_bulk_jobs) groupBy(state) executeQuery()',
			$this->sql()
		);
	}//end testCountByStateSkipsBlankStates()

	/**
	 * findByActor() without a state or page filters on the actor only.
	 *
	 * @return void
	 */
	public function testFindByActorWithoutFiltersQueriesTheActorOnly(): void {
		$this->results[] = [['id' => 2], ['id' => 1]];

		$jobs = $this->mapper->findByActor('jan');

		$this->assertSame([2, 1], array_map(static fn (BulkJob $j): int => $j->getId(), $jobs));
		$this->assertSame(
			'select(*) from(openregister_bulk_jobs) where(eq(started_by, :p0)) orderBy(id, DESC) executeQuery()',
			$this->sql()
		);
	}//end testFindByActorWithoutFiltersQueriesTheActorOnly()

	/**
	 * findByActor() with a state and a page narrows and pages.
	 *
	 * @return void
	 */
	public function testFindByActorWithStateAndPage(): void {
		$this->mapper->findByActor('jan', 'paused', 10, 20);

		$this->assertSame(
			'select(*) from(openregister_bulk_jobs) where(eq(started_by, :p0)) orderBy(id, DESC) andWhere(eq(state, :p1)) setMaxResults(10) setFirstResult(20) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['jan', 'paused'], $this->boundValues());
	}//end testFindByActorWithStateAndPage()

	/**
	 * findAllJobs() lists everything when no state is given.
	 *
	 * @return void
	 */
	public function testFindAllJobsWithoutState(): void {
		$this->mapper->findAllJobs();

		$this->assertSame('select(*) from(openregister_bulk_jobs) orderBy(id, DESC) executeQuery()', $this->sql());
		$this->assertSame([], $this->params);
	}//end testFindAllJobsWithoutState()

	/**
	 * findAllJobs() narrows to a state and pages.
	 *
	 * @return void
	 */
	public function testFindAllJobsWithStateAndPage(): void {
		$this->results[] = [['id' => 1, 'state' => 'failed']];

		$jobs = $this->mapper->findAllJobs('failed', 5, 0);

		$this->assertSame('failed', $jobs[0]->getState());
		$this->assertSame(
			'select(*) from(openregister_bulk_jobs) orderBy(id, DESC) andWhere(eq(state, :p0)) setMaxResults(5) setFirstResult(0) executeQuery()',
			$this->sql()
		);
	}//end testFindAllJobsWithStateAndPage()

	/**
	 * readState() returns the stored state as a string.
	 *
	 * @return void
	 */
	public function testReadStateReturnsTheState(): void {
		$this->results[] = [['state' => 'cancelled']];

		$this->assertSame('cancelled', $this->mapper->readState(4));
		$this->assertSame(
			'select(state) from(openregister_bulk_jobs) where(eq(id, :p0)) setMaxResults(1) executeQuery()',
			$this->sql()
		);
		$this->assertSame([4, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testReadStateReturnsTheState()

	/**
	 * readState() of an unknown job is null.
	 *
	 * @return void
	 */
	public function testReadStateOfUnknownJobIsNull(): void {
		$this->assertNull($this->mapper->readState(99));
	}//end testReadStateOfUnknownJobIsNull()

	/**
	 * createFromArray() mints a uuid and stamps created/updated when absent.
	 *
	 * @return void
	 */
	public function testCreateFromArrayMintsUuidAndStamps(): void {
		$job = $this->mapper->createFromArray(['action' => 'delete', 'state' => 'queued']);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $job->getUuid());
		$this->assertInstanceOf(DateTime::class, $job->getCreated());
		$this->assertSame($job->getCreated(), $job->getUpdated());
		$this->assertSame(42, $job->getId());
		$this->assertStringStartsWith('insert(openregister_bulk_jobs)', $this->sql());
		$this->assertContains('delete', $this->boundValues());
		$this->assertContains($job->getUuid(), $this->boundValues());
	}//end testCreateFromArrayMintsUuidAndStamps()

	/**
	 * createFromArray() keeps a supplied uuid and created stamp.
	 *
	 * @return void
	 */
	public function testCreateFromArrayKeepsSuppliedUuidAndCreated(): void {
		$created = new DateTime('2026-01-02T03:04:05+00:00');

		$job = $this->mapper->createFromArray(['uuid' => 'fixed-uuid', 'created' => $created]);

		$this->assertSame('fixed-uuid', $job->getUuid());
		$this->assertSame($created, $job->getCreated());
		$this->assertNotSame($created, $job->getUpdated());
	}//end testCreateFromArrayKeepsSuppliedUuidAndCreated()

	/**
	 * save() restamps updated and writes the row by id.
	 *
	 * @return void
	 */
	public function testSaveRestampsUpdated(): void {
		$old = new DateTime('2020-01-01T00:00:00+00:00');
		$job = new BulkJob();
		$job->setId(5);
		$job->setUpdated($old);
		$job->resetUpdatedFields();

		$saved = $this->mapper->save($job);

		$this->assertNotSame($old, $saved->getUpdated());
		$this->assertStringStartsWith('update(openregister_bulk_jobs) set(updated, :p0) where(eq(id, :p1))', $this->sql());
		$this->assertSame(5, $this->bound(':p1'));
	}//end testSaveRestampsUpdated()
}//end class

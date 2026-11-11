<?php

/**
 * SchemaRunMapper query behaviour tests.
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

use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The id lookup and the active-run lookup.
 */
class SchemaRunMapperQueriesTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var SchemaRunMapper
	 */
	private SchemaRunMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new SchemaRunMapper($this->recordingDb());
	}//end setUp()

	/**
	 * find() binds the id as an integer.
	 *
	 * @return void
	 */
	public function testFindBindsTheIdAsAnInteger(): void {
		$this->results[] = [['id' => 4, 'state' => SchemaRun::STATE_RUNNING]];

		$run = $this->mapper->find(4);

		$this->assertSame(SchemaRun::STATE_RUNNING, $run->getState());
		$this->assertSame('select(*) from(openregister_schema_runs) where(eq(id, :p0)) executeQuery()', $this->sql());
		$this->assertSame([4, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testFindBindsTheIdAsAnInteger()

	/**
	 * findActiveForSchema() asks for one run of the schema in an active state.
	 *
	 * @return void
	 */
	public function testFindActiveForSchemaFiltersOnActiveStates(): void {
		$this->results[] = [['id' => 9, 'state' => SchemaRun::STATE_DRAFT]];

		$run = $this->mapper->findActiveForSchema(6);

		$this->assertSame(9, $run?->getId());
		$this->assertSame([6, IQueryBuilder::PARAM_INT], $this->params[':p0']);
		$this->assertSame([SchemaRun::ACTIVE_STATES, IQueryBuilder::PARAM_STR_ARRAY], $this->params[':p1']);
	}//end testFindActiveForSchemaFiltersOnActiveStates()

	/**
	 * findActiveForSchema() is null when no run is active.
	 *
	 * @return void
	 */
	public function testFindActiveForSchemaIsNullWithoutARun(): void {
		$this->assertNull($this->mapper->findActiveForSchema(6));
	}//end testFindActiveForSchemaIsNullWithoutARun()
}//end class

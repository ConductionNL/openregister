<?php

/**
 * EndpointLogMapper find and statistics tests.
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

use OCA\OpenRegister\Db\EndpointLogMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Statistics count 2xx as success and the rest as failed, optionally per endpoint.
 */
class EndpointLogMapperStatisticsTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var EndpointLogMapper
	 */
	private EndpointLogMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new EndpointLogMapper($this->recordingDb());
	}//end setUp()

	/**
	 * find() binds the id as an integer.
	 *
	 * @return void
	 */
	public function testFindBindsTheIdAsAnInteger(): void {
		$this->results[] = [['id' => 6, 'status_code' => 201]];

		$this->assertSame(201, $this->mapper->find(6)->getStatusCode());
		$this->assertSame('select(*) from(openregister_endpoint_logs) where(eq(id, :p0)) executeQuery()', $this->sql());
		$this->assertSame([6, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testFindBindsTheIdAsAnInteger()

	/**
	 * find() of a missing log throws.
	 *
	 * @return void
	 */
	public function testFindOfMissingLogThrows(): void {
		$this->expectException(DoesNotExistException::class);
		$this->mapper->find(404);
	}//end testFindOfMissingLogThrows()

	/**
	 * Without an endpoint the totals cover every log; failed is total minus 2xx.
	 *
	 * @return void
	 */
	public function testStatisticsAcrossAllEndpoints(): void {
		$this->results = [[['total' => '10']], [['success' => '7']]];

		$this->assertSame(['total' => 10, 'success' => 7, 'failed' => 3], $this->mapper->getStatistics());
		$this->assertSame('select(count(*, total)) from(openregister_endpoint_logs) executeQuery()', $this->sql(0));
		$this->assertSame(
			'select(count(*, success)) from(openregister_endpoint_logs) where(gte(status_code, :p0)) andWhere(lt(status_code, :p1)) executeQuery()',
			$this->sql(1)
		);
		$this->assertSame([200, 300], $this->boundValues());
	}//end testStatisticsAcrossAllEndpoints()

	/**
	 * An endpoint id narrows both counts to that endpoint.
	 *
	 * @return void
	 */
	public function testStatisticsForOneEndpoint(): void {
		$this->results = [[['total' => 4]], [['success' => 4]]];

		$this->assertSame(['total' => 4, 'success' => 4, 'failed' => 0], $this->mapper->getStatistics(12));
		$this->assertSame('select(count(*, total)) from(openregister_endpoint_logs) where(eq(endpoint_id, :p0)) executeQuery()', $this->sql(0));
		$this->assertStringEndsWith('andWhere(eq(endpoint_id, :p3)) executeQuery()', $this->sql(1));
		$this->assertSame([12, IQueryBuilder::PARAM_INT], $this->params[':p3']);
	}//end testStatisticsForOneEndpoint()

	/**
	 * Empty count rows read as zero.
	 *
	 * @return void
	 */
	public function testStatisticsWithoutRowsAreZero(): void {
		$this->assertSame(['total' => 0, 'success' => 0, 'failed' => 0], $this->mapper->getStatistics());
	}//end testStatisticsWithoutRowsAreZero()
}//end class

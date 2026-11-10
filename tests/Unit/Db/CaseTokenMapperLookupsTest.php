<?php

/**
 * CaseTokenMapper lookup tests.
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

use OCA\OpenRegister\Db\CaseToken;
use OCA\OpenRegister\Db\CaseTokenMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Token and id lookups answer null when nothing matches; the list is newest first.
 */
class CaseTokenMapperLookupsTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var CaseTokenMapper
	 */
	private CaseTokenMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new CaseTokenMapper($this->recordingDb());
	}//end setUp()

	/**
	 * findByToken() returns the row holding that token.
	 *
	 * @return void
	 */
	public function testFindByTokenReturnsTheRow(): void {
		$this->results[] = [['id' => 3, 'token' => 'tok-abc']];

		$this->assertSame(3, $this->mapper->findByToken('tok-abc')?->getId());
		$this->assertSame('select(*) from(openregister_case_tokens) where(eq(token, :p0)) executeQuery()', $this->sql());
		$this->assertSame('tok-abc', $this->bound(':p0'));
	}//end testFindByTokenReturnsTheRow()

	/**
	 * An unknown token answers null.
	 *
	 * @return void
	 */
	public function testFindByUnknownTokenIsNull(): void {
		$this->assertNull($this->mapper->findByToken('tok-none'));
	}//end testFindByUnknownTokenIsNull()

	/**
	 * findById() binds the id as an integer.
	 *
	 * @return void
	 */
	public function testFindByIdBindsAnInteger(): void {
		$this->results[] = [['id' => 8]];

		$this->assertSame(8, $this->mapper->findById(8)?->getId());
		$this->assertSame('select(*) from(openregister_case_tokens) where(eq(id, :p0)) executeQuery()', $this->sql());
		$this->assertSame([8, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testFindByIdBindsAnInteger()

	/**
	 * An unknown id answers null.
	 *
	 * @return void
	 */
	public function testFindByUnknownIdIsNull(): void {
		$this->assertNull($this->mapper->findById(404));
	}//end testFindByUnknownIdIsNull()

	/**
	 * findByObjectUuid() lists that object_uuid's rows newest first.
	 *
	 * @return void
	 */
	public function testListIsNewestFirst(): void {
		$this->results[] = [['id' => 2, 'object_uuid' => 'k-1'], ['id' => 1, 'object_uuid' => 'k-1']];

		$rows = $this->mapper->findByObjectUuid('k-1');

		$this->assertSame([2, 1], array_map(static fn (CaseToken $row): int => $row->getId(), $rows));
		$this->assertSame('k-1', $rows[0]->getObjectUuid());
		$this->assertSame('select(*) from(openregister_case_tokens) where(eq(object_uuid, :p0)) orderBy(created_at, DESC) executeQuery()', $this->sql());
	}//end testListIsNewestFirst()

	/**
	 * findByObjectUuid() without rows is empty.
	 *
	 * @return void
	 */
	public function testListWithoutRowsIsEmpty(): void {
		$this->assertSame([], $this->mapper->findByObjectUuid('k-none'));
	}//end testListWithoutRowsIsEmpty()
}//end class

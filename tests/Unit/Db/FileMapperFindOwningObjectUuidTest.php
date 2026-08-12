<?php

/**
 * FileMapperFindOwningObjectUuidTest
 *
 * Unit tests covering FileMapper::findOwningObjectUuid() — the fileId -> owning
 * object UUID reverse join used by the `_content_search` chunk fan-out.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expose-content-search-in-object-service/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FileMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FileMapperFindOwningObjectUuidTest extends TestCase {
	private IDBConnection&MockObject $db;
	private IURLGenerator&MockObject $urlGenerator;
	private FileMapper $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->mapper = new FileMapper($this->db, $this->urlGenerator);
	}//end setUp()

	private function mockQueryBuilder(): IQueryBuilder&MockObject {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn (string $col, $val): string => "$col = $val");

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('innerJoin')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		return $qb;
	}//end mockQueryBuilder()

	public function testResolvesOwningObjectUuidFromParentFolderName(): void {
		$qb = $this->mockQueryBuilder();
		$stmt = $this->createMock(IResult::class);
		$stmt->method('fetchOne')->willReturn('object-uuid-123');
		$qb->method('executeQuery')->willReturn($stmt);

		$uuid = $this->mapper->findOwningObjectUuid(fileId: 42);

		$this->assertSame('object-uuid-123', $uuid);
	}//end testResolvesOwningObjectUuidFromParentFolderName()

	public function testReturnsNullWhenNoParentFolderMatches(): void {
		$qb = $this->mockQueryBuilder();
		$stmt = $this->createMock(IResult::class);
		$stmt->method('fetchOne')->willReturn(false);
		$qb->method('executeQuery')->willReturn($stmt);

		$uuid = $this->mapper->findOwningObjectUuid(fileId: 999);

		$this->assertNull($uuid);
	}//end testReturnsNullWhenNoParentFolderMatches()

	public function testReturnsNullWhenParentFolderNameIsEmpty(): void {
		$qb = $this->mockQueryBuilder();
		$stmt = $this->createMock(IResult::class);
		$stmt->method('fetchOne')->willReturn('');
		$qb->method('executeQuery')->willReturn($stmt);

		$uuid = $this->mapper->findOwningObjectUuid(fileId: 7);

		$this->assertNull($uuid);
	}//end testReturnsNullWhenParentFolderNameIsEmpty()
}//end class

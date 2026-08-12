<?php

declare(strict_types=1);

/**
 * PgVectorPlatform Unit Tests
 *
 * Covers the shared pgvector fast-path capability helper: platform detection,
 * embedding_vector column-dimension lookup (with caching and tolerant
 * degradation), and pgvector literal formatting.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vectorization\Handlers
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/hybrid-document-search/tasks.md#3.1
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Vectorization\Handlers;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Service\Vectorization\Handlers\PgVectorPlatform;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PgVectorPlatformTest extends TestCase {
	private IDBConnection&MockObject $db;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	public function testIsPostgresTrueOnPostgreSqlPlatform(): void {
		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		$helper = new PgVectorPlatform($this->db, $this->logger);

		$this->assertTrue($helper->isPostgres());
	}//end testIsPostgresTrueOnPostgreSqlPlatform()

	public function testIsPostgresFalseOnMariaDbPlatform(): void {
		$this->db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());

		$helper = new PgVectorPlatform($this->db, $this->logger);

		$this->assertFalse($helper->isPostgres());
	}//end testIsPostgresFalseOnMariaDbPlatform()

	public function testGetVectorColumnDimensionNullOnNonPostgres(): void {
		$this->db->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$this->db->expects($this->never())->method('executeQuery');

		$helper = new PgVectorPlatform($this->db, $this->logger);

		$this->assertNull($helper->getVectorColumnDimension());
	}//end testGetVectorColumnDimensionNullOnNonPostgres()

	public function testGetVectorColumnDimensionReadsAtttypmodAndCaches(): void {
		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(768);

		// Cached after the first lookup: exactly one catalog query.
		$this->db->expects($this->once())
			->method('executeQuery')
			->with($this->stringContains('pg_attribute'))
			->willReturn($result);

		$helper = new PgVectorPlatform($this->db, $this->logger);

		$this->assertSame(768, $helper->getVectorColumnDimension());
		$this->assertSame(768, $helper->getVectorColumnDimension());
	}//end testGetVectorColumnDimensionReadsAtttypmodAndCaches()

	public function testGetVectorColumnDimensionNullWhenColumnMissing(): void {
		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		// regclass lookup throws when the table/column doesn't exist.
		$this->db->method('executeQuery')
			->willThrowException(new \Exception('relation does not exist'));

		$helper = new PgVectorPlatform($this->db, $this->logger);

		$this->assertNull($helper->getVectorColumnDimension());
	}//end testGetVectorColumnDimensionNullWhenColumnMissing()

	public function testGetVectorColumnDimensionNullOnNonPositiveTypmod(): void {
		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(false);

		$this->db->method('executeQuery')->willReturn($result);

		$helper = new PgVectorPlatform($this->db, $this->logger);

		$this->assertNull($helper->getVectorColumnDimension());
	}//end testGetVectorColumnDimensionNullOnNonPositiveTypmod()

	public function testFormatVectorProducesPgvectorLiteral(): void {
		$helper = new PgVectorPlatform($this->db, $this->logger);

		$this->assertSame('[0.5,-0.25,1]', $helper->formatVector([0.5, -0.25, 1.0]));
		$this->assertSame('[]', $helper->formatVector([]));
	}//end testFormatVectorProducesPgvectorLiteral()
}//end class

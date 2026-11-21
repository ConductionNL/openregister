<?php

/**
 * Characterisation tests for PgVectorPlatform::getVectorColumnDimension().
 *
 * Pins what the sibling PgVectorPlatformTest leaves open: a failed catalog
 * lookup is logged at debug level with the error and cached as null (the
 * catalog is asked once per request), a zero typmod is null, and the
 * non-PostgreSQL answer is cached without a query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\PgVectorPlatform;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PgVectorPlatformDimensionCharacterisationTest extends TestCase {

	public function testAFailedLookupIsLoggedAtDebugAndCachedAsNull(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->expects($this->once())->method('executeQuery')
			->with(
				"SELECT a.atttypmod FROM pg_attribute a WHERE a.attrelid = '" . PgVectorPlatform::SIDECAR_TABLE . "'::regclass "
				. "AND a.attname = 'embedding' AND NOT a.attisdropped"
			)
			->willThrowException(new \Exception('relation does not exist'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'[PgVectorPlatform] pgvector ANN sidecar unavailable',
			$this->callback(static fn (array $context): bool => $context['error'] === 'relation does not exist')
		);

		$platform = new PgVectorPlatform($db, $logger);

		$this->assertNull($platform->getVectorColumnDimension());
		$this->assertNull($platform->getVectorColumnDimension());
	}

	public function testAZeroTypmodIsNullAndClosesTheCursor(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn('0');
		$result->expects($this->once())->method('closeCursor');
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->expects($this->once())->method('executeQuery')->willReturn($result);

		$platform = new PgVectorPlatform($db, $this->createMock(LoggerInterface::class));

		$this->assertNull($platform->getVectorColumnDimension());
		$this->assertNull($platform->getVectorColumnDimension());
	}

	public function testAPositiveTypmodIsTheDimension(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn('768');
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$db->expects($this->once())->method('executeQuery')->willReturn($result);

		$platform = new PgVectorPlatform($db, $this->createMock(LoggerInterface::class));

		$this->assertSame(768, $platform->getVectorColumnDimension());
		$this->assertSame(768, $platform->getVectorColumnDimension());
	}

	public function testANonPostgresPlatformIsCachedNullWithoutAQuery(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
		$db->expects($this->never())->method('executeQuery');

		$platform = new PgVectorPlatform($db, $this->createMock(LoggerInterface::class));

		$this->assertNull($platform->getVectorColumnDimension());
		$this->assertNull($platform->getVectorColumnDimension());
	}
}

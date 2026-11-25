<?php

/**
 * MagicMapper::canBuildTextSearchIndex() answers whether a trigram index can be built.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-the-property-editor-offers-both-index-kinds
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * PostgreSQL with pg_trgm can; PostgreSQL without it and MariaDB cannot.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper::canBuildTextSearchIndex
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 */
final class MagicMapperTextSearchIndexTest extends TestCase {

	/** @var array<int, string> */
	private array $prepared = [];

	/**
	 * A mapper on the given platform whose extension count is the given number.
	 *
	 * @param bool $postgres   Whether the platform is PostgreSQL.
	 * @param int  $extensions How many pg_trgm rows pg_extension holds.
	 *
	 * @return MagicMapper
	 */
	private function mapper(bool $postgres, int $extensions): MagicMapper {
		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetchOne')->willReturn((string)$extensions);
		$statement = $this->createMock(originalClassName: IPreparedStatement::class);
		$statement->method('execute')->willReturn($result);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$platform = new MySQLPlatform();
		if ($postgres === true) {
			$platform = new PostgreSQLPlatform();
		}

		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($statement): IPreparedStatement {
				$this->prepared[] = $sql;
				return $statement;
			}
		);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper     = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => new NullLogger()] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()

	/**
	 * PostgreSQL with pg_trgm installed can build one, and asks the catalogue once.
	 *
	 * @return void
	 */
	public function testPostgresWithPgTrgmCanBuildATextSearchIndex(): void {
		self::assertTrue(condition: $this->mapper(postgres: true, extensions: 1)->canBuildTextSearchIndex());
		self::assertCount(expectedCount: 1, haystack: $this->prepared);
		self::assertStringContainsString(needle: "extname = 'pg_trgm'", haystack: $this->prepared[0]);
	}//end testPostgresWithPgTrgmCanBuildATextSearchIndex()

	/**
	 * PostgreSQL without pg_trgm cannot.
	 *
	 * @return void
	 */
	public function testPostgresWithoutPgTrgmCannot(): void {
		self::assertFalse(condition: $this->mapper(postgres: true, extensions: 0)->canBuildTextSearchIndex());
	}//end testPostgresWithoutPgTrgmCannot()

	/**
	 * MariaDB cannot, and never asks for a PostgreSQL extension.
	 *
	 * @return void
	 */
	public function testMariaDbCannotAndDoesNotAskTheCatalogue(): void {
		self::assertFalse(condition: $this->mapper(postgres: false, extensions: 1)->canBuildTextSearchIndex());
		self::assertSame(expected: [], actual: $this->prepared);
	}//end testMariaDbCannotAndDoesNotAskTheCatalogue()
}//end class

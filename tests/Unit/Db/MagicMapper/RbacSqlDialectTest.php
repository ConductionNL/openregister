<?php

/**
 * Tests for RbacSqlDialect: value and column quoting per platform, and the
 * fallback when the connection cannot be read.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect
 */
class RbacSqlDialectTest extends TestCase {

	private function dialect(?string $platform, ?LoggerInterface $logger = null): RbacSqlDialect {
		$container = $this->createMock(ContainerInterface::class);
		if ($platform === null) {
			$container->method('get')->willThrowException(new RuntimeException('no connection'));
		} else {
			$db = $this->createMock(IDBConnection::class);
			$db->method('getDatabasePlatform')->willReturn($platform === 'postgres' ? new PostgreSQLPlatform() : new MySQLPlatform());
			$db->method('getDatabaseProvider')->willReturn($platform === 'postgres' ? IDBConnection::PLATFORM_POSTGRES : IDBConnection::PLATFORM_MYSQL);
			$container->method('get')->willReturn($db);
		}

		return new RbacSqlDialect($container, $logger ?? $this->createMock(LoggerInterface::class));
	}//end dialect()

	public function testLiteralsAreNotQuoted(): void {
		$dialect = $this->dialect('mysql');

		$this->assertSame('NULL', $dialect->quote(null));
		$this->assertSame('TRUE', $dialect->quote(true));
		$this->assertSame('FALSE', $dialect->quote(false));
		$this->assertSame('7', $dialect->quote(7));
		$this->assertSame('1.5', $dialect->quote(1.5));
	}//end testLiteralsAreNotQuoted()

	public function testMysqlDoublesBackslashesAndQuotes(): void {
		$dialect = $this->dialect('mysql');

		$this->assertSame("'o''brien'", $dialect->quote("o'brien"));
		$this->assertSame("'a\\\\'' OR 1=1'", $dialect->quote("a\\' OR 1=1"));
		$this->assertSame("'7'", $dialect->quote('7'));
		$this->assertSame('`status`', $dialect->quotedColumn('status'));
		$this->assertFalse($dialect->isPostgres());
	}//end testMysqlDoublesBackslashesAndQuotes()

	public function testPostgresKeepsBackslashesAndUsesDoubleQuotedColumns(): void {
		$dialect = $this->dialect('postgres');

		$this->assertSame("'a\\'' OR 1=1'", $dialect->quote("a\\' OR 1=1"));
		$this->assertSame('"status"', $dialect->quotedColumn('status'));
		$this->assertTrue($dialect->isPostgres());
	}//end testPostgresKeepsBackslashesAndUsesDoubleQuotedColumns()

	public function testAnUnreadableConnectionFallsBackSafelyAndIsAskedOnce(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');
		$dialect = $this->dialect(null, $logger);

		// MariaDB column syntax, and backslashes doubled, which is safe everywhere.
		$this->assertSame('`status`', $dialect->quotedColumn('status'));
		$this->assertSame('`level`', $dialect->quotedColumn('level'));
		$this->assertSame("'a\\\\b'", $dialect->quote('a\\b'));
		$this->assertSame("'c\\\\d'", $dialect->quote('c\\d'));
	}//end testAnUnreadableConnectionFallsBackSafelyAndIsAskedOnce()
}//end class

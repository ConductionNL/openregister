<?php

/**
 * Every branch of the raw-SQL `match` compiler on both platforms: the exact
 * predicate it emits, and the impossible predicate where nothing compiles.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\RbacMatchSql;
use OCA\OpenRegister\Db\MagicMapper\RbacOperatorTerm;
use OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Pins the SQL RbacMatchSql emits for each kind of match value.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\RbacMatchSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacOperatorTerm
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 */
class RbacMatchSqlBranchesTest extends TestCase {

	/**
	 * Warnings the logger received.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A compiler on one platform.
	 *
	 * `$user.none` resolves to null, `$user.groups` to a list with a null in
	 * it, `$user.nothing` to an empty list, anything else to itself.
	 *
	 * @param bool $postgres        PostgreSQL rather than MySQL.
	 * @param bool $unknownMetadata Whether the matcher says the block names unknown metadata.
	 *
	 * @return RbacMatchSql
	 */
	private function compiler(bool $postgres = false, bool $unknownMetadata = false): RbacMatchSql {
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('namesUnknownMetadata')->willReturn($unknownMetadata);
		$matcher->method('resolveDynamicValue')->willReturnCallback(
			static fn (mixed $value): mixed => match ($value) {
				'$user.none' => null,
				'$user.groups' => ['editors', null, 'admins'],
				'$user.nothing' => [null],
				default => $value,
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string)$message;
			}
		);

		$connection = $this->createMock(IDBConnection::class);
		if ($postgres === true) {
			$connection->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
			$connection->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		} else {
			$connection->method('getDatabasePlatform')->willReturn($this->createMock(MySQLPlatform::class));
			$connection->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($connection);

		return new RbacMatchSql($matcher, new RbacSqlDialect($container, $logger));
	}//end compiler()

	/**
	 * No terms join to null, one term to itself, several in parentheses.
	 *
	 * @return void
	 */
	public function testJoined(): void {
		$this->assertNull(RbacMatchSql::joined([], ' AND '));
		$this->assertSame('a', RbacMatchSql::joined(['a'], ' AND '));
		$this->assertSame('(a OR b)', RbacMatchSql::joined(['a', 'b'], ' OR '));
	}//end testJoined()

	/**
	 * Strings, numbers and booleans are compared for equality; null and lists are not.
	 *
	 * @return void
	 */
	public function testIsEqualityValue(): void {
		$this->assertTrue(RbacMatchSql::isEqualityValue('x'));
		$this->assertTrue(RbacMatchSql::isEqualityValue(1.5));
		$this->assertTrue(RbacMatchSql::isEqualityValue(false));
		$this->assertFalse(RbacMatchSql::isEqualityValue(null));
		$this->assertFalse(RbacMatchSql::isEqualityValue(['x']));
	}//end testIsEqualityValue()

	/**
	 * A block naming unknown metadata is impossible.
	 *
	 * @return void
	 */
	public function testUnknownMetadataIsImpossible(): void {
		$this->assertSame('1 = 0', $this->compiler(unknownMetadata: true)->matchConditions(['_bogus' => 'x']));
	}//end testUnknownMetadataIsImpossible()

	/**
	 * Every property applies, prefixed and quoted; null is IS NULL and a non-condition is dropped.
	 *
	 * @return void
	 */
	public function testPropertiesAreAndedWithThePrefix(): void {
		$sql = $this->compiler()->matchConditions(
			['caseType' => "o'neil", 'closedAt' => null, 'ignored' => new stdClass()],
			'r.'
		);

		$this->assertSame("(r.`case_type` = 'o''neil' AND r.`closed_at` IS NULL)", $sql);
	}//end testPropertiesAreAndedWithThePrefix()

	/**
	 * A block of non-conditions only is null.
	 *
	 * @return void
	 */
	public function testABlockWithoutConditionsIsNull(): void {
		$this->assertNull($this->compiler()->matchConditions(['ignored' => new stdClass()]));
	}//end testABlockWithoutConditionsIsNull()

	/**
	 * A dynamic value that resolves to nothing is impossible, at the property and in a comparison.
	 *
	 * @return void
	 */
	public function testADynamicValueResolvingToNullIsImpossible(): void {
		$compiler = $this->compiler(postgres: true);

		$this->assertSame('1 = 0', $compiler->matchConditions(['owner' => '$user.none']));
		$this->assertSame('1 = 0', $compiler->operatorConditions('"owner"', ['$ne' => '$user.none']));
	}//end testADynamicValueResolvingToNullIsImpossible()

	/**
	 * A comparison against a resolved list denies and warns.
	 *
	 * @return void
	 */
	public function testAComparisonWithAListOperandIsImpossible(): void {
		$this->assertSame('1 = 0', $this->compiler()->operatorConditions('`group`', ['$eq' => '$user.groups']));
		$this->assertSame(
			['[MagicRbacHandler] Array operand for a scalar comparison operator — emitting an impossible predicate'],
			$this->warnings
		);
	}//end testAComparisonWithAListOperandIsImpossible()

	/**
	 * Comparisons use their symbol; literals are not quoted.
	 *
	 * @return void
	 */
	public function testComparisons(): void {
		$this->assertSame(
			"((`age` >= 18 AND `age` < 65.5) AND `ok` != TRUE AND `name` = 'a\\\\b')",
			$this->compiler()->matchConditions(['age' => ['$gte' => 18, '$lt' => 65.5], 'ok' => ['$ne' => true], 'name' => ['$eq' => 'a\\b']])
		);
	}//end testComparisons()

	/**
	 * An empty object is no condition; a numeric key and an unknown operator deny and warn.
	 *
	 * @return void
	 */
	public function testUnknownOperatorsAreImpossible(): void {
		$compiler = $this->compiler();

		$this->assertNull($compiler->operatorConditions('`age`', []));
		$this->assertSame('(1 = 0 AND 1 = 0)', $compiler->operatorConditions('`age`', [0 => 5, '$regex' => '^1']));
		$this->assertCount(2, $this->warnings);
		$this->assertSame('[MagicRbacHandler] Unknown operator or operand — emitting an impossible predicate', $this->warnings[0]);
	}//end testUnknownOperatorsAreImpossible()

	/**
	 * Lists: a map denies, an empty `$in` denies, an empty `$nin` requires a value.
	 *
	 * @return void
	 */
	public function testListOperators(): void {
		$compiler = $this->compiler();

		$this->assertSame("`s` IN ('a', 2)", $compiler->operatorConditions('`s`', ['$in' => ['a', 2]]));
		$this->assertSame("`s` NOT IN ('a')", $compiler->operatorConditions('`s`', ['$nin' => ['a']]));
		$this->assertSame('1 = 0', $compiler->operatorConditions('`s`', ['$in' => ['k' => 'a']]));
		$this->assertSame('1 = 0', $compiler->operatorConditions('`s`', ['$in' => []]));
		$this->assertSame('`s` IS NOT NULL', $compiler->operatorConditions('`s`', ['$nin' => []]));
	}//end testListOperators()

	/**
	 * `$exists: true` requires a value, any other operand requires none.
	 *
	 * @return void
	 */
	public function testExists(): void {
		$compiler = $this->compiler();

		$this->assertSame('`s` IS NOT NULL', $compiler->operatorConditions('`s`', ['$exists' => true]));
		$this->assertSame('`s` IS NULL', $compiler->operatorConditions('`s`', ['$exists' => 'no']));
	}//end testExists()

	/**
	 * `$contains` is no answer for another operator.
	 *
	 * @return void
	 */
	public function testContainsIgnoresOtherOperators(): void {
		$this->assertNull($this->compiler()->contains('`tags`', new RbacOperatorTerm('$in', ['a'])));
	}//end testContainsIgnoresOtherOperators()

	/**
	 * `$contains` on MySQL uses JSON_CONTAINS, one OR term per non-null list value.
	 *
	 * @return void
	 */
	public function testContainsOnMysql(): void {
		$compiler = $this->compiler();

		$this->assertSame(
			"JSON_CONTAINS(COALESCE(`tags`, '[]'), JSON_QUOTE('7'))",
			$compiler->contains('`tags`', new RbacOperatorTerm('$contains', 7))
		);
		$this->assertSame(
			"(JSON_CONTAINS(COALESCE(`tags`, '[]'), JSON_QUOTE('editors')) OR JSON_CONTAINS(COALESCE(`tags`, '[]'), JSON_QUOTE('admins')))",
			$compiler->contains('`tags`', new RbacOperatorTerm('$contains', '$user.groups'))
		);
	}//end testContainsOnMysql()

	/**
	 * `$contains` on PostgreSQL uses jsonb containment.
	 *
	 * @return void
	 */
	public function testContainsOnPostgres(): void {
		$this->assertSame(
			"COALESCE(\"tags\", '[]')::jsonb @> to_jsonb('a\\b'::text)",
			$this->compiler(postgres: true)->contains('"tags"', new RbacOperatorTerm('$contains', 'a\\b'))
		);
	}//end testContainsOnPostgres()

	/**
	 * `$contains` of a value that resolves to nothing, or to only nulls, denies.
	 *
	 * @return void
	 */
	public function testContainsOfNothingIsImpossible(): void {
		$compiler = $this->compiler();

		$this->assertSame('1 = 0', $compiler->contains('`tags`', new RbacOperatorTerm('$contains', '$user.none')));
		$this->assertSame('1 = 0', $compiler->contains('`tags`', new RbacOperatorTerm('$contains', '$user.nothing')));
		$this->assertSame('1 = 0', $compiler->operatorConditions('`tags`', ['$contains' => '$user.none']));
	}//end testContainsOfNothingIsImpossible()
}//end class

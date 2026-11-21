<?php

/**
 * Every branch of the QueryBuilder `match` compiler: the expressions it asks
 * the builder for, and the fail-closed predicate where nothing compiles.
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

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\RbacMatchSql;
use OCA\OpenRegister\Db\MagicMapper\RbacQueryMatch;
use OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\RuleColumns;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Pins the expressions RbacQueryMatch builds for each kind of match value.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\RbacQueryMatch
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacMatchSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacOperatorTerm
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 */
class RbacQueryMatchBranchesTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * Messages the logger received, as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * A compiler over a recording builder.
	 *
	 * `$user.none` resolves to null, `$user.groups` to a list, anything else to itself.
	 *
	 * @param bool $unknownMetadata Whether the matcher says the block names unknown metadata.
	 *
	 * @return RbacQueryMatch
	 */
	private function compiler(bool $unknownMetadata = false): RbacQueryMatch {
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('namesUnknownMetadata')->willReturn($unknownMetadata);
		$matcher->method('resolveDynamicValue')->willReturnCallback(
			static fn (mixed $value): mixed => match ($value) {
				'$user.none' => null,
				'$user.groups' => ['editors', 'admins'],
				default => $value,
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message) use ($level): void {
					$this->logged[] = $level . ': ' . $message;
				}
			);
		}

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
		$connection->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($connection);

		return new RbacQueryMatch($this->recordingBuilder(), new RbacMatchSql($matcher, new RbacSqlDialect($container, $logger)));
	}//end compiler()

	/**
	 * The impossible predicate binds 1 and 0 and compares them.
	 *
	 * @return void
	 */
	public function testImpossibleComparesOneWithZero(): void {
		$this->assertSame('eq(:p1, :p2, NULL)', $this->compiler()->impossible());
		$this->assertSame(
			['#1 param :p1 = 1, 2', '#1 param :p2 = 0, 2', '#1 expr.eq(:p1, :p2, NULL)'],
			$this->queryLog
		);
	}//end testImpossibleComparesOneWithZero()

	/**
	 * A cross-organisation branch whose columns the table has compiles the match.
	 *
	 * @return void
	 */
	public function testCrossOrganisationBranchCompilesAnAdmittedMatch(): void {
		$condition = $this->compiler()->crossOrganisationBranch(new RuleColumns(), ['_owner' => 'alice']);

		$this->assertSame('eq(t._owner, :p3, NULL)', $condition);
		$this->assertContains('#1 param :p3 = alice, 2', $this->queryLog);
	}//end testCrossOrganisationBranchCompilesAnAdmittedMatch()

	/**
	 * A cross-organisation branch naming a column the table lacks stays impossible.
	 *
	 * @return void
	 */
	public function testCrossOrganisationBranchDeniesAColumnTheTableLacks(): void {
		$condition = $this->compiler()->crossOrganisationBranch(new RuleColumns(), ['status' => 'open']);

		$this->assertSame('eq(:p1, :p2, NULL)', $condition);
		$this->assertSame(['#1 param :p1 = 1, 2', '#1 param :p2 = 0, 2', '#1 expr.eq(:p1, :p2, NULL)'], $this->queryLog);
	}//end testCrossOrganisationBranchDeniesAColumnTheTableLacks()

	/**
	 * A block naming unknown metadata is the impossible predicate, not its conditions.
	 *
	 * @return void
	 */
	public function testUnknownMetadataIsImpossible(): void {
		$this->assertSame('eq(:p1, :p2, NULL)', $this->compiler(unknownMetadata: true)->matchConditions(['_bogus' => 'x']));
		$this->assertNotContains('#1 param :p3 = x, 2', $this->queryLog);
	}//end testUnknownMetadataIsImpossible()

	/**
	 * A block in which no value is a condition yields null and logs it.
	 *
	 * @return void
	 */
	public function testABlockWithoutConditionsIsNullAndLogged(): void {
		$this->assertNull($this->compiler()->matchConditions(['status' => new stdClass()]));
		$this->assertSame(['debug: [MagicRbacHandler] No valid match conditions built'], $this->logged);
	}//end testABlockWithoutConditionsIsNullAndLogged()

	/**
	 * Several conditions are ANDed, a null value as IS NULL, camelCase as snake_case.
	 *
	 * @return void
	 */
	public function testSeveralConditionsAreAnded(): void {
		$this->compiler()->matchConditions(['caseType' => 'permit', 'closedAt' => null]);

		$this->assertSame(
			[
				'#1 param :p1 = permit, 2',
				'#1 expr.eq(t.case_type, :p1, NULL)',
				'#1 expr.isNull(t.closed_at)',
				'#1 expr.andX(eq(t.case_type, :p1, NULL), isNull(t.closed_at))',
			],
			$this->queryLog
		);
	}//end testSeveralConditionsAreAnded()

	/**
	 * A dynamic variable that resolves to nothing denies instead of being dropped.
	 *
	 * @return void
	 */
	public function testADynamicValueResolvingToNullIsImpossible(): void {
		$this->assertSame('eq(:p1, :p2, NULL)', $this->compiler()->propertyCondition('owner', '$user.none'));
		$this->assertNotContains('#1 expr.isNull(t.owner)', $this->queryLog);
	}//end testADynamicValueResolvingToNullIsImpossible()

	/**
	 * A boolean equality binds a real boolean.
	 *
	 * @return void
	 */
	public function testABooleanIsBoundAsBoolean(): void {
		$this->assertSame('eq(t.published, :p1, NULL)', $this->compiler()->propertyCondition('published', false));
		$this->assertSame('#1 param :p1 = false, boolean', $this->queryLog[0]);
	}//end testABooleanIsBoundAsBoolean()

	/**
	 * An empty operator object is no condition.
	 *
	 * @return void
	 */
	public function testAnEmptyOperatorObjectIsNull(): void {
		$this->assertNull($this->compiler()->operatorConditions('age', []));
		$this->assertSame([], $this->queryLog);
	}//end testAnEmptyOperatorObjectIsNull()

	/**
	 * A numeric operator key is no operator: it denies, without a warning.
	 *
	 * @return void
	 */
	public function testANumericOperatorKeyIsImpossible(): void {
		$this->assertSame('eq(:p1, :p2, NULL)', $this->compiler()->operatorConditions('age', [0 => 5]));
		$this->assertSame([], $this->logged);
	}//end testANumericOperatorKeyIsImpossible()

	/**
	 * A comparison against a resolved list denies and warns.
	 *
	 * @return void
	 */
	public function testAComparisonWithAListOperandIsImpossible(): void {
		$this->assertSame('eq(:p1, :p2, NULL)', $this->compiler()->operatorConditions('group', ['$eq' => '$user.groups']));
		$this->assertSame(
			['warning: [MagicRbacHandler] Array operand for a scalar comparison operator — emitting an impossible predicate'],
			$this->logged
		);
	}//end testAComparisonWithAListOperandIsImpossible()

	/**
	 * Comparisons use their own builder method, and every one applies.
	 *
	 * @return void
	 */
	public function testComparisonsMapToTheirBuilderMethods(): void {
		$this->compiler()->operatorConditions('age', ['$gt' => 17, '$lte' => 65, '$ne' => 30]);

		$this->assertSame(
			[
				'#1 param :p1 = 17, 2',
				'#1 expr.gt(t.age, :p1, NULL)',
				'#1 param :p2 = 65, 2',
				'#1 expr.lte(t.age, :p2, NULL)',
				'#1 param :p3 = 30, 2',
				'#1 expr.neq(t.age, :p3, NULL)',
				'#1 expr.andX(gt(t.age, :p1, NULL), lte(t.age, :p2, NULL), neq(t.age, :p3, NULL))',
			],
			$this->queryLog
		);
	}//end testComparisonsMapToTheirBuilderMethods()

	/**
	 * `$in` over an empty list matches nothing; `$nin` over one matches every present value.
	 *
	 * @return void
	 */
	public function testEmptyListsDenyOrRequireAValue(): void {
		$compiler = $this->compiler();

		$this->assertSame('eq(:p1, :p2, NULL)', $compiler->operatorConditions('status', ['$in' => []]));
		$this->assertSame('isNotNull(t.status)', $compiler->operatorConditions('status', ['$nin' => []]));
	}//end testEmptyListsDenyOrRequireAValue()

	/**
	 * A list operator over a map denies; over a list binds a string array.
	 *
	 * @return void
	 */
	public function testListOperatorsNeedAList(): void {
		$compiler = $this->compiler();

		$this->assertSame('eq(:p1, :p2, NULL)', $compiler->operatorConditions('status', ['$nin' => ['a' => 'open']]));
		$this->assertSame('notIn(t.status, :p3, NULL)', $compiler->operatorConditions('status', ['$nin' => ['open', 'closed']]));
		$this->assertCount(1, preg_grep('/^#1 param :p3 = \\["open","closed"\\], \\S/', $this->queryLog));
	}//end testListOperatorsNeedAList()

	/**
	 * `$contains` is the raw-SQL compiler's fragment, wrapped as a query function.
	 *
	 * @return void
	 */
	public function testContainsUsesTheRawSqlFragment(): void {
		$condition = $this->compiler()->operatorConditions('tags', ['$contains' => "o'neil"]);

		$this->assertSame("COALESCE(t.\"tags\", '[]')::jsonb @> to_jsonb('o''neil'::text)", (string)$condition);
	}//end testContainsUsesTheRawSqlFragment()

	/**
	 * `$exists: true` requires a value, any other operand requires none.
	 *
	 * @return void
	 */
	public function testExistsChecksForNull(): void {
		$compiler = $this->compiler();

		$this->assertSame('isNotNull(t.closed_at)', $compiler->operatorConditions('closed_at', ['$exists' => true]));
		$this->assertSame('isNull(t.closed_at)', $compiler->operatorConditions('closed_at', ['$exists' => false]));
	}//end testExistsChecksForNull()

	/**
	 * An unknown operator is logged and denies.
	 *
	 * @return void
	 */
	public function testAnUnknownOperatorIsLoggedAndDenies(): void {
		$this->assertSame('eq(:p1, :p2, NULL)', $this->compiler()->operatorConditions('age', ['$regex' => '^1']));
		$this->assertSame(['warning: [MagicRbacHandler] Unknown operator'], $this->logged);
	}//end testAnUnknownOperatorIsLoggedAndDenies()
}//end class

<?php

/**
 * An operator the list path cannot build denies, as the single-object read does (openregister#4089).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The list predicate and the single-object verdict agree on a malformed `match`.
 *
 * Before openregister#4089 the SQL builders returned null for an operator they
 * could not build and `buildMatchConditions()` dropped that property from the
 * AND. A two-property match with one unknown operator therefore granted the
 * list on the other property alone, while OperatorEvaluator denied the
 * single-object read. A property with two operators kept only the first on the
 * list, so `{"$gte": 18, "$lt": 65}` listed everyone over 18.
 */
class MagicRbacUnhandledOperatorTest extends TestCase {

	/**
	 * A handler whose caller is alice in the `members` group.
	 *
	 * @return MagicRbacHandler
	 */
	private function handler(): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['members']);

		// Tokens are not under test: every value resolves to itself.
		$conditionMatcher = $this->createMock(ConditionMatcher::class);
		$conditionMatcher->method('resolveDynamicValue')->willReturnArgument(0);

		return new MagicRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$conditionMatcher,
			$this->createMock(ContainerInterface::class),
			new NullLogger(),
			new RbacResolvers(
				objectScopeResolver: null,
				objectGrantResolver: null,
				denyResolver: new DenyResolver(new DenyEntryMatcher())
			)
		);
	}//end handler()

	/**
	 * The list predicate for a read rule with this match.
	 *
	 * @param array<string, mixed> $match The match clause.
	 *
	 * @return string
	 */
	private function listPredicateFor(array $match): string {
		$schema = new Schema();
		$schema->setId(681);
		$schema->setAuthorization(['read' => [['group' => 'members', 'match' => $match]]]);

		return $this->handler()->buildRbacPredicateForAlias(schema: $schema, alias: 't', action: 'read');
	}//end listPredicateFor()

	/**
	 * One unknown operator beside a valid property does not grant on the valid one alone.
	 *
	 * @return void
	 */
	public function testAnUnknownOperatorBesideAValidPropertyDenies(): void {
		$predicate = $this->listPredicateFor(['status' => 'open', 'project' => ['$lookup' => ['from' => 'project']]]);

		$this->assertStringContainsString("t.status = 'open'", $predicate);
		$this->assertStringContainsString('1 = 0', $predicate, 'The unknown operator must make the rule unsatisfiable on the list, as it is on find.');
	}//end testAnUnknownOperatorBesideAValidPropertyDenies()

	/**
	 * An `$in` whose operand is a map, not a list, denies instead of binding "Array".
	 *
	 * @return void
	 */
	public function testAnInOverAMapDenies(): void {
		$predicate = $this->listPredicateFor(['project' => ['$in' => ['$lookup' => ['from' => 'project']]]]);

		$this->assertStringNotContainsString('Array', $predicate);
		$this->assertStringContainsString('1 = 0', $predicate);
	}//end testAnInOverAMapDenies()

	/**
	 * Every operator of a property is applied, not only the first.
	 *
	 * @return void
	 */
	public function testEveryOperatorOfAPropertyIsApplied(): void {
		$predicate = $this->listPredicateFor(['age' => ['$gte' => 18, '$lt' => 65]]);

		$this->assertStringContainsString('t.age >= 18', $predicate);
		$this->assertStringContainsString('t.age < 65', $predicate);
	}//end testEveryOperatorOfAPropertyIsApplied()

	/**
	 * The single-object verdict denies a `$nin` over a map, as the list now does.
	 *
	 * @return void
	 */
	public function testTheSingleObjectVerdictDeniesANinOverAMap(): void {
		$evaluator = new OperatorEvaluator(new NullLogger());

		$this->assertFalse($evaluator->valueMatchesOperator('p-1', ['$nin' => ['$lookup' => ['from' => 'project']]]));
		$this->assertFalse($evaluator->valueMatchesOperator('p-1', ['$in' => ['$lookup' => ['from' => 'project']]]));
	}//end testTheSingleObjectVerdictDeniesANinOverAMap()
	/**
	 * The QueryBuilder path, as the list query builds it: every operator, and a deny for an unknown one.
	 *
	 * The expression builder records what it is asked for; the interfaces are
	 * Nextcloud's own, so no method here is one the real builder lacks.
	 *
	 * @return void
	 */
	public function testTheQueryBuilderPathAppliesEveryOperatorAndDeniesAnUnknownOne(): void {
		$calls = [];
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['gte', 'lt', 'eq', 'in', 'notIn', 'isNotNull'] as $method) {
			$expr->method($method)->willReturnCallback(
				static function (...$args) use (&$calls, $method): string {
					$sql = $method.'('.implode(',', array_map(static fn ($a): string => (string) $a, array_filter($args, static fn ($a): bool => $a !== null))).')';
					$calls[] = $sql;
					return $sql;
				}
			);
		}

		$composite = $this->createMock(ICompositeExpression::class);
		$expr->method('andX')->willReturnCallback(
			static function (...$parts) use (&$calls, $composite): ICompositeExpression {
				$calls[] = 'AND('.implode(' ; ', $parts).')';
				return $composite;
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): string => ':'.json_encode($value)
		);

		$method = new ReflectionMethod(MagicRbacHandler::class, 'buildOperatorCondition');
		$method->setAccessible(true);
		$handler = $this->handler();

		$this->assertSame($composite, $method->invoke($handler, $qb, 'age', ['$gte' => 18, '$lt' => 65]));
		$this->assertSame('AND(gte(t.age,:18) ; lt(t.age,:65))', end($calls));

		$this->assertSame($composite, $method->invoke($handler, $qb, 'status', ['$eq' => 'open', '$lookup' => ['from' => 'project']]));
		$this->assertSame('AND(eq(t.status,:"open") ; eq(:1,:0))', end($calls));

		$this->assertSame('eq(:1,:0)', $method->invoke($handler, $qb, 'project', ['$in' => ['from' => 'project']]));
		$this->assertSame('isNotNull(t.phase)', $method->invoke($handler, $qb, 'phase', ['$nin' => []]));
	}//end testTheQueryBuilderPathAppliesEveryOperatorAndDeniesAnUnknownOne()
}//end class

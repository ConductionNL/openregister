<?php

/**
 * A boolean in an RBAC match rule is bound as a boolean, not as a string.
 *
 * Found live: a non-admin reading a hermiq `agent` got HTTP 500,
 * `invalid input syntax for type boolean: ""`. The agent schema's read rule is
 * `{"isPrivate": false}`. The query-builder path bound that PHP `false` with the
 * default PARAM_STR, which PDO sends as the empty string, and PostgreSQL refuses
 * an empty string for a boolean column. `true` went out as '1', which pgsql
 * happens to accept, so only rules on `false` failed. The raw-SQL path
 * (buildRbacConditionsSql) already emitted TRUE/FALSE, so list and single-object
 * reads disagreed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
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

class MagicRbacBooleanBindingTest extends TestCase {

	/**
	 * A handler for a signed-in non-admin; dynamic tokens resolve to themselves.
	 *
	 * @return MagicRbacHandler
	 */
	private function handler(): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('instructor');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['authenticated']);

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
	 * A query builder that binds parameters the way PDO does against PostgreSQL.
	 *
	 * A PHP bool bound as anything but PARAM_BOOL is cast to string, so `false`
	 * reaches the server as '' and `true` as '1'. PostgreSQL rejects '' for a
	 * boolean column; the double raises that same error at bind time. A bool
	 * bound as PARAM_BOOL is rendered as the literal the server receives.
	 *
	 * @return IQueryBuilder
	 */
	private function pgsqlQueryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'gt', 'gte', 'lt', 'lte'] as $method) {
			$expr->method($method)->willReturnCallback(
				static fn ($left, $right): string => $method . '(' . $left . ',' . $right . ')'
			);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		// Returns a real IParameter, as the real builder does: a helper typed
		// to return anything else fails here exactly as it would in production.
		$parameter = static fn (string $sql): IParameter => new class($sql) implements IParameter {
			public function __construct(private readonly string $sql) {
			}

			public function __toString(): string {
				return $this->sql;
			}
		};
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value, $type = IQueryBuilder::PARAM_STR) use ($parameter): IParameter {
				if (is_bool($value) === true) {
					if ($type === IQueryBuilder::PARAM_BOOL) {
						return $parameter($value === true ? 'true' : 'false');
					}

					$sent = (string) $value;
					if ($sent === '') {
						throw new \RuntimeException('SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type boolean: ""');
					}

					return $parameter("'" . $sent . "'");
				}

				return $parameter(':' . json_encode($value));
			}
		);

		return $qb;
	}//end pgsqlQueryBuilder()

	/**
	 * The hermiq agent rule `{"isPrivate": false}` binds a real boolean.
	 *
	 * @return void
	 */
	public function testAMatchOnFalseBindsABoolean(): void {
		$method = new ReflectionMethod(MagicRbacHandler::class, 'buildPropertyCondition');
		$method->setAccessible(true);

		$this->assertSame('eq(t.is_private,false)', $method->invoke($this->handler(), $this->pgsqlQueryBuilder(), 'isPrivate', false));
		$this->assertSame('eq(t.is_private,true)', $method->invoke($this->handler(), $this->pgsqlQueryBuilder(), 'isPrivate', true));
	}//end testAMatchOnFalseBindsABoolean()

	/**
	 * A comparison operator on a boolean binds a real boolean too.
	 *
	 * @return void
	 */
	public function testAComparisonOperatorOnABooleanBindsABoolean(): void {
		$method = new ReflectionMethod(MagicRbacHandler::class, 'buildOperatorCondition');
		$method->setAccessible(true);

		$this->assertSame('eq(t.is_private,false)', $method->invoke($this->handler(), $this->pgsqlQueryBuilder(), 'is_private', ['$eq' => false]));
		$this->assertSame('neq(t.is_private,true)', $method->invoke($this->handler(), $this->pgsqlQueryBuilder(), 'is_private', ['$ne' => true]));
	}//end testAComparisonOperatorOnABooleanBindsABoolean()

	/**
	 * CONTROL: strings and numbers keep their ordinary binding.
	 *
	 * @return void
	 */
	public function testStringsAndNumbersAreBoundAsBefore(): void {
		$method = new ReflectionMethod(MagicRbacHandler::class, 'buildPropertyCondition');
		$method->setAccessible(true);

		$this->assertSame('eq(t.status,:"open")', $method->invoke($this->handler(), $this->pgsqlQueryBuilder(), 'status', 'open'));
		$this->assertSame('eq(t.level,:3)', $method->invoke($this->handler(), $this->pgsqlQueryBuilder(), 'level', 3));
	}//end testStringsAndNumbersAreBoundAsBefore()

	/**
	 * CONTROL: the raw-SQL path the list uses already agrees, so both paths now match.
	 *
	 * @return void
	 */
	public function testTheRawSqlPathAlreadyEmitsABooleanLiteral(): void {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setAuthorization(['read' => [['group' => 'authenticated', 'match' => ['isPrivate' => false]]]]);

		$predicate = $this->handler()->buildRbacPredicateForAlias(schema: $schema, alias: 't', action: 'read');

		$this->assertStringContainsString('is_private = FALSE', $predicate);
	}//end testTheRawSqlPathAlreadyEmitsABooleanLiteral()
}//end class

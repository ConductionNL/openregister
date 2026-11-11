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

use OCA\OpenRegister\Db\MagicMapper\AliasedRows;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacMatchSql;
use OCA\OpenRegister\Db\MagicMapper\RbacQueryMatch;
use OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect;
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
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;

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
			identity: new MagicMapperIdentity(
				userSession: $userSession,
				groupManager: $groupManager,
				userManager: $this->createMock(IUserManager::class)
			),
			settings: new MagicRbacSettings(
				appConfig: $this->createMock(IAppConfig::class)
			),
			support: new MagicRbacSupport(
				conditionMatcher: $conditionMatcher,
				container: $this->createMock(ContainerInterface::class),
				logger: new NullLogger()
			),
			resolvers: new RbacResolvers(
				objectScopeResolver: null,
				objectGrantResolver: null,
				denyResolver: new DenyResolver(new DenyEntryMatcher())
			)
		);
	}//end handler()

	/**
	 * The QueryBuilder `match` compiler the list query uses; dynamic tokens resolve to themselves.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return RbacQueryMatch
	 */
	private function queryMatch(IQueryBuilder $qb): RbacQueryMatch {
		$conditionMatcher = $this->createMock(ConditionMatcher::class);
		$conditionMatcher->method('resolveDynamicValue')->willReturnArgument(0);

		return new RbacQueryMatch($qb, new RbacMatchSql($conditionMatcher, new RbacSqlDialect($this->createMock(ContainerInterface::class), new NullLogger())));
	}//end queryMatch()

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
		$this->assertSame('eq(t.is_private,false)', $this->queryMatch($this->pgsqlQueryBuilder())->propertyCondition('isPrivate', false));
		$this->assertSame('eq(t.is_private,true)', $this->queryMatch($this->pgsqlQueryBuilder())->propertyCondition('isPrivate', true));
	}//end testAMatchOnFalseBindsABoolean()

	/**
	 * A comparison operator on a boolean binds a real boolean too.
	 *
	 * @return void
	 */
	public function testAComparisonOperatorOnABooleanBindsABoolean(): void {
		$this->assertSame('eq(t.is_private,false)', $this->queryMatch($this->pgsqlQueryBuilder())->operatorConditions('is_private', ['$eq' => false]));
		$this->assertSame('neq(t.is_private,true)', $this->queryMatch($this->pgsqlQueryBuilder())->operatorConditions('is_private', ['$ne' => true]));
	}//end testAComparisonOperatorOnABooleanBindsABoolean()

	/**
	 * CONTROL: strings and numbers keep their ordinary binding.
	 *
	 * @return void
	 */
	public function testStringsAndNumbersAreBoundAsBefore(): void {
		$this->assertSame('eq(t.status,:"open")', $this->queryMatch($this->pgsqlQueryBuilder())->propertyCondition('status', 'open'));
		$this->assertSame('eq(t.level,:3)', $this->queryMatch($this->pgsqlQueryBuilder())->propertyCondition('level', 3));
	}//end testStringsAndNumbersAreBoundAsBefore()

	/**
	 * CONTROL: the raw-SQL path the list uses already agrees, so both paths now match.
	 *
	 * @return void
	 */
	public function testTheRawSqlPathAlreadyEmitsABooleanLiteral(): void {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setProperties(['isPrivate' => ['type' => 'boolean']]);
		$schema->setAuthorization(['read' => [['group' => 'authenticated', 'match' => ['isPrivate' => false]]]]);

		$predicate = $this->handler()->buildRbacPredicateForAlias(schema: $schema, rows: new AliasedRows(alias: 't', action: 'read'));

		$this->assertStringContainsString('`is_private` = FALSE', $predicate);
	}//end testTheRawSqlPathAlreadyEmitsABooleanLiteral()
}//end class

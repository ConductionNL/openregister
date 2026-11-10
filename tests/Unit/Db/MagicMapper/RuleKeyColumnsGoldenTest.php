<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\AliasedRows;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacResolvers;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Pins what a legitimate rule key compiles to on every RBAC emitter.
 *
 * The fixture was recorded before keys were resolved to quoted columns. The
 * column's identifier quotes are the one permitted difference: the comparison
 * removes them, and every other byte has to match.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacResolvers
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFilterCall
 */
class RuleKeyColumnsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/rule-key-columns-golden.json';

	/**
	 * The legitimate keys, each with a column the magic table has.
	 *
	 * @return array<int, string> The keys.
	 */
	private function keys(): array {
		return [
			'status',
			'firstName',
			'isPrivate',
			'dateOfBirth',
			'verified_actor_id',
			'_owner',
			'_organisation',
			'_uuid',
			'_id',
			'_created',
			'_schema',
			'_schemaVersion',
			'@self.owner',
			'@self.organisation',
			'@self.id',
		];
	}//end keys()

	/**
	 * Every value shape a condition can take.
	 *
	 * @return array<string, mixed> The values by name.
	 */
	private function values(): array {
		return [
			'string'      => 'open',
			'quoted'      => "o'brien",
			'token'       => '$userId',
			'int'         => 5,
			'true'        => true,
			'false'       => false,
			'null'        => null,
			'eq'          => ['$eq' => 'a'],
			'ne'          => ['$ne' => 'a'],
			'range'       => ['$gte' => 18, '$lt' => 65],
			'in'          => ['$in' => ['a', 'b']],
			'nin'         => ['$nin' => ['a', 'b']],
			'inEmpty'     => ['$in' => []],
			'ninEmpty'    => ['$nin' => []],
			'exists'      => ['$exists' => true],
			'notExists'   => ['$exists' => false],
			'contains'    => ['$contains' => 'a'],
			'containsAny' => ['$contains' => ['a', 'b']],
			'unknownOp'   => ['$regex' => 'a'],
		];
	}//end values()

	/**
	 * The handler for a signed-in non-admin in `editors`.
	 *
	 * @param string $denyMode The deny enforcement mode.
	 *
	 * @return MagicRbacHandler The handler.
	 */
	private function handler(string $denyMode = DenyEnforcementMode::MODE_STAGING): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['editors']);
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('resolveDynamicValue')->willReturnArgument(0);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(static fn (string $app, string $key, bool $default = false): bool => $default);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === DenyEnforcementMode::CONFIG_KEY ? $denyMode : $default
		);

		return new MagicRbacHandler(
			$session,
			$groups,
			$this->createMock(IUserManager::class),
			$config,
			$matcher,
			$this->createMock(ContainerInterface::class),
			new NullLogger(),
			new RbacResolvers(objectScopeResolver: null, objectGrantResolver: null, denyResolver: new DenyResolver(new DenyEntryMatcher())),
			new DenyEnforcementMode($config, new NullLogger())
		);
	}//end handler()

	/**
	 * A schema with the fixture's properties and this authorization.
	 *
	 * @param array $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(
			[
				'status'            => ['type' => 'string'],
				'firstName'         => ['type' => 'string'],
				'isPrivate'         => ['type' => 'boolean'],
				'dateOfBirth'       => ['type' => 'string'],
				'verified_actor_id' => ['type' => 'string'],
			]
		);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * A query builder whose expressions read back as text.
	 *
	 * @param array<int, string> $wheres Receives what the code passes to andWhere.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(array &$wheres = []): IQueryBuilder {
		$composite = static fn (string $sql): ICompositeExpression => new class($sql) implements ICompositeExpression {
			public function __construct(private readonly string $sql) {
			}

			public function addMultiple(array $parts = []): ICompositeExpression {
				return $this;
			}

			public function add($part): ICompositeExpression {
				return $this;
			}

			public function count(): int {
				return 1;
			}

			public function getType(): string {
				return 'X';
			}

			public function __toString(): string {
				return $this->sql;
			}
		};

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'notIn'] as $method) {
			$expr->method($method)->willReturnCallback(static fn ($left, $right): string => $method.'('.$left.','.$right.')');
		}

		foreach (['isNull', 'isNotNull'] as $method) {
			$expr->method($method)->willReturnCallback(static fn ($column): string => $method.'('.$column.')');
		}

		$expr->method('andX')->willReturnCallback(static fn (...$parts): ICompositeExpression => $composite('and('.implode(',', array_map('strval', $parts)).')'));
		$expr->method('orX')->willReturnCallback(static fn (...$parts): ICompositeExpression => $composite('or('.implode(',', array_map('strval', $parts)).')'));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn ($value): IParameter => new class((string)json_encode($value)) implements IParameter {
				public function __construct(private readonly string $sql) {
				}

				public function __toString(): string {
					return $this->sql;
				}
			}
		);
		$qb->method('createFunction')->willReturnCallback(
			static fn ($sql): IQueryFunction => new class((string)$sql) implements IQueryFunction {
				public function __construct(private readonly string $sql) {
				}

				public function __toString(): string {
					return $this->sql;
				}
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			static function (...$parts) use (&$wheres, $qb): IQueryBuilder {
				foreach ($parts as $part) {
					$wheres[] = (string)$part;
				}

				return $qb;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * What every grant emitter makes of one match clause.
	 *
	 * @param array $match The clause.
	 *
	 * @return array<string, mixed> The output per emitter.
	 */
	private function emitted(array $match): array {
		$handler = $this->handler();
		$allow = $this->schema(['read' => [['group' => 'editors', 'match' => $match]]]);
		$cross = $this->schema(['read' => [['group' => 'editors', 'crossOrganisation' => true, 'match' => $match]]]);

		$wheres = [];
		$handler->applyRbacFilters($this->queryBuilder($wheres), $allow);

		return [
			'union'    => $handler->buildRbacConditionsSql(schema: $allow, action: 'read')['conditions'],
			'aliased'  => $handler->buildRbacPredicateForAlias(schema: $allow, rows: new AliasedRows(alias: 'r0')),
			'row'      => $handler->buildRbacRowPredicateSql(schema: $allow),
			'crossSql' => $handler->getCrossOrganisationBranchSql(schema: $cross),
			'crossQb'  => (string)$handler->getCrossOrganisationBranch($this->queryBuilder(), $cross),
			'qb'       => $wheres,
		];
	}//end emitted()

	/**
	 * The conditional deny term one match clause adds to a list, without the terms around it.
	 *
	 * @param array $match The clause.
	 *
	 * @return array<string, mixed> The term per emitter.
	 */
	private function denied(array $match): array {
		$handler = $this->handler(denyMode: DenyEnforcementMode::MODE_ENFORCING);
		$deny = $this->schema(['read' => ['editors'], 'deny' => ['read' => [['group' => 'editors', 'match' => $match]]]]);

		$wheres = [];
		$handler->applyRbacFilters($this->queryBuilder($wheres), $deny);

		return [
			'union'   => $this->denyTerm(sql: (string)($handler->buildRbacConditionsSql(schema: $deny, action: 'read')['conditions'][0] ?? '')),
			'aliased' => $this->denyTerm(sql: $handler->buildRbacPredicateForAlias(schema: $deny, rows: new AliasedRows(alias: 'r0'))),
			'qb'      => $this->denyTerm(sql: (string)($wheres[0] ?? '')),
		];
	}//end denied()

	/**
	 * The last `(NOT (...))` of a predicate, which is the conditional deny.
	 *
	 * @param string $sql The predicate.
	 *
	 * @return string The term, or the predicate when it has none.
	 */
	private function denyTerm(string $sql): string {
		$start = strrpos($sql, '(NOT (');
		if ($start === false) {
			return $sql;
		}

		return substr($sql, $start);
	}//end denyTerm()

	/**
	 * The whole output of the emitters over keys and value shapes.
	 *
	 * @return array<string, mixed> The output by case.
	 */
	private function allCases(): array {
		$cases = [];
		foreach ($this->keys() as $key) {
			foreach ($this->values() as $name => $value) {
				$cases[$key.'/'.$name] = $this->emitted([$key => $value]);
				$cases[$key.'/'.$name.'/deny'] = $this->denied([$key => $value]);
			}
		}

		$cases['two keys'] = $this->emitted(['status' => 'open', '_owner' => '$userId']);
		$cases['two keys/deny'] = $this->denied(['status' => 'open', '_owner' => '$userId']);

		return $cases;
	}//end allCases()

	/**
	 * Legitimate keys compile as recorded, apart from the column's identifier quotes.
	 *
	 * @return void
	 */
	public function testLegitimateKeysCompileAsRecorded(): void {
		$cases = $this->allCases();
		if (getenv('RECORD_RULE_KEY_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertCount(count($recorded), $cases);
		foreach ($recorded as $name => $output) {
			$this->assertSame($output, $this->withoutIdentifierQuotes($cases[$name]), $name);
		}
	}//end testLegitimateKeysCompileAsRecorded()

	/**
	 * Remove the identifier quotes this platform puts around a column.
	 *
	 * @param mixed $output The emitter output.
	 *
	 * @return mixed The output without them.
	 */
	private function withoutIdentifierQuotes(mixed $output): mixed {
		if (is_array($output) === true) {
			return array_map(fn (mixed $part): mixed => $this->withoutIdentifierQuotes($part), $output);
		}

		if (is_string($output) === true) {
			return str_replace('`', '', $output);
		}

		return $output;
	}//end withoutIdentifierQuotes()
}//end class

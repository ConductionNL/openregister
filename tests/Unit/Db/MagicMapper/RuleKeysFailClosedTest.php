<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\AliasedRows;
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacAccessCall;
use OCA\OpenRegister\Db\MagicMapper\RbacFilterCall;
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
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A rule key reaches SQL only as a known column; any other key admits no row.
 *
 * The condition matcher is a double that refuses nothing, so these cases hold
 * on the column check alone.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @covers \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacResolvers
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFilterCall
 */
class RuleKeysFailClosedTest extends TestCase {

	/**
	 * Keys that must not reach SQL.
	 *
	 * @return array<string, array{0: string}> The keys.
	 */
	public static function refusedKeys(): array {
		return [
			'closes the bracket'   => ['x) or (1=1'],
			'subquery'             => ["1=1) or (select 1 from oc_users where uid='admin' and password like 'a%')>0 or (1"],
			'statement'            => ['a; drop table oc_users'],
			'comment'              => ['status -- '],
			'backtick'             => ['status` = 1 or `status'],
			'double quote'         => ['status" = 1 or "status'],
			'dotted'               => ['other.status'],
			'undeclared property'  => ['secret'],
			'metadata injection'   => ['_owner) or (1=1'],
			'self injection'       => ['@self.owner) or (1=1'],
			'no such metadata'     => ['_nothing'],
			'no such self field'   => ['@self.nothing'],
			'empty'                => [''],
			'numeric'              => ['0'],
			'column of the config' => ['required'],
		];
	}//end refusedKeys()

	/**
	 * A handler for a signed-in non-admin in `editors`.
	 *
	 * @param string                  $denyMode  The deny enforcement mode.
	 * @param ContainerInterface|null $container The container the platform is read from.
	 *
	 * @return MagicRbacHandler The handler.
	 */
	private function handler(string $denyMode = DenyEnforcementMode::MODE_STAGING, ?ContainerInterface $container = null): MagicRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['editors']);
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('resolveDynamicValue')->willReturnArgument(0);
		$matcher->method('namesUnknownMetadata')->willReturn(false);
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
			($container ?? $this->createMock(ContainerInterface::class)),
			new NullLogger(),
			new RbacResolvers(objectScopeResolver: null, objectGrantResolver: null, denyResolver: new DenyResolver(new DenyEntryMatcher())),
			new DenyEnforcementMode($config, new NullLogger())
		);
	}//end handler()

	/**
	 * A schema declaring `status`, `order` and whatever else is asked.
	 *
	 * @param array                $authorization The block.
	 * @param array<string, mixed> $properties    Further properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $authorization, array $properties = []): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(['status' => ['type' => 'string'], 'order' => ['type' => 'string']] + $properties);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * An allow rule on this clause.
	 *
	 * @param array $match The clause.
	 *
	 * @return array The block.
	 */
	private function allow(array $match): array {
		return ['read' => [['group' => 'editors', 'match' => $match]]];
	}//end allow()

	/**
	 * A cross-organisation allow rule on this clause.
	 *
	 * @param array $match The clause.
	 *
	 * @return array The block.
	 */
	private function cross(array $match): array {
		return ['read' => [['group' => 'editors', 'crossOrganisation' => true, 'match' => $match]]];
	}//end cross()

	/**
	 * A grant with a conditional deny on this clause.
	 *
	 * @param array $match The clause.
	 *
	 * @return array The block.
	 */
	private function deny(array $match): array {
		return ['read' => ['editors'], 'deny' => ['read' => [['group' => 'editors', 'match' => $match]]]];
	}//end deny()

	/**
	 * A query builder whose expressions read back as text.
	 *
	 * @param array<int, string> $wheres Receives what the code passes to andWhere.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(array &$wheres = []): IQueryBuilder {
		$text = static fn (string $sql): ICompositeExpression => new class($sql) implements ICompositeExpression {
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
		$expr->method('eq')->willReturnCallback(static fn ($left, $right): string => 'eq('.$left.','.$right.')');
		$expr->method('andX')->willReturnCallback(static fn (...$parts): ICompositeExpression => $text('and('.implode(',', array_map('strval', $parts)).')'));
		$expr->method('orX')->willReturnCallback(static fn (...$parts): ICompositeExpression => $text('or('.implode(',', array_map('strval', $parts)).')'));

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
	 * The raw emitters compile the rule to exactly what a known key's rule is, with `1 = 0` for its condition.
	 *
	 * @param string $key The refused key.
	 *
	 * @return void
	 */
	#[DataProvider('refusedKeys')]
	public function testAnAllowCompilesToTheFailClosedPredicate(string $key): void {
		$handler = $this->handler();
		$known = $this->schema($this->allow(['status' => 'open']));
		$refused = $this->schema($this->allow([$key => 'open']));
		$closed = static fn (string $sql): string => str_replace(["r0.`status` = 'open'", "`status` = 'open'"], '1 = 0', $sql);

		$this->assertSame(
			array_map($closed, $handler->buildRbacConditionsSql((new RbacAccessCall(schema: $known))->forAction('read'))['conditions']),
			$handler->buildRbacConditionsSql((new RbacAccessCall(schema: $refused))->forAction('read'))['conditions']
		);
		$this->assertSame(
			$closed($handler->buildRbacPredicateForAlias(schema: $known, rows: new AliasedRows(alias: 'r0'))),
			$handler->buildRbacPredicateForAlias(schema: $refused, rows: new AliasedRows(alias: 'r0'))
		);
		$this->assertSame(
			$closed((string)$handler->buildRbacRowPredicateSql((new RbacAccessCall(schema: $known)))),
			$handler->buildRbacRowPredicateSql((new RbacAccessCall(schema: $refused)))
		);
	}//end testAnAllowCompilesToTheFailClosedPredicate()

	/**
	 * CONTROL: a known key's rule compiles with the alias of its table, on both list paths.
	 *
	 * @return void
	 */
	public function testAKnownKeyCompilesWithItsAlias(): void {
		$handler = $this->handler();
		$schema = $this->schema($this->allow(['status' => 'open']));
		$this->assertStringContainsString("r0.`status` = 'open'", $handler->buildRbacPredicateForAlias(schema: $schema, rows: new AliasedRows(alias: 'r0')));

		$wheres = [];
		$handler->applyRbacFilters((new RbacFilterCall(qb: $this->queryBuilder($wheres), schema: $schema)));
		$this->assertStringContainsString('eq(t.status,"open")', $wheres[0]);
	}//end testAKnownKeyCompilesWithItsAlias()

	/**
	 * One refused key closes the whole clause, not only its own condition.
	 *
	 * @return void
	 */
	public function testARefusedKeyBesideAKnownKeyClosesTheClause(): void {
		$sql = implode(' OR ', $this->handler()->buildRbacConditionsSql((new RbacAccessCall(schema: $this->schema($this->allow(['status' => 'open', 'x) or (1=1' => 'a']))))->forAction('read'))['conditions']);

		$this->assertStringContainsString('1 = 0', $sql);
		$this->assertStringNotContainsString('status', str_replace('_status', '', $sql));
	}//end testARefusedKeyBesideAKnownKeyClosesTheClause()

	/**
	 * The cross-organisation branch on both list paths.
	 *
	 * @param string $key The refused key.
	 *
	 * @return void
	 */
	#[DataProvider('refusedKeys')]
	public function testTheCrossOrganisationBranchIsClosed(string $key): void {
		$handler = $this->handler();
		$schema = $this->schema($this->cross([$key => 'a']));

		$this->assertSame('(1 = 0)', $handler->getCrossOrganisationBranchSql(schema: $schema));
		$this->assertSame('or(eq(1,0))', (string)$handler->getCrossOrganisationBranch($this->queryBuilder(), $schema));
	}//end testTheCrossOrganisationBranchIsClosed()

	/**
	 * The QueryBuilder list path admits no row on such a key.
	 *
	 * @param string $key The refused key.
	 *
	 * @return void
	 */
	#[DataProvider('refusedKeys')]
	public function testTheQueryBuilderListIsClosed(string $key): void {
		$wheres = [];
		$this->handler()->applyRbacFilters((new RbacFilterCall(qb: $this->queryBuilder($wheres), schema: $this->schema($this->allow([$key => 'a'])))));

		$this->assertCount(1, $wheres);
		$this->assertStringContainsString(',eq(1,0))', $wheres[0]);
		if ($key !== '') {
			$this->assertStringNotContainsString($key.',', $wheres[0]);
		}
	}//end testTheQueryBuilderListIsClosed()

	/**
	 * A deny on such a key empties the list: `NOT (1 = 0)` would admit every row.
	 *
	 * @param string $key The refused key.
	 *
	 * @return void
	 */
	#[DataProvider('refusedKeys')]
	public function testADenyEmptiesTheList(string $key): void {
		$handler = $this->handler(denyMode: DenyEnforcementMode::MODE_ENFORCING);
		$schema = $this->schema($this->deny([$key => 'a']));

		$this->assertSame([], $handler->buildRbacConditionsSql((new RbacAccessCall(schema: $schema))->forAction('read'))['conditions']);
		$this->assertSame('FALSE', $handler->buildRbacPredicateForAlias(schema: $schema, rows: new AliasedRows(alias: 'r0')));

		$wheres = [];
		$handler->applyRbacFilters((new RbacFilterCall(qb: $this->queryBuilder($wheres), schema: $schema)));
		$this->assertSame(['eq(1,0)'], $wheres);
	}//end testADenyEmptiesTheList()

	/**
	 * CONTROL: a deny on a known key adds its term and leaves the list open.
	 *
	 * @return void
	 */
	public function testADenyOnAKnownKeyKeepsTheList(): void {
		$handler = $this->handler(denyMode: DenyEnforcementMode::MODE_ENFORCING);
		$sql = $handler->buildRbacPredicateForAlias(schema: $this->schema($this->deny(['status' => 'a'])), rows: new AliasedRows(alias: 'r0'));

		$this->assertStringContainsString("(NOT (r0.`status` = 'a'))", $sql);

		$wheres = [];
		$handler->applyRbacFilters((new RbacFilterCall(qb: $this->queryBuilder($wheres), schema: $this->schema($this->deny(['status' => 'a'])))));
		$this->assertStringContainsString("(NOT (t.`status` = 'a'))", $wheres[0]);
	}//end testADenyOnAKnownKeyKeepsTheList()

	/**
	 * A declared property with a hostile name reaches SQL only as its sanitised, quoted column.
	 *
	 * @return void
	 */
	public function testADeclaredHostileNameIsReducedToAColumn(): void {
		$key = 'x) or (1=1';
		$handler = $this->handler();
		$schema = $this->schema($this->cross([$key => 'a']), [$key => ['type' => 'string']]);

		$this->assertSame("(`x_or_1_1` = 'a')", $handler->getCrossOrganisationBranchSql(schema: $schema));
		$this->assertSame('or(eq(t.x_or_1_1,"a"))', (string)$handler->getCrossOrganisationBranch($this->queryBuilder(), $schema));
	}//end testADeclaredHostileNameIsReducedToAColumn()

	/**
	 * A declared hostile name cannot become a metadata column.
	 *
	 * @return void
	 */
	public function testADeclaredNameCannotRenderToAMetadataColumn(): void {
		$schema = $this->schema($this->cross(['-owner' => 'alice']), ['-owner' => ['type' => 'string']]);

		$this->assertSame('(1 = 0)', $this->handler()->getCrossOrganisationBranchSql(schema: $schema));
	}//end testADeclaredNameCannotRenderToAMetadataColumn()

	/**
	 * The column is quoted for the platform, also where its name is a reserved word.
	 *
	 * @return void
	 */
	public function testTheColumnIsQuotedForThePlatform(): void {
		$schema = $this->schema($this->cross(['order' => 'a']));
		$this->assertSame("(`order` = 'a')", $this->handler()->getCrossOrganisationBranchSql(schema: $schema));

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($connection);

		$this->assertSame(
			"(\"order\" = 'a')",
			$this->handler(container: $container)->getCrossOrganisationBranchSql(schema: $schema)
		);
	}//end testTheColumnIsQuotedForThePlatform()

	/**
	 * Values that try to end their string literal under MySQL's backslash rule.
	 *
	 * @return array<string, array{0: string}> The values.
	 */
	public static function hostileValues(): array {
		return [
			'backslash before a quote' => ["\\' OR 1=1 -- "],
			'two backslashes'          => ["\\\\' OR 1=1 -- "],
			'trailing backslash'       => ['tail\\'],
		];
	}//end hostileValues()

	/**
	 * A handler connected to the given database provider.
	 *
	 * @param string $provider The provider the connection answers.
	 *
	 * @return MagicRbacHandler The handler.
	 */
	private function handlerOn(string $provider): MagicRbacHandler {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getDatabaseProvider')->willReturn($provider);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($connection);

		return $this->handler(container: $container);
	}//end handlerOn()

	/**
	 * The part of a predicate that sits outside its string literals, read by MySQL's rules.
	 *
	 * @param string $sql The predicate.
	 *
	 * @return string The predicate with each literal replaced by `?`.
	 */
	private function outsideMysqlLiterals(string $sql): string {
		$outside = '';
		$inside = false;
		for ($i = 0, $end = strlen($sql); $i < $end; $i++) {
			$char = $sql[$i];
			if ($inside === false) {
				$outside .= $char === "'" ? '?' : $char;
				$inside = $char === "'";
				continue;
			}

			if ($char === '\\' || ($char === "'" && ($sql[$i + 1] ?? '') === "'")) {
				$i++;
				continue;
			}

			$inside = $char !== "'";
		}

		return $outside;
	}//end outsideMysqlLiterals()

	/**
	 * On MySQL a rule value stays inside its literal, on every operator that quotes one.
	 *
	 * @param string $value The hostile value.
	 *
	 * @return void
	 */
	#[DataProvider('hostileValues')]
	public function testAValueStaysInsideItsLiteralOnMysql(string $value): void {
		$handler = $this->handlerOn(IDBConnection::PLATFORM_MYSQL);

		foreach ([$value, ['$ne' => $value], ['$in' => [$value, 'b']], ['$contains' => $value]] as $condition) {
			$safe = $this->schema($this->cross(['status' => (is_array($condition) === true ? array_map(static fn ($v) => is_array($v) === true ? ['x', 'b'] : 'x', $condition) : 'x')]));
			$hostile = $this->schema($this->cross(['status' => $condition]));

			$this->assertSame(
				$this->outsideMysqlLiterals($handler->getCrossOrganisationBranchSql(schema: $safe)),
				$this->outsideMysqlLiterals($handler->getCrossOrganisationBranchSql(schema: $hostile))
			);
		}
	}//end testAValueStaysInsideItsLiteralOnMysql()

	/**
	 * A backslash is an ordinary character on PostgreSQL and SQLite, so it stays single there.
	 *
	 * @return void
	 */
	public function testABackslashIsDoubledOnlyOnMysql(): void {
		$schema = $this->schema($this->cross(['status' => 'a\\b\'c']));

		$this->assertSame(
			"(`status` = 'a\\\\b''c')",
			$this->handlerOn(IDBConnection::PLATFORM_MYSQL)->getCrossOrganisationBranchSql(schema: $schema)
		);
		$this->assertSame(
			"(`status` = 'a\\b''c')",
			$this->handlerOn(IDBConnection::PLATFORM_SQLITE)->getCrossOrganisationBranchSql(schema: $schema)
		);
	}//end testABackslashIsDoubledOnlyOnMysql()

	/**
	 * Without an answer from the connection the backslash is doubled, which is safe on every engine.
	 *
	 * @return void
	 */
	public function testAnUnreadableProviderStillDoublesTheBackslash(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no connection'));

		$this->assertStringContainsString(
			"'a\\\\b'",
			$this->handler(container: $container)->getCrossOrganisationBranchSql(schema: $this->schema($this->cross(['status' => 'a\\b'])))
		);
	}//end testAnUnreadableProviderStillDoublesTheBackslash()

	/**
	 * `$contains` is the one QueryBuilder condition written as raw SQL, so it quotes the column too.
	 *
	 * @return void
	 */
	public function testTheQueryBuilderContainsFragmentQuotesTheColumn(): void {
		$schema = $this->schema($this->cross(['status' => ['$contains' => 'a']]));

		$this->assertSame(
			"or(JSON_CONTAINS(COALESCE(t.`status`, '[]'), JSON_QUOTE('a')))",
			(string)$this->handler()->getCrossOrganisationBranch($this->queryBuilder(), $schema)
		);
	}//end testTheQueryBuilderContainsFragmentQuotesTheColumn()

	/**
	 * A linked-type column is known only on a schema that declares the type.
	 *
	 * @return void
	 */
	public function testALinkedTypeColumnNeedsTheSchemasLinkedType(): void {
		$schema = $this->schema($this->cross(['_mail' => ['$exists' => true]]));
		$this->assertSame('(1 = 0)', $this->handler()->getCrossOrganisationBranchSql(schema: $schema));

		$schema->setConfiguration(['linkedTypes' => ['mail']]);
		$this->assertSame('(`_mail` IS NOT NULL)', $this->handler()->getCrossOrganisationBranchSql(schema: $schema));
	}//end testALinkedTypeColumnNeedsTheSchemasLinkedType()

	/**
	 * On a real database such a key returns no row and the query still runs.
	 *
	 * @param string $key The refused key.
	 *
	 * @return void
	 */
	#[DataProvider('refusedKeys')]
	#[RequiresPhpExtension('pdo_sqlite')]
	public function testARealDatabaseReturnsNoRow(string $key): void {
		$this->assertSame([], $this->rowsFor(match: [$key => 'open']));
	}//end testARealDatabaseReturnsNoRow()

	/**
	 * CONTROL: on a real database a known key selects its rows, a reserved word included.
	 *
	 * @return void
	 */
	#[RequiresPhpExtension('pdo_sqlite')]
	public function testARealDatabaseSelectsOnAKnownKey(): void {
		$this->assertSame(['a'], $this->rowsFor(match: ['status' => 'open']));
		$this->assertSame(['b'], $this->rowsFor(match: ['order' => 'second']));
		$this->assertSame(['b'], $this->rowsFor(match: ['@self.owner' => 'bob']));
	}//end testARealDatabaseSelectsOnAKnownKey()

	/**
	 * The keys of the rows a cross-organisation rule on this clause selects.
	 *
	 * @param array $match The clause.
	 *
	 * @return array<int, string> The row keys.
	 */
	private function rowsFor(array $match): array {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE t (k TEXT, status TEXT, "order" TEXT, _owner TEXT)');
		$pdo->exec("INSERT INTO t VALUES ('a', 'open', 'first', 'alice'), ('b', 'closed', 'second', 'bob')");

		$branch = $this->handler()->getCrossOrganisationBranchSql(schema: $this->schema($this->cross($match)));

		return $pdo->query('SELECT k FROM t WHERE '.$branch.' ORDER BY k')->fetchAll(PDO::FETCH_COLUMN);
	}//end rowsFor()
}//end class

<?php

/**
 * The object-access contract on both layers: one declaration, one answer.
 *
 * A `case` schema declares that its assignee, or a member of its assignees,
 * may read it. The find path (PermissionHandler) and the list path
 * (MagicRbacHandler, compiled to SQL by MagicSearchHandler and run against an
 * SQLite table) are both real, over the same real ConditionMatcher. Only the
 * Nextcloud boundaries are doubles. Each caller's find verdicts over every
 * case must equal the uuids their list returns, which is the layer-agreement
 * guard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-additional-object-access-control-is-declared-on-the-schema-not-guarded-in-an-app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\RbacPermissionAsk;
use OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\VerbListing;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\AbstractObjectMapper
 * @uses   \OCA\OpenRegister\Db\ColumnFiltersTrait
 * @uses   \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses   \OCA\OpenRegister\Db\JsonFieldsTrait
 * @uses   \OCA\OpenRegister\Db\LikeOperator
 * @uses   \OCA\OpenRegister\Db\LinkedItemsTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\ArrayElementFilter
 * @uses   \OCA\OpenRegister\Db\MagicMapper\ConditionSqlRun
 * @uses   \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses   \OCA\OpenRegister\Db\MagicMapper\DraftListRequest
 * @uses   \OCA\OpenRegister\Db\MagicMapper\LifecycleColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicReadTable
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnionOrder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\NestedPathFilter
 * @uses   \OCA\OpenRegister\Db\MagicMapper\OrganisationBoundary
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacAccessCall
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacFilterCall
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacMatchSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacOperatorTerm
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacPermissionAsk
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacPermissionCheck
 * @uses   \OCA\OpenRegister\Db\MagicMapper\RbacSqlDialect
 * @uses   \OCA\OpenRegister\Db\MagicMapper\ReadPosture
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Db\MagicMapper\WhereClauseRequest
 * @uses   \OCA\OpenRegister\Db\ManagedByConfigurationTrait
 * @uses   \OCA\OpenRegister\Db\MultiTenancyTrait
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\ObjectEntity\ObjectEntityFieldTypes
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\RegisterMapper
 * @uses   \OCA\OpenRegister\Db\Register\RegisterFieldTypes
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\SchemaMapper
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\SchemaFieldTypes
 * @uses   \OCA\OpenRegister\Db\SlugIdLookupTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\EntityRbacTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\OrganisationWriteGuardTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 * @uses   \OCA\OpenRegister\Event\CustomScopeEvaluatedEvent
 * @uses   \OCA\OpenRegister\Event\CustomScopeEvaluatingEvent
 * @uses   \OCA\OpenRegister\Event\EvaluatedSchemaActionAccessors
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\ComparableInstants
 * @uses   \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\ConditionUserResolvers
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator
 * @uses   \OCA\OpenRegister\Service\DateTimeNormalizer
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses   \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses   \OCA\OpenRegister\Service\Object\DraftListing
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses   \OCA\OpenRegister\Service\Object\InheritFromPublicSetting
 * @uses   \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses   \OCA\OpenRegister\Service\Object\PreservedListLens
 * @uses   \OCA\OpenRegister\Service\Object\PreservedListing
 * @uses   \OCA\OpenRegister\Service\Object\SchemaTypeConverter
 * @uses   \OCA\OpenRegister\Service\Object\VerbListing
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses   \OCA\OpenRegister\Service\Rbac\AccessDeclarationValidators
 * @uses   \OCA\OpenRegister\Service\Rbac\AuthorizationColumn
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler
 * @uses   \OCA\OpenRegister\Service\Rbac\DerivedGrantResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\DerivedGrantStore
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantLookup
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantedRows
 * @uses   \OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander
 * @uses   \OCA\OpenRegister\Service\Rbac\MatrixRowWhere
 * @uses   \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses   \OCA\OpenRegister\Service\Rbac\MemberOfResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectGrantResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\PermissionCatalogue
 * @uses   \OCA\OpenRegister\Service\Rbac\RbacCollaborators
 * @uses   \OCA\OpenRegister\Service\Rbac\RbacFallbacks
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\Rbac\RevealCollector
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\StagedDenial
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantNarrower
 * @uses   \OCA\OpenRegister\Service\Rbac\UndecidablePosture
 * @uses   \OCA\OpenRegister\Service\Rbac\UndeclaredPropertyConditions
 * @uses   \OCA\OpenRegister\Service\Search\SearchGrammar
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermParser
 * @uses   \OCA\OpenRegister\Service\Search\SearchTermSqlCompiler
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Support\NullFilter
 */
final class ObjectAccessContractTest extends TestCase {

	/**
	 * The cases: uuid => [assignee, assignees].
	 *
	 * @var array<string, array{0: string|null, 1: array<int, string>}>
	 */
	private const CASES = [
		'c-1' => ['anna', []],
		'c-2' => ['dirk', ['bram', 'erik']],
		'c-3' => [null, ['bram']],
		'c-4' => ['dirk', ['erik']],
		'c-5' => [null, []],
	];

	/**
	 * The info lines the find path logged, with their context.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $infos = [];

	/**
	 * The case schema with the dossiq declaration from design D-1.
	 *
	 * @param bool $withAssignees Whether the second read entry (assignees `$contains`) is declared.
	 *
	 * @return Schema
	 */
	private static function caseSchema(bool $withAssignees = true): Schema {
		$read = [['group' => 'authenticated', 'match' => ['assignee' => '$userId']]];
		if ($withAssignees === true) {
			$read[] = ['group' => 'authenticated', 'match' => ['assignees' => ['$contains' => '$userId']]];
		}

		$schema = new Schema();
		$schema->setId(70);
		$schema->setSlug('case');
		$schema->setTitle('Case');
		$schema->setProperties(
			[
				'assignee'  => ['type' => 'string'],
				'assignees' => ['type' => 'array', 'items' => ['type' => 'string']],
			]
		);
		$schema->setAuthorization(
			[
				'read'   => $read,
				'update' => [['group' => 'authenticated', 'match' => ['assignee' => '$userId']]],
			]
		);

		return $schema;
	}//end caseSchema()

	/**
	 * Record a log line; called by the logger double.
	 *
	 * @param string $level   The level.
	 * @param string $message The message.
	 * @param array  $context The context.
	 *
	 * @return void
	 */
	public function record(string $level, string $message, array $context): void {
		if ($level === 'info') {
			$this->infos[] = [$message, $context];
		}
	}//end record()

	private function session(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	private function groupManager(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn(['medewerkers']);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(false);
		return $groups;
	}//end groupManager()

	private function matcher(IUserSession $session, IGroupManager $groups): ConditionMatcher {
		$evaluator = new OperatorEvaluator(new NullLogger());
		return new ConditionMatcher(
			new ConditionUserResolvers($session, $groups),
			$this->createMock(ContainerInterface::class),
			$evaluator,
			new NullLogger()
		);
	}//end matcher()

	/**
	 * The find path for one caller: a real PermissionHandler over a register with no rules of its own.
	 *
	 * @param string $uid The caller.
	 *
	 * @return PermissionHandler
	 */
	private function findPath(string $uid): PermissionHandler {
		$test   = $this;
		$logger = new class($test) extends AbstractLogger {
			public function __construct(private readonly ObjectAccessContractTest $test) {
			}

			public function log($level, string|Stringable $message, array $context=[]): void {
				$this->test->record((string)$level, (string)$message, $context);
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_ENFORCING);

		$session = $this->session($uid);
		$groups  = $this->groupManager();
		$users   = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($session->getUser());

		$register = new Register();
		$register->setId(9);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(9);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registerMapper): mixed {
				if ($id === RegisterMapperInterface::class) {
					return $registerMapper;
				}

				throw new RuntimeException('nothing registered for '.$id);
			}
		);

		$catalogue = $this->createMock(PermissionCatalogue::class);
		$catalogue->method('declarationFor')->willReturn(null);
		$catalogue->method('isGrantable')->willReturn(false);
		$catalogue->method('verbs')->willReturn(['create', 'read', 'update', 'delete', 'list', 'manage']);
		$store = $this->createMock(DerivedGrantStore::class);
		$store->method('grantsFor')->willReturn([]);
		$derived = $this->createMock(DerivedGrantResolver::class);
		$derived->method('groupsFor')->willReturn([]);
		$grants = $this->createMock(ObjectGrantResolver::class);
		$grants->method('isGranted')->willReturn(false);

		return PermissionHandlerFactory::create(
			userSession: $session,
			userManager: $users,
			groupManager: $groups,
			schemaMapper: $this->createMock(SchemaMapper::class),
			objectEntityMapper: $this->createMock(MagicMapper::class),
			conditionMatcher: $this->matcher($session, $groups),
			appConfig: $appConfig,
			logger: $logger,
			container: $container,
			eventDispatcher: null,
			objectScopeResolver: new ObjectScopeResolver(),
			objectGrantResolver: $grants,
			denyResolver: new DenyResolver(matcher: new DenyEntryMatcher()),
			denyEnforcementMode: new DenyEnforcementMode(appConfig: $appConfig, logger: $logger),
			permissionCatalogue: $catalogue,
			derivedGrantStore: $store,
			derivedGrantResolver: $derived
		);
	}//end findPath()

	/**
	 * The list path for one caller: the real MagicRbacHandler, resolving the
	 * cascade through the real find-path handler.
	 *
	 * @param string $uid The caller.
	 *
	 * @return MagicRbacHandler
	 */
	private function listPath(string $uid): MagicRbacHandler {
		$session = $this->session($uid);
		$groups  = $this->groupManager();
		$find    = $this->findPath($uid);

		$grants = $this->createMock(ObjectGrantResolver::class);
		$grants->method('isGranted')->willReturn(false);
		$grants->method('grantedObjectUuidsFor')->willReturn([]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($grants, $find, $db): object {
				return match ($id) {
					ObjectGrantResolver::class => $grants,
					PermissionHandlerInterface::class, PermissionHandler::class => $find,
					IDBConnection::class => $db,
					default => throw new RuntimeException('nothing registered for '.$id),
				};
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$appConfig->method('getValueBool')->willReturn(false);

		return new MagicRbacHandler(
			identity: new MagicMapperIdentity(
				userSession: $session,
				groupManager: $groups,
				userManager: $this->createMock(IUserManager::class)
			),
			settings: new MagicRbacSettings(appConfig: $appConfig),
			support: new MagicRbacSupport(
				conditionMatcher: $this->matcher($session, $groups),
				container: $container,
				logger: new NullLogger()
			)
		);
	}//end listPath()

	/**
	 * The uuids a caller's list returns, from the SQL the list path compiles, run on a real table.
	 *
	 * @param string $uid    The caller.
	 * @param Schema $schema The case schema.
	 *
	 * @return string[] The listed uuids, sorted.
	 */
	private function listed(string $uid, Schema $schema): array {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'".str_replace("'", "''", (string)$value)."'"
		);
		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		$organisation = $this->createMock(MagicOrganizationHandler::class);
		$organisation->method('resolveOrganizationScope')->willReturn(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-home']]);
		$organisation->method('isAdminOverrideEnabled')->willReturn(false);

		$search = new MagicSearchHandler(
			$db,
			new NullLogger(),
			$this->listPath($uid),
			$organisation,
			$this->createMock(SchemaTypeConverter::class),
			$this->createMock(DateTimeNormalizer::class),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
		);

		$conditions = $search->buildWhereClause(request: new WhereClauseRequest(query: [], table: new MagicReadTable(schema: $schema)));
		$this->assertNotSame([], $conditions, 'the list must be filtered, not open');

		$rows = $this->table()->query('SELECT _uuid FROM t WHERE '.implode(' AND ', $conditions).' ORDER BY _uuid')->fetchAll(PDO::FETCH_COLUMN);
		return array_values($rows);
	}//end listed()

	/**
	 * The uuids the find path admits for a caller, one find per case.
	 *
	 * @param string $uid    The caller.
	 * @param Schema $schema The case schema.
	 * @param string $action The verb.
	 *
	 * @return string[] The admitted uuids, sorted.
	 */
	private function found(string $uid, Schema $schema, string $action = 'read'): array {
		$handler  = $this->findPath($uid);
		$admitted = [];
		foreach (array_keys(self::CASES) as $uuid) {
			if ($handler->hasPermission(ask: self::ask($schema, $action, $uid, $uuid)) === true) {
				$admitted[] = $uuid;
			}
		}

		sort($admitted);
		return $admitted;
	}//end found()

	/**
	 * The find-path question: a caller asking for a verb on one case.
	 *
	 * @param Schema $schema The case schema.
	 * @param string $action The verb.
	 * @param string $uid    The caller.
	 * @param string $uuid   The case.
	 *
	 * @return PermissionAsk
	 */
	private static function ask(Schema $schema, string $action, string $uid, string $uuid): PermissionAsk {
		return (new PermissionAsk(schema: $schema, action: $action))
			->askedBy(userId: $uid, objectOwner: 'importer')
			->about(object: self::object($uuid), register: null);
	}//end ask()

	private static function object(string $uuid): ObjectEntity {
		[$assignee, $assignees] = self::CASES[$uuid];
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setOwner('importer');
		$object->setOrganisation('org-home');
		$object->setObject(['assignee' => $assignee, 'assignees' => $assignees]);
		return $object;
	}//end object()

	/**
	 * The case table, with MySQL's JSON_CONTAINS and JSON_QUOTE supplied for SQLite.
	 *
	 * @return PDO
	 */
	private function table(): PDO {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$pdo->sqliteCreateFunction('JSON_QUOTE', static fn ($value): string => (string)json_encode((string)$value), 1);
		$pdo->sqliteCreateFunction(
			'JSON_CONTAINS',
			static function ($document, $candidate): int {
				$list = json_decode((string)$document, true);
				return (int)(is_array($list) === true && in_array(json_decode((string)$candidate, true), $list, true) === true);
			},
			2
		);
		$pdo->exec(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT,'
			.' _deleted TEXT, _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT,'
			.' assignee TEXT, assignees TEXT)'
		);
		$insert = $pdo->prepare("INSERT INTO t (_uuid, _owner, _organisation, assignee, assignees) VALUES (?, 'importer', 'org-home', ?, ?)");
		foreach (self::CASES as $uuid => [$assignee, $assignees]) {
			$insert->execute([$uuid, $assignee, json_encode($assignees)]);
		}

		return $pdo;
	}//end table()

	/**
	 * Scenario: a case assignee reads their case and no other.
	 *
	 * @return void
	 */
	public function testAnAssigneeListsAndFindsTheirCaseAndNoOther(): void {
		$schema = self::caseSchema(withAssignees: false);

		$this->assertSame(['c-1'], $this->listed('anna', $schema));
		$this->assertSame(['c-1'], $this->found('anna', $schema));
		$this->assertFalse(
			$this->findPath('anna')->hasPermission(ask: self::ask($schema, 'read', 'anna', 'c-2')),
			'a find on another case is refused'
		);
	}//end testAnAssigneeListsAndFindsTheirCaseAndNoOther()

	/**
	 * Scenario: a member of the assignees array is admitted on both layers, and only there.
	 *
	 * @return void
	 */
	public function testAMemberOfTheAssigneesIsAdmittedOnBothLayersAndOnlyThere(): void {
		$schema = self::caseSchema();

		$this->assertSame(['c-2', 'c-3'], $this->listed('bram', $schema));
		$this->assertSame(['c-2', 'c-3'], $this->found('bram', $schema));
		$this->assertSame([], $this->found('bram', $schema, 'update'), 'update declares the assignee alone');
	}//end testAMemberOfTheAssigneesIsAdmittedOnBothLayersAndOnlyThere()

	/**
	 * Scenario: a stranger is refused, and the refusal names the principal and the rule.
	 *
	 * @return void
	 */
	public function testAStrangerIsRefusedAndTheRefusalNamesThePrincipalAndTheRule(): void {
		$schema = self::caseSchema();

		$this->assertSame([], $this->listed('carl', $schema));

		$this->infos = [];
		$verdict = $this->findPath('carl')->hasPermission(
			ask: self::ask($schema, 'read', 'carl', 'c-2')
		);
		$this->assertFalse($verdict);

		$refusals = array_values(
			array_filter(
				$this->infos,
				static fn (array $line): bool => $line[0] === '[PermissionHandler] Action refused; the rule that decided it is named below'
			)
		);
		$this->assertCount(1, $refusals);
		$context = $refusals[0][1];
		$this->assertSame('carl', $context['userId']);
		$this->assertSame('c-2', $context['objectUuid']);
		$this->assertSame('read', $context['action']);
		$this->assertContains('medewerkers', $context['principals']);
		$this->assertSame(self::caseSchema()->getAuthorization()['read'], $context['rule']);
		$this->assertSame('the rule for this verb does not name this caller', $context['reason']);
	}//end testAStrangerIsRefusedAndTheRefusalNamesThePrincipalAndTheRule()

	/**
	 * Scenario: the answer an app asks for and the enforcement agree.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-app-declares-object-access-rather-than-guarding-it/specs/object-access-contract/spec.md#requirement-an-app-can-ask-one-question-instead-of-guessing
	 */
	public function testTheAnswerAnAppAsksForAgreesWithTheEnforcement(): void {
		$schema = self::caseSchema();

		foreach (['carl' => [], 'bram' => ['read'], 'dirk' => ['read', 'update']] as $uid => $expected) {
			$handler  = $this->findPath($uid);
			$answered = $handler->permittedActionsFor(new VerbListing(schema: $schema, object: self::object('c-2'), userId: $uid));
			$this->assertSame($expected, $answered, $uid.' on c-2');

			foreach (['read', 'update'] as $verb) {
				$enforced = $handler->hasPermission(ask: self::ask($schema, $verb, $uid, 'c-2'));
				$this->assertSame(in_array($verb, $answered, true), $enforced, $uid.' '.$verb.': the answer is the enforcement');
			}
		}
	}//end testTheAnswerAnAppAsksForAgreesWithTheEnforcement()

	/**
	 * Callers whose answers the agreement guard compares.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function callers(): array {
		return [
			'the assignee'           => ['anna', ['c-1']],
			'a member of assignees'  => ['bram', ['c-2', 'c-3']],
			'in two cases'           => ['erik', ['c-2', 'c-4']],
			'the assignee of two'    => ['dirk', ['c-2', 'c-4']],
			'a stranger'             => ['carl', []],
		];
	}//end callers()

	/**
	 * Task 5.3: for one declaration, the find path and the list path answer the same set.
	 *
	 * @param string             $uid      The caller.
	 * @param array<int, string> $expected The cases they may read.
	 *
	 * @return void
	 *
	 * @dataProvider callers
	 */
	public function testTheFindPathAndTheListPathAnswerTheSameSet(string $uid, array $expected): void {
		$schema = self::caseSchema();

		$listed = $this->listed($uid, $schema);
		$found  = $this->found($uid, $schema);

		$this->assertSame($found, $listed, 'an object refused on a find is absent from the list, and the reverse');
		$this->assertSame($expected, $found);
	}//end testTheFindPathAndTheListPathAnswerTheSameSet()

	/**
	 * The row verdict the list path gives one loaded row agrees with the find path too.
	 *
	 * @param string             $uid      The caller.
	 * @param array<int, string> $expected The cases they may read.
	 *
	 * @return void
	 *
	 * @dataProvider callers
	 */
	public function testTheListPathRowVerdictAgreesWithTheFindPath(string $uid, array $expected): void {
		$schema = self::caseSchema();
		$rbac   = $this->listPath($uid);

		$admitted = [];
		foreach (array_keys(self::CASES) as $uuid) {
			if ($rbac->hasRowPermission(new RbacPermissionAsk($schema, 'read'), self::object($uuid)) === true) {
				$admitted[] = $uuid;
			}
		}

		$this->assertSame($expected, $admitted);
		$this->assertSame($this->found($uid, $schema), $admitted);
	}//end testTheListPathRowVerdictAgreesWithTheFindPath()
}//end class

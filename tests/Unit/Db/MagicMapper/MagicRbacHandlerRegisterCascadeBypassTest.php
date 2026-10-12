<?php

/**
 * MagicRbacHandler register cascade and the organisation boundary
 *
 * A schema without an authorization block of its own is governed by its
 * register's block. For a reader that block grants, the organisation filter
 * stays on and ALSO admits the rows with no organisation; the rows of other
 * organisations stay hidden. A schema's own block keeps lifting the filter as
 * before. The per-object verdict (hasPermission) reads the same cascade.
 *
 * These tests wire a REAL PermissionHandler (only the register lookup is a
 * double) so the cascade under test is the production one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PDO;
use RuntimeException;

/**
 * Tests that the register cascade admits org-less rows only, and that hasPermission() reads it.
 */
class MagicRbacHandlerRegisterCascadeBypassTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var MagicRbacHandler
	 */
	private MagicRbacHandler $handler;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Register lookup double (the only part of the cascade that is not real).
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Build the real PermissionHandler and the subject under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$conditionMatcher = $this->createMock(originalClassName: ConditionMatcher::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);

		$container = $this->createMock(originalClassName: ContainerInterface::class);

		$permissionHandler = new PermissionHandler(
			$this->userSession,
			$userManager,
			$this->groupManager,
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$conditionMatcher,
			$appConfig,
			$logger,
			$container
		);

		$container->method('get')->willReturnCallback(
			function (string $service) use ($permissionHandler) {
				if ($service === PermissionHandler::class) {
					return $permissionHandler;
				}

				if ($service === RegisterMapper::class) {
					return $this->registerMapper;
				}

				throw new RuntimeException(message: 'Unexpected service ' . $service);
			}
		);

		$this->handler = new MagicRbacHandler(
			$this->userSession,
			$this->groupManager,
			$userManager,
			$appConfig,
			$conditionMatcher,
			$container,
			$logger
		);

	}//end setUp()

	/**
	 * Give the schema a learniq-shaped parent register: a `read-write` role
	 * granted to `instructors` and `hr`, and no schema-level block.
	 *
	 * @return void
	 */
	private function wireLearniqRegister(): void {
		$register = new Register();
		$register->setId(99);
		$register->setTitle('learniq');
		$register->setAuthorization(['roles' => ['read-write' => ['instructors', 'hr']]]);
		$register->setConfiguration(
			[
				'roles' => [
					['name' => 'read-write', 'actions' => ['read', 'create', 'update']],
				],
			]
		);

		$this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(99);
		$this->registerMapper->method('find')->willReturn($register);

	}//end wireLearniqRegister()

	/**
	 * Build a schema fixture.
	 *
	 * @param array|null $authorization The schema's own authorization block.
	 *
	 * @return Schema
	 */
	private function createSchema(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('School');
		$schema->setAuthorization($authorization);

		return $schema;

	}//end createSchema()

	/**
	 * Log a user in with the given groups.
	 *
	 * @param string $uid    The user id.
	 * @param array  $groups The user's groups.
	 *
	 * @return void
	 */
	private function mockUser(string $uid, array $groups): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);

	}//end mockUser()

	/**
	 * A search handler over the REAL rbac handler, whose organisation handler
	 * reports the caller's organisation as `org-home`.
	 *
	 * @param array $scope The organisation scope the organisation handler decides.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function searchHandler(
		array $scope = ['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-home']]
	): MagicSearchHandler {
		$connection = $this->createMock(originalClassName: IDBConnection::class);
		$connection->method('quote')->willReturnCallback(
			static fn ($value): string => "'" . str_replace("'", "''", (string)$value) . "'"
		);

		$queryBuilder = $this->createMock(originalClassName: IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		$organisation = $this->createMock(originalClassName: MagicOrganizationHandler::class);
		$organisation->method('resolveOrganizationScope')->willReturn($scope);
		$organisation->method('isAdminOverrideEnabled')->willReturn(false);

		return new MagicSearchHandler(
			$db,
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->handler,
			$organisation,
			$this->createMock(originalClassName: SchemaTypeConverter::class),
			$this->createMock(originalClassName: DateTimeNormalizer::class),
			relatedRows: $this->createMock(originalClassName: RelatedRowQueryApplier::class)
		);

	}//end searchHandler()

	/**
	 * Run the organisation and RBAC conditions the list builds against a real
	 * table holding one row of the caller's organisation, one with no
	 * organisation and one of another organisation.
	 *
	 * @param Schema $schema The schema read.
	 * @param array  $query  The list query.
	 *
	 * @return array<int, string> The uuids the list returns, sorted.
	 */
	private function visibleRows(Schema $schema, array $query = []): array {
		$conditions = $this->searchHandler()->buildWhereConditionsSql(query: $query, schema: $schema);

		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// The non-Postgres RBAC predicate is written in the MySQL dialect;
		// SQLite's json_extract() already returns the unquoted scalar.
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$pdo->exec(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT,'
			. ' _deleted TEXT, _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT)'
		);
		$pdo->exec(
			"INSERT INTO t (_uuid, _owner, _organisation) VALUES"
			. " ('own-org', 'importer', 'org-home'),"
			. " ('no-org', 'importer', NULL),"
			. " ('other-org', 'importer', 'org-other')"
		);

		$where = '';
		if ($conditions !== []) {
			$where = ' WHERE ' . implode(' AND ', $conditions);
		}

		$uuids = $pdo->query('SELECT _uuid FROM t' . $where)->fetchAll(PDO::FETCH_COLUMN);
		sort($uuids);

		return $uuids;

	}//end visibleRows()

	/**
	 * A granted reader of a schema that inherits its register's role keeps the
	 * organisation filter: the cascade does not lift it.
	 *
	 * @return void
	 */
	public function testInheritedSchemaKeepsTheOrganisationFilterForAGrantedReader(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertFalse(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(
				schema: $this->createSchema(authorization: null),
				action: 'read'
			)
		);

	}//end testInheritedSchemaKeepsTheOrganisationFilterForAGrantedReader()

	/**
	 * The register role admits the org-less rows for a reader it grants.
	 *
	 * @return void
	 */
	public function testInheritedSchemaAdmitsOrganisationlessRowsForAGrantedReader(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertTrue(
			condition: $this->handler->admitsOrganisationlessRowsThroughCascade(
				schema: $this->createSchema(authorization: null),
				action: 'read'
			)
		);

	}//end testInheritedSchemaAdmitsOrganisationlessRowsForAGrantedReader()

	/**
	 * End to end over the list's own SQL: the granted instructor sees its own
	 * organisation's row and the org-less row, and NOT another organisation's.
	 *
	 * @return void
	 */
	public function testGrantedReaderSeesOrganisationlessRowButNotAnotherOrganisationsRow(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertSame(
			expected: ['no-org', 'own-org'],
			actual: $this->visibleRows(schema: $this->createSchema(authorization: null))
		);

	}//end testGrantedReaderSeesOrganisationlessRowButNotAnotherOrganisationsRow()

	/**
	 * An explicit `_multi` keeps the strict organisation filter, as it does for
	 * a schema's own rules.
	 *
	 * @return void
	 */
	public function testExplicitMultitenancyKeepsTheStrictFilter(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertSame(
			expected: ['own-org'],
			actual: $this->visibleRows(
				schema: $this->createSchema(authorization: null),
				query: ['_multitenancy' => true, '_multitenancy_explicit' => true]
			)
		);

	}//end testExplicitMultitenancyKeepsTheStrictFilter()

	/**
	 * A user outside every granted group gets neither a bypass nor the org-less
	 * rows, and the list shows nothing.
	 *
	 * @return void
	 */
	public function testUngrantedUserGetsNothing(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-ib-01', groups: ['coordinators']);
		$schema = $this->createSchema(authorization: null);

		$this->assertFalse(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(schema: $schema, action: 'read')
		);
		$this->assertFalse(
			condition: $this->handler->admitsOrganisationlessRowsThroughCascade(schema: $schema, action: 'read')
		);
		$this->assertSame(expected: [], actual: $this->visibleRows(schema: $schema));

	}//end testUngrantedUserGetsNothing()

	/**
	 * The register role grants read, not delete, so it admits nothing for delete.
	 *
	 * @return void
	 */
	public function testRegisterRoleAdmitsNothingForAnUngrantedAction(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertFalse(
			condition: $this->handler->admitsOrganisationlessRowsThroughCascade(
				schema: $this->createSchema(authorization: null),
				action: 'delete'
			)
		);

	}//end testRegisterRoleAdmitsNothingForAnUngrantedAction()

	/**
	 * A schema with its own block is unchanged: its own grant still lifts the
	 * organisation filter, the register role neither leaks into it nor admits
	 * org-less rows on it.
	 *
	 * @return void
	 */
	public function testSchemaOwnBlockIsUnchanged(): void {
		$this->wireLearniqRegister();
		$schema = $this->createSchema(authorization: ['read' => ['teachers']]);

		$this->mockUser(uid: 'juf-01', groups: ['teachers']);
		$this->assertTrue(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(schema: $schema, action: 'read')
		);
		$this->assertFalse(
			condition: $this->handler->admitsOrganisationlessRowsThroughCascade(schema: $schema, action: 'read')
		);
		$this->assertSame(
			expected: ['no-org', 'other-org', 'own-org'],
			actual: $this->visibleRows(schema: $schema)
		);

	}//end testSchemaOwnBlockIsUnchanged()

	/**
	 * On a schema with its own block, a member of the register's group only is
	 * still refused, exactly as before.
	 *
	 * @return void
	 */
	public function testSchemaOwnBlockStillRefusesTheRegistersGroup(): void {
		$this->wireLearniqRegister();
		$schema = $this->createSchema(authorization: ['read' => ['teachers']]);
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertFalse(
			condition: $this->handler->hasConditionalRulesBypassingMultitenancy(schema: $schema, action: 'read')
		);
		$this->assertFalse(
			condition: $this->handler->admitsOrganisationlessRowsThroughCascade(schema: $schema, action: 'read')
		);
		$this->assertSame(expected: [], actual: $this->visibleRows(schema: $schema));

	}//end testSchemaOwnBlockStillRefusesTheRegistersGroup()

	/**
	 * A register rule that only matches `_organisation` admits nothing extra.
	 *
	 * @return void
	 */
	public function testRegisterOrganisationOnlyMatchAdmitsNothing(): void {
		$register = new Register();
		$register->setId(99);
		$register->setTitle('learniq');
		$register->setAuthorization(
			['read' => [['group' => 'instructors', 'match' => ['_organisation' => '$organisation']]]]
		);
		$this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(99);
		$this->registerMapper->method('find')->willReturn($register);
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertFalse(
			condition: $this->handler->admitsOrganisationlessRowsThroughCascade(
				schema: $this->createSchema(authorization: null),
				action: 'read'
			)
		);

	}//end testRegisterOrganisationOnlyMatchAdmitsNothing()

	/**
	 * An unresolvable register admits nothing (fail closed).
	 *
	 * @return void
	 */
	public function testUnresolvableRegisterAdmitsNothing(): void {
		$this->registerMapper->method('getFirstRegisterWithSchema')
			->willThrowException(new RuntimeException(message: 'database gone'));
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertFalse(
			condition: $this->handler->admitsOrganisationlessRowsThroughCascade(
				schema: $this->createSchema(authorization: null),
				action: 'read'
			)
		);

	}//end testUnresolvableRegisterAdmitsNothing()

	/**
	 * The per-object verdict reads the cascade: on a schema with no block of
	 * its own, a user the register does not grant is refused. It used to read
	 * the schema's own (absent) block and treat the schema as open.
	 *
	 * @return void
	 */
	public function testHasPermissionRefusesAUserTheRegisterDoesNotGrant(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-ib-01', groups: ['coordinators']);

		$this->assertFalse(
			condition: $this->handler->hasPermission(
				schema: $this->createSchema(authorization: null),
				action: 'read',
				objectOwner: 'importer',
				objectData: ['name' => 'De Wilg', '_organisation' => null]
			)
		);

	}//end testHasPermissionRefusesAUserTheRegisterDoesNotGrant()

	/**
	 * The per-object verdict admits the user the register grants.
	 *
	 * @return void
	 */
	public function testHasPermissionAdmitsAUserTheRegisterGrants(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertTrue(
			condition: $this->handler->hasPermission(
				schema: $this->createSchema(authorization: null),
				action: 'read',
				objectOwner: 'importer',
				objectData: ['name' => 'De Wilg', '_organisation' => null]
			)
		);

	}//end testHasPermissionAdmitsAUserTheRegisterGrants()

	/**
	 * The per-object verdict on a schema with its own block is unchanged.
	 *
	 * @return void
	 */
	public function testHasPermissionOnASchemaOwnBlockIsUnchanged(): void {
		$this->wireLearniqRegister();
		$schema = $this->createSchema(authorization: ['read' => ['teachers']]);
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);

		$this->assertFalse(
			condition: $this->handler->hasPermission(schema: $schema, action: 'read', objectData: ['name' => 'x'])
		);

	}//end testHasPermissionOnASchemaOwnBlockIsUnchanged()

	/**
	 * A schema in no register and with no block stays open, as the cascade says.
	 *
	 * @return void
	 */
	public function testHasPermissionWithNoBlockAnywhereStaysOpen(): void {
		$this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(null);
		$this->mockUser(uid: 'po-ib-01', groups: ['coordinators']);

		$this->assertTrue(
			condition: $this->handler->hasPermission(
				schema: $this->createSchema(authorization: null),
				action: 'read',
				objectData: ['name' => 'x']
			)
		);

	}//end testHasPermissionWithNoBlockAnywhereStaysOpen()

	/**
	 * The aggregation fast path asks the search handler the list's question:
	 * on an inherited schema the boundary is kept and widened, on a schema's
	 * own block it is waived as before.
	 *
	 * @return void
	 */
	public function testAggregationQuestionsFollowTheListDecision(): void {
		$this->wireLearniqRegister();
		$this->mockUser(uid: 'po-leerkracht-09', groups: ['instructors']);
		$inherited = $this->createSchema(authorization: null);
		$search = $this->searchHandler();

		$this->assertFalse(condition: $search->organisationBoundaryWaivedByRbac(schema: $inherited));
		$this->assertTrue(condition: $search->organisationlessRowsAdmittedByRbac(schema: $inherited));

		$ownBlock = $this->createSchema(authorization: ['read' => ['instructors']]);
		$this->assertTrue(condition: $search->organisationBoundaryWaivedByRbac(schema: $ownBlock));
		$this->assertFalse(condition: $search->organisationlessRowsAdmittedByRbac(schema: $ownBlock));

	}//end testAggregationQuestionsFollowTheListDecision()

	/**
	 * Widening a scope by the org-less rows changes only the two modes that
	 * exclude them.
	 *
	 * @return void
	 */
	public function testAdmitOrganisationlessWidensOnlyTheExcludingModes(): void {
		$widen = static fn (string $mode, array $uuids = []): array => MagicOrganizationHandler::admitOrganisationless(
			scope: ['mode' => $mode, 'uuids' => $uuids]
		);

		$this->assertSame(
			expected: ['mode' => MagicOrganizationHandler::SCOPE_IN_OR_NULL, 'uuids' => ['org-home']],
			actual: $widen(MagicOrganizationHandler::SCOPE_IN, ['org-home'])
		);
		$this->assertSame(
			expected: ['mode' => MagicOrganizationHandler::SCOPE_NULL_ONLY, 'uuids' => []],
			actual: $widen(MagicOrganizationHandler::SCOPE_NONE)
		);
		$this->assertSame(expected: MagicOrganizationHandler::SCOPE_ALL, actual: $widen(MagicOrganizationHandler::SCOPE_ALL)['mode']);
		$this->assertSame(expected: 'a-later-mode', actual: $widen('a-later-mode')['mode']);

	}//end testAdmitOrganisationlessWidensOnlyTheExcludingModes()
}//end class

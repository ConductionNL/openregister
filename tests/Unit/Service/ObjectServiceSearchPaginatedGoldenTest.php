<?php

/**
 * ObjectService::searchObjectsPaginated() scopes, delegates, annotates and extends exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of searchObjectsPaginated() over every branch: the object-source short-circuit and
 * its read check (which user it runs for), the register/schema scoping, view application, the
 * multitenancy bypass for public-read schemas, the arguments the database search receives, the
 * @self annotations, the _extend objects and _names maps, and the search-trail call.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class ObjectServiceSearchPaginatedGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-service-search-paginated-golden.json';

	private const REL = '0b3f2c1a-1111-4222-8333-944455556666';

	private const OWNER = '0b3f2c1a-1111-4222-8333-000000000001';

	/** @var array<int, array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private array $setup = [];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$rows = [['@self' => ['relations' => [self::REL], 'owner' => self::OWNER], 'title' => 'a'], ['id' => 2, 'ref' => self::REL], 'not-an-array'];
		$actual = [
			'no context'                         => $this->runScenario(['query' => ['_limit' => 5]]),
			'register and schema context'        => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['_limit' => 5]]),
			'register only'                      => $this->runScenario(['register' => true, 'query' => []]),
			'schema only'                        => $this->runScenario(['schema' => [], 'query' => []]),
			'explicit null register and schema'  => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['_register' => null, '_schema' => null]]),
			'caller register and schema kept'    => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['_register' => 7, '_schema' => 70]]),
			'_schemas: no schema, register kept' => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['_schemas' => [10, 11]]]),
			'@self.schema array'                 => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['@self' => ['schema' => [10, 11]]]]),
			'@self.schema empty array'           => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['@self' => ['schema' => []]]]),
			'@self.schema scalar'                => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['@self' => ['schema' => 10]]]),
			'@self.schemas'                      => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['@self' => ['schemas' => [10]]]]),
			'@self.schemas null'                 => $this->runScenario(['register' => true, 'query' => ['@self' => ['schemas' => null]]]),
			'_schemas null'                      => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['_schemas' => null]]),
			'views applied'                      => $this->runScenario(['register' => true, 'schema' => [], 'query' => ['_search' => 'x'], 'views' => [3, 4]]),
			'empty views'                        => $this->runScenario(['schema' => [], 'query' => [], 'views' => []]),
			'_source stripped'                   => $this->runScenario(['query' => ['_source' => 'index', 'a' => 1]]),
			'_source reaches views, then stripped' => $this->runScenario(['query' => ['_source' => 'index', 'a' => 1], 'views' => [9]]),
			'_source stripped after views'       => $this->runScenario(['query' => ['a' => 1], 'views' => [9], 'viewAdds' => ['_source' => 'index']]),
			'public read drops multitenancy'     => $this->runScenario(['schema' => ['read' => ['public']], 'query' => []]),
			'public read, multitenancy off'      => $this->runScenario(['schema' => ['read' => ['public']], 'query' => [], 'multi' => false]),
			'loose public is not public'         => $this->runScenario(['schema' => ['read' => [true, 0]], 'query' => []]),
			'group read keeps multitenancy'      => $this->runScenario(['schema' => ['read' => ['staff'], 'update' => ['public']], 'query' => []]),
			'group read, multitenancy off'       => $this->runScenario(['schema' => ['read' => ['staff']], 'query' => [], 'multi' => false]),
			'public read, no schema context'     => $this->runScenario(['query' => ['@self' => ['schema' => 10]]]),
			'flags passed through'               => $this->runScenario(['query' => [], 'rbac' => false, 'multi' => false, 'deleted' => true, 'ids' => ['a', 'b'], 'uses' => 'u-1']),
			'forged flags in the query'          => $this->runScenario(['schema' => [], 'query' => ['_rbac' => false, '_multitenancy' => false, 'userId' => 'alice', '_owner' => 'alice', 'owner' => 'alice', '@self' => ['owner' => 'alice']]]),
			'source kept from result'            => $this->runScenario(['query' => [], 'result' => ['results' => [], 'total' => 0, '@self' => ['source' => 'magic_mapper', 'x' => 1]]]),
			'index source'                       => $this->runScenario(['query' => ['_search' => 'q'], 'result' => ['results' => [1, 2], 'total' => 9, '@self' => ['source' => 'index']]]),
			'extend array'                       => $this->runScenario(['query' => ['_extend' => ['a']]]),
			'extend string with blanks'          => $this->runScenario(['query' => ['_extend' => ' a , ,b ']]),
			'extend empty string'                => $this->runScenario(['query' => ['_extend' => '']]),
			'extend only commas'                 => $this->runScenario(['query' => ['_extend' => ' , ']]),
			'extend zero string'                 => $this->runScenario(['query' => ['_extend' => '0']]),
			'extend empty array'                 => $this->runScenario(['query' => ['_extend' => []]]),
			'extend scalar'                      => $this->runScenario(['query' => ['_extend' => 5]]),
			'names from string'                  => $this->runScenario(['query' => ['_extend' => 'a,_names'], 'result' => ['results' => $rows, 'total' => 3]]),
			'names from array'                   => $this->runScenario(['query' => ['_extend' => ['_names']], 'result' => ['results' => $rows, 'total' => 3]]),
			'names, no results'                  => $this->runScenario(['query' => ['_extend' => '_names'], 'result' => ['results' => [], 'total' => 0]]),
			'names, results missing'             => $this->runScenario(['query' => ['_extend' => '_names'], 'result' => ['total' => 0]]),
			'names, results not an array'        => $this->runScenario(['query' => ['_extend' => '_names'], 'result' => ['results' => 'x', 'total' => 0]]),
			'names lookup fails'                 => $this->runScenario(['query' => ['_extend' => '_names'], 'result' => ['results' => $rows, 'total' => 3], 'namesFail' => true]),
			'names, no uuids'                    => $this->runScenario(['query' => ['_extend' => ['_names']], 'result' => ['results' => [['n' => 1]], 'total' => 1]]),
			'names needs strict match, true'     => $this->runScenario(['query' => ['_extend' => [true]], 'result' => ['results' => $rows, 'total' => 3]]),
			'names needs strict match'           => $this->runScenario(['query' => ['_extend' => [0]], 'result' => ['results' => $rows, 'total' => 3]]),
			'trail mode none'                    => $this->runScenario(['query' => ['_search' => 'q'], 'mode' => 'none']),
			'trail mode _search, no term'        => $this->runScenario(['query' => ['_search' => '  '], 'mode' => '_search']),
			'trail mode _search, term'           => $this->runScenario(['query' => ['_search' => ' q '], 'mode' => '_search']),
			'trail fails'                        => $this->runScenario(['query' => [], 'mode' => 'throw']),
			'source schema, allowed'             => $this->runScenario(['register' => true, 'schema' => ['source' => true], 'query' => ['_limit' => 2, 'userId' => 'mallory']]),
			'source schema, rbac off'            => $this->runScenario(['register' => true, 'schema' => ['source' => true], 'query' => [], 'rbac' => false, 'session' => 'mallory']),
			'source schema, forged user denied'  => $this->runScenario(['register' => true, 'schema' => ['source' => true], 'query' => ['userId' => 'alice', '_owner' => 'alice'], 'session' => 'mallory']),
			'source schema, no session'          => $this->runScenario(['register' => true, 'schema' => ['source' => true], 'query' => [], 'session' => null]),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Run one searchObjectsPaginated() call on a fresh service and record what happened.
	 *
	 * @param array<string, mixed> $setup The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $setup): array {
		$this->calls = [];
		$this->setup = $setup + ['session' => 'alice', 'mode' => 'all'];
		$service = $this->service();

		if (($setup['register'] ?? false) === true) {
			$register = new Register();
			$register->setId(1);
			$register->setSlug('register-1');
			$service->setRegister(register: $register);
		}

		if (isset($setup['schema']) === true) {
			$schema = new Schema();
			$schema->setId(10);
			$schema->setSlug('schema-10');
			$auth = $setup['schema'];
			if (($auth['source'] ?? false) === true) {
				unset($auth['source']);
				$schema->setConfiguration(['x-openregister-object-source' => ['provider' => 'ext', 'config' => ['c' => 1]]]);
			}

			$schema->setAuthorization($auth);
			$service->setSchema(schema: $schema);
		}

		try {
			$returned = $service->searchObjectsPaginated(
				query: $setup['query'],
				_rbac: ($setup['rbac'] ?? true),
				_multitenancy: ($setup['multi'] ?? true),
				deleted: ($setup['deleted'] ?? false),
				ids: ($setup['ids'] ?? null),
				uses: ($setup['uses'] ?? null),
				views: ($setup['views'] ?? null)
			);
		} catch (\Throwable $e) {
			$returned = ['exception' => [get_class($e), $e->getMessage()]];
		}

		return ['returned' => $returned, 'calls' => $this->calls];
	}//end runScenario()

	/**
	 * A service over recording doubles. The permission double grants only to the session user "alice".
	 *
	 * @return ObjectService
	 */
	private function service(): ObjectService {
		$queryHandler = $this->createMock(QueryHandler::class);
		$queryHandler->method('searchObjectsPaginatedDatabase')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true, bool $deleted = false, ?array $ids = null, ?string $uses = null): array {
				$this->calls[] = ['database' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'deleted' => $deleted, 'ids' => $ids, 'uses' => $uses];
				return ($this->setup['result'] ?? ['results' => [['id' => 1]], 'total' => 1, 'page' => 1]);
			}
		);

		$searchQueryHandler = $this->createMock(SearchQueryHandler::class);
		$searchQueryHandler->method('applyViewsToQuery')->willReturnCallback(
			function (array $query, array $viewIds): array {
				$this->calls[] = ['applyViews' => $viewIds, 'query' => $query];
				return $query + ['_viewFilter' => $viewIds] + ($this->setup['viewAdds'] ?? []);
			}
		);
		$searchQueryHandler->method('getEffectiveRecordingMode')->willReturnCallback(
			function (): string {
				$this->calls[] = ['recordingMode'];
				if ($this->setup['mode'] === 'throw') {
					throw new Exception('trail settings unavailable');
				}

				return $this->setup['mode'];
			}
		);
		$searchQueryHandler->method('logSearchTrail')->willReturnCallback(
			function (array $_query, int $_resultCount, int $_totalResults, float $_executionTime, string $_executionType = 'sync'): void {
				$this->calls[] = ['trail' => $_query, 'count' => $_resultCount, 'total' => $_totalResults, 'type' => $_executionType, 'timeIsNonNegative' => $_executionTime >= 0];
			}
		);

		$renderHandler = $this->createMock(RenderObject::class);
		$renderHandler->method('getObjectsCache')->willReturnCallback(
			function (): array {
				$this->calls[] = ['objectsCache'];
				return ['o-1' => ['title' => 'extended']];
			}
		);

		$cacheHandler = $this->createMock(CacheHandler::class);
		$cacheHandler->method('getMultipleObjectNames')->willReturnCallback(
			function (array $identifiers): array {
				$this->calls[] = ['names' => array_values($identifiers)];
				if (($this->setup['namesFail'] ?? false) === true) {
					throw new Exception('name cache down');
				}

				return array_fill_keys($identifiers, 'Name');
			}
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				if ($this->setup['session'] === null) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->setup['session']);
				return $user;
			}
		);

		$permissionHandler = $this->createMock(PermissionHandler::class);
		$permissionHandler->method('checkPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, $object = null) use ($userSession): void {
				$acting = ($userId ?? $userSession->getUser()?->getUID());
				$this->calls[] = ['checkPermission' => $action, 'schema' => $schema->getId(), 'userId' => $userId, 'acting' => $acting, 'owner' => $objectOwner, 'rbac' => $_rbac];
				if ($_rbac === true && $acting !== 'alice') {
					throw new NotAuthorizedException('read denied for ' . (string) $acting);
				}
			}
		);

		$objectSourceRegistry = $this->createMock(ObjectSourceRegistry::class);
		$objectSourceRegistry->method('get')->willReturnCallback(
			function (string $provider) {
				$this->calls[] = ['objectSource.get' => $provider];
				return null;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line'], $context['trace']);
					$message = preg_replace('/ at \S+:\d+$/', ' at FILE:LINE', (string) $message);
					$this->calls[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		return new ObjectService(
			dataManipHandler: $this->createMock(DataManipulationHandler::class),
			deleteHandler: $this->createMock(DeleteObject::class),
			getHandler: $this->createMock(GetObject::class),
			permissionHandler: $permissionHandler,
			renderHandler: $renderHandler,
			saveHandler: $this->createMock(SaveObject::class),
			saveObjectsHandler: $this->createMock(SaveObjects::class),
			searchQueryHandler: $searchQueryHandler,
			validateHandler: $this->createMock(ValidateObject::class),
			lockHandler: $this->createMock(LockHandler::class),
			auditHandler: $this->createMock(AuditHandler::class),
			relationHandler: $this->createMock(RelationHandler::class),
			mergeHandler: $this->createMock(MergeHandler::class),
			facetHandler: $this->createMock(FacetHandler::class),
			metadataHandler: $this->createMock(MetadataHandler::class),
			perfOptHandler: $this->createMock(PerformanceOptimizationHandler::class),
			queryHandler: $queryHandler,
			revertHandler: $this->createMock(RevertHandler::class),
			utilityHandler: $this->createMock(UtilityHandler::class),
			validationHandler: $this->createMock(ValidationHandler::class),
			cascadingHandler: $this->createMock(CascadingHandler::class),
			migrationHandler: $this->createMock(MigrationHandler::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			viewMapper: $this->createMock(ViewMapper::class),
			objectMapper: $this->createMock(MagicMapper::class),
			fileService: $this->createMock(FileService::class),
			userSession: $userSession,
			searchTrailService: $this->createMock(SearchTrailService::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			organisationService: $this->createMock(OrganisationService::class),
			logger: $logger,
			cacheHandler: $cacheHandler,
			settingsService: $this->createMock(SettingsService::class),
			dateTimeNormalizer: $this->createMock(DateTimeNormalizer::class),
			container: $this->createMock(IAppContainer::class),
			objectSourceRegistry: $objectSourceRegistry
		);
	}//end service()
}//end class

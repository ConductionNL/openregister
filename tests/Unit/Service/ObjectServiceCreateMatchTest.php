<?php

/**
 * A create rule with a data match is evaluated against the object being created (openregister#4094).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
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
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\ObjectServiceFactory;

/**
 * The create check sees the incoming object, so a `match` on it can pass.
 *
 * Before openregister#4094 `checkSavePermissions()` asked the create question
 * with no object, the match was evaluated against nothing, and every non-admin
 * create on a schema whose create rule carries a match was refused with 403.
 * The permission handler and the condition matcher here are the REAL ones.
 */
class ObjectServiceCreateMatchTest extends TestCase {

	private ObjectService $objectService;

	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['planners']);

		$register = new Register();
		$register->setId(10);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getFirstRegisterWithSchema')->willReturn(10);
		$registerMapper->method('find')->willReturn($register);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($registerMapper) {
				if ($class === RegisterMapper::class) {
					return $registerMapper;
				}

				throw new \RuntimeException('Not available: ' . $class);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => $default
		);

		$logger = new NullLogger();
		$conditionMatcher = new ConditionMatcher(new ConditionUserResolvers($userSession), $container, new OperatorEvaluator($logger), $logger);

		$permissionHandler = \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$conditionMatcher,
			$appConfig,
			$logger,
			$container
		);

		$this->objectService = new ObjectService(...ObjectServiceFactory::args(
			$this->createMock(DataManipulationHandler::class),
			$this->createMock(DeleteObject::class),
			$this->createMock(GetObject::class),
			$permissionHandler,
			$this->createMock(RenderObject::class),
			$this->createMock(SaveObject::class),
			$this->createMock(SaveObjects::class),
			$this->createMock(SearchQueryHandler::class),
			$this->createMock(ValidateObject::class),
			$this->createMock(LockHandler::class),
			$this->createMock(AuditHandler::class),
			$this->createMock(RelationHandler::class),
			$this->createMock(MergeHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(MetadataHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->createMock(QueryHandler::class),
			$this->createMock(RevertHandler::class),
			$this->createMock(UtilityHandler::class),
			$this->createMock(ValidationHandler::class),
			$this->createMock(CascadingHandler::class),
			$this->createMock(MigrationHandler::class),
			$registerMapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(ViewMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(FileService::class),
			$userSession,
			$this->createMock(SearchTrailService::class),
			$groupManager,
			$userManager,
			$this->createMock(OrganisationService::class),
			$logger,
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(DateTimeNormalizer::class),
			$this->createMock(IAppContainer::class),
			$this->createMock(ObjectSourceRegistry::class)
		));

		// planninq's plannedTimeEntry: a member books time for themselves.
		$schema = new Schema();
		$schema->setId(681);
		$schema->setTitle('Planned time entry');
		$schema->setAuthorization(
			[
				'create' => [['group' => 'planners', 'match' => ['user' => '$userId']]],
				'read' => ['planners'],
			]
		);
		$this->objectService->setSchema($schema);
	}//end setUp()

	/**
	 * Ask the create question for this incoming object.
	 *
	 * @param array<string, mixed> $object The incoming object data.
	 *
	 * @return void
	 */
	private function checkCreate(array $object): void {
		$method = new ReflectionMethod(ObjectService::class, 'checkSavePermissions');
		$method->setAccessible(true);
		$method->invoke($this->objectService, null, true, $object);
	}//end checkCreate()

	/**
	 * A member creating an entry that names themselves is allowed.
	 *
	 * @return void
	 */
	public function testACreateThatSatisfiesTheMatchIsAllowed(): void {
		$this->expectNotToPerformAssertions();

		$this->checkCreate(['user' => 'alice', 'hours' => 4]);

	}//end testACreateThatSatisfiesTheMatchIsAllowed()

	/**
	 * A member creating an entry for someone else is still refused.
	 *
	 * @return void
	 */
	public function testACreateThatFailsTheMatchIsRefused(): void {
		$this->expectException(NotAuthorizedException::class);

		$this->checkCreate(['user' => 'bob', 'hours' => 4]);
	}//end testACreateThatFailsTheMatchIsRefused()

	/**
	 * A `@self` block in the request cannot supply what the match reads.
	 *
	 * @return void
	 */
	public function testAForgedSelfBlockDoesNotSatisfyTheMatch(): void {
		$this->expectException(NotAuthorizedException::class);

		$this->checkCreate(['hours' => 4, '@self' => ['user' => 'alice']]);
	}//end testAForgedSelfBlockDoesNotSatisfyTheMatch()
	/**
	 * A metadata key in the request body cannot supply what a create rule's
	 * match reads: `_owner` and `_organisation` are set by the system.
	 *
	 * @return void
	 */
	public function testABodyMetadataKeyDoesNotSatisfyTheMatch(): void {
		foreach (['_owner' => 'alice', '_organisation' => 'org-target'] as $field => $value) {
			$schema = new Schema();
			$schema->setId(683);
			$schema->setTitle('Guarded');
			$schema->setAuthorization(['create' => [['group' => 'planners', 'match' => [$field => $value]]], 'read' => ['planners']]);
			$this->objectService->setSchema(schema: $schema);

			try {
				$this->checkCreate(object: ['title' => 'x', $field => $value]);
				$this->fail(message: 'a body ' . $field . ' satisfied the create rule');
			} catch (NotAuthorizedException $e) {
				$this->addToAssertionCount(count: 1);
			}
		}
	}//end testABodyMetadataKeyDoesNotSatisfyTheMatch()

	/**
	 * The transient record the create rule reads holds the body without its `@self` block or metadata keys.
	 *
	 * Listeners of a custom scope receive this record, so the strip must hold on the record
	 * itself, not only in what the matcher reads.
	 *
	 * @return void
	 */
	public function testTheCreateCheckRecordCarriesNoSelfBlock(): void {
		$method = new ReflectionMethod(ObjectService::class, 'buildIncomingObjectForCreateCheck');
		$incoming = $method->invoke(
			$this->objectService,
			['title' => 't', '@self' => ['organisation' => 'org-a', 'owner' => 'alice'], '_owner' => 'alice', 0 => 'n']
		);

		$this->assertSame(expected: ['title' => 't', 0 => 'n'], actual: array_diff_key($incoming->getObject(), ['id' => true]));
		$this->assertNull(actual: $incoming->getOwner());
	}//end testTheCreateCheckRecordCarriesNoSelfBlock()

	/**
	 * Ordinary properties still answer a create rule after the metadata keys are dropped.
	 *
	 * @return void
	 */
	public function testOrdinaryPropertiesBesideAMetadataKeyStillCount(): void {
		$this->expectNotToPerformAssertions();

		$this->checkCreate(object: ['user' => 'alice', '_owner' => 'bob', 'hours' => 4]);

	}//end testOrdinaryPropertiesBesideAMetadataKeyStillCount()

	/**
	 * A schema whose default scope is private still takes creates.
	 *
	 * The incoming data is not an object yet, so the private scope does not
	 * gate it; before the create check saw the data it was never gated either.
	 *
	 * @return void
	 */
	public function testAPrivateDefaultScopeDoesNotBlockACreate(): void {
		$this->expectNotToPerformAssertions();

		$schema = new Schema();
		$schema->setId(682);
		$schema->setTitle('Private note');
		$schema->setAuthorization(
			[
				'scope' => 'private',
				'create' => ['planners'],
				'read' => ['planners'],
			]
		);
		$this->objectService->setSchema($schema);

		$this->checkCreate(['title' => 'mine']);

	}//end testAPrivateDefaultScopeDoesNotBlockACreate()
}//end class

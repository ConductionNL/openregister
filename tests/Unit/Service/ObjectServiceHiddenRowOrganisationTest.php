<?php

/**
 * A save by uuid onto a stored row the caller cannot read keeps to the caller's organisations.
 *
 * The caller's own lookup does not return another organisation's row, so the
 * write is judged as a create, and the stored row it would land on by the
 * update rule. Neither rule looks at organisations, so the row is refused
 * when the organisation filter alone does not return it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
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

/**
 * The create check sees the incoming object, so a `match` on it can pass.
 *
 * Before openregister#4094 `checkSavePermissions()` asked the create question
 * with no object, the match was evaluated against nothing, and every non-admin
 * create on a schema whose create rule carries a match was refused with 403.
 * The permission handler and the condition matcher here are the REAL ones.
 */
/**
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Service\Object\SaveAccessRequest
 * @uses \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses \OCA\OpenRegister\Service\Object\ObjectSaveRun
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 * @uses \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses \OCA\OpenRegister\Service\ConditionMatcher
 * @uses \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 */
class ObjectServiceHiddenRowOrganisationTest extends TestCase {

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

		$this->objectService = new ObjectService(
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
			$this->cascadingHandler(),
			$this->createMock(MigrationHandler::class),
			$registerMapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(ViewMapper::class),
			$this->objectMapper(),
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
		);

		// planninq's plannedTimeEntry: a member books time for themselves.
		$schema = new Schema();
		$schema->setId(681);
		$schema->setTitle('Planned time entry');
		$schema->setAuthorization(['create' => ['planners'], 'update' => ['planners'], 'read' => ['planners']]);
		$this->objectService->setSchema($schema);
	}//end setUp()

	/**
	 * What a save that got past the permission check ends in.
	 *
	 * @var string
	 */
	private const PAST_THE_CHECK = 'past the permission check';

	/**
	 * A cascading handler that ends the save, the first step after the permission check.
	 *
	 * @return CascadingHandler The handler.
	 */
	private function cascadingHandler(): CascadingHandler {
		$handler = $this->createMock(CascadingHandler::class);
		$handler->method('handlePreValidationCascading')->willThrowException(
			new \RuntimeException(self::PAST_THE_CHECK)
		);
		return $handler;
	}//end cascadingHandler()

	/**
	 * The scope flags of each lookup, as [rbac, multitenancy].
	 *
	 * @var array<int, array{0: bool, 1: bool}>
	 */
	private array $lookups = [];

	/**
	 * The rows whose organisation was compared with the caller's scope.
	 *
	 * @var array<int, string|null>
	 */
	private array $organisationChecks = [];

	/**
	 * Where the stored row is visible: 'caller' (the caller's own lookup), 'organisation' (inside the caller's
	 * organisations, hidden by the read rules), 'lifted' (another organisation's row that a lookup with the
	 * organisation filter alone returns, because a `public` read rule lifts that filter) or 'none'.
	 */
	private string $visibleTo = 'none';

	/**
	 * The stored row.
	 */
	private ?ObjectEntity $stored = null;

	/**
	 * The object mapper: the stored row comes back only as far as `$visibleTo` says.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function objectMapper(): MagicMapper {
		$this->stored = new ObjectEntity();
		$this->stored->setUuid('uuid-b');
		$this->stored->setOrganisation('org-b');
		$this->stored->setOwner('bob');
		$this->stored->setObject(['title' => 'B']);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('find')->willReturnCallback(
			function (string|int $identifier, $register = null, $schema = null, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->lookups[] = [$_rbac, $_multitenancy];
				$found = match (true) {
					$_rbac === false && $_multitenancy === false => true,
					$_rbac === false && $_multitenancy === true => in_array($this->visibleTo, ['caller', 'organisation', 'lifted'], true),
					default => $this->visibleTo === 'caller',
				};
				if ($found === true) {
					return $this->stored;
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('not in scope');
			}
		);
		$mapper->method('callerOrganisationsHold')->willReturnCallback(
			function (ObjectEntity $row): bool {
				$this->organisationChecks[] = $row->getUuid();
				return in_array($this->visibleTo, ['caller', 'organisation'], true);
			}
		);
		return $mapper;
	}//end objectMapper()

	/**
	 * Ask the save question for a write by uuid.
	 *
	 * @param bool $multitenancy Whether the write keeps to the caller's organisations.
	 *
	 * @return void
	 */
	private function checkSave(bool $multitenancy = true): void {
		$method = new ReflectionMethod(ObjectService::class, 'checkSavePermissions');
		$method->setAccessible(true);
		$method->invoke($this->objectService, 'uuid-b', true, ['title' => 'overwritten'], $multitenancy);
	}//end checkSave()

	public function testAWriteOntoAnotherOrganisationsRowIsRefused(): void {
		$this->expectException(NotAuthorizedException::class);
		$this->checkSave();
	}

	public function testTheHiddenRowsOwnOrganisationIsCompared(): void {
		try {
			$this->checkSave();
		} catch (NotAuthorizedException) {
		}

		$this->assertSame(['uuid-b'], $this->organisationChecks);
	}

	public function testARowTheReadRulesHideInsideTheOrganisationIsLeftToTheUpdateRule(): void {
		$this->visibleTo = 'organisation';

		$this->checkSave();

		$this->assertSame(['uuid-b'], $this->organisationChecks);
	}

	public function testARowAPublicReadRuleLiftsTheOrganisationFilterForIsStillRefused(): void {
		$this->visibleTo = 'lifted';

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('You may not change this object.');
		$this->checkSave();
	}

	public function testAWriteThatDoesNotKeepToOrganisationsIsNotRefusedForIt(): void {
		$this->checkSave(multitenancy: false);

		$this->assertSame([], $this->organisationChecks);
	}

	public function testARowTheCallerCanReadIsAnOrdinaryUpdate(): void {
		$this->visibleTo = 'caller';

		$this->checkSave();

		$this->assertSame([], $this->organisationChecks);
	}

	/**
	 * What a full save by uuid ends in: the refusal, or the step after the permission check.
	 *
	 * @param bool $multitenancy Whether the save keeps to the caller's organisations.
	 *
	 * @return string|null The message the save ended with.
	 */
	private function saveRefusal(bool $multitenancy): ?string {
		$schema = new Schema();
		$schema->setId(681);
		$schema->setAuthorization(['create' => ['planners'], 'update' => ['planners'], 'read' => ['planners']]);
		$register = new Register();
		$register->setId(10);

		try {
			$this->objectService->saveObject(
				object: ['title' => 'overwritten'],
				register: $register,
				schema: $schema,
				uuid: 'uuid-b',
				_rbac: true,
				_multitenancy: $multitenancy
			);
		} catch (NotAuthorizedException|\RuntimeException $ended) {
			return $ended->getMessage();
		}

		return null;
	}//end saveRefusal()

	public function testASaveCarriesItsMultitenancyIntoTheCheck(): void {
		$this->assertSame('You may not change this object.', $this->saveRefusal(multitenancy: true));
		$this->assertSame(self::PAST_THE_CHECK, $this->saveRefusal(multitenancy: false));
	}

	public function testAnotherOrganisationsRowIsRefusedBeforeTheUpdateRuleIsAsked(): void {
		$this->expectExceptionMessage('You may not change this object.');
		$this->checkSave();
	}
}

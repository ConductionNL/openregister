<?php

/**
 * On an append-only schema a write by uuid onto any stored row gets one answer.
 *
 * A stored row the caller cannot see, in their own organisation or another,
 * gets the same 405 as a visible one, before the organisation refusal and the
 * update rule are asked, so neither tells which rows belong to another
 * organisation. A soft-deleted row counts as stored.
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
use OCA\OpenRegister\Exception\AppendOnlyException;
use OCA\OpenRegister\Db\Organisation;
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
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\ObjectServiceFactory;

/**
 * The permission handler and the condition matcher here are the REAL ones.
 *
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
 * @uses \OCA\OpenRegister\Exception\AppendOnlyException
 * @uses \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 */
class ObjectServiceAppendOnlyHiddenRowTest extends TestCase {

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
		));


		$this->objectService->setSchema($this->schema([]));
	}//end setUp()

	private const PAST = 'past the permission check';
	private const AO = 'SCHEMA_APPEND_ONLY: Schema "statements" is append-only; update operations are not permitted.';

	private function cascadingHandler(): CascadingHandler {
		$handler = $this->createMock(CascadingHandler::class);
		$handler->method('handlePreValidationCascading')->willThrowException(new \RuntimeException(self::PAST));
		return $handler;
	}

	/**
	 * The scope flags of each lookup, as [rbac, multitenancy].
	 *
	 * @var array<int, array{0: bool, 1: bool}>
	 */
	public array $lookups = [];

	/**
	 * Where the stored row is visible: 'none' (another organisation, hidden), 'organisation' (own organisation,
	 * hidden by the read rules), 'caller' (visible), 'deleted' (soft-deleted, another organisation) or 'absent'.
	 */
	public string $visibleTo = 'none';

	/**
	 * The stored row.
	 */
	public ?ObjectEntity $stored = null;

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
				if ($this->visibleTo === 'absent' || ($this->visibleTo === 'deleted' && $includeDeleted === false)) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('absent');
				}
				$found = match (true) {
					$_rbac === false && $_multitenancy === false => true,
					$_rbac === false && $_multitenancy === true => in_array($this->visibleTo, ['caller', 'organisation'], true),
					default => $this->visibleTo === 'caller',
				};
				if ($found === true) {
					return $this->stored;
				}
				throw new \OCP\AppFramework\Db\DoesNotExistException('not in scope');
			}
		);
		$mapper->method('callerOrganisationsHold')->willReturnCallback(
			fn (ObjectEntity $row): bool => in_array($this->visibleTo, ['caller', 'organisation'], true)
		);
		return $mapper;
	}

	private function schema(array $auth): Schema {
		$schema = new Schema();
		$schema->setId(681);
		$schema->setTitle('Statements');
		$schema->setSlug('statements');
		$schema->setAppendOnly(true);
		$schema->setHardValidation(false);
		$schema->setAuthorization($auth);
		return $schema;
	}

	private function answer(array $auth, string $visibleTo, bool $multitenancy = true, ?string $storedOrg = null, bool $rbac = true): string {
		$this->visibleTo = $visibleTo;
		if ($storedOrg !== null) {
			$this->stored->setOrganisation($storedOrg);
		}
		$register = new Register();
		$register->setId(10);
		try {
			$this->objectService->saveObject(
				object: ['title' => 'overwritten'],
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $register, schema: $this->schema($auth)),
					uuid: 'uuid-b'
				),
				_rbac: $rbac,
				_multitenancy: $multitenancy
			);
		} catch (AppendOnlyException $e) {
			return '405 ' . $e->getMessage();
		} catch (NotAuthorizedException $e) {
			return '403 ' . $e->getMessage();
		} catch (\RuntimeException $e) {
			return 'PASS ' . $e->getMessage();
		}
		return 'SAVED';
	}

	private const FULL = ['create' => ['planners'], 'update' => ['planners'], 'read' => ['planners']];

	public function testAnotherOrganisationsHiddenRowGetsTheSingle405(): void {
		$this->assertSame('405 ' . self::AO, $this->answer(self::FULL, 'none'));
	}

	public function testAHiddenRowOfTheOwnOrganisationGetsTheSame405(): void {
		$this->assertSame('405 ' . self::AO, $this->answer(self::FULL, 'organisation'));
	}

	public function testAVisibleRowGetsTheSame405(): void {
		$this->assertSame('405 ' . self::AO, $this->answer(self::FULL, 'caller'));
	}

	public function testAnUnusedUuidIsAnInsert(): void {
		$this->assertSame('PASS ' . self::PAST, $this->answer(self::FULL, 'absent'));
	}

	public function testAWriteThatDoesNotKeepToOrganisationsIsStillRefused(): void {
		$this->assertSame('405 ' . self::AO, $this->answer(self::FULL, 'none', multitenancy: false));
	}

	public function testWithoutUpdateRightsBothHiddenKindsAnswerAlike(): void {
		$auth = ['create' => ['planners'], 'read' => ['planners'], 'update' => ['editors']];
		$foreign = $this->answer($auth, 'none');
		$same = $this->answer($auth, 'organisation');
		$visible = $this->answer($auth, 'caller');

		$this->assertSame('405 ' . self::AO, $foreign);
		$this->assertSame($foreign, $same);
		$this->assertStringStartsWith('403 ', $visible);
	}

	public function testAnUpdateRulePinnedToTheOrganisationDoesNotTellRowsApart(): void {
		$auth = ['create' => ['planners'], 'read' => ['planners'], 'update' => [['group' => 'planners', 'match' => ['_organisation' => 'org-a']]]];
		$foreign = $this->answer($auth, 'none', storedOrg: 'org-b');
		$same = $this->answer($auth, 'organisation', storedOrg: 'org-a');

		$this->assertSame('405 ' . self::AO, $foreign);
		$this->assertSame($foreign, $same);
	}

	public function testASoftDeletedForeignRowGetsTheSame405(): void {
		$this->assertSame('405 ' . self::AO, $this->answer(self::FULL, 'deleted'));
	}

	public function testASoftDeletedRowGetsThe405AlsoWithRbacOff(): void {
		$this->assertSame('405 ' . self::AO, $this->answer(self::FULL, 'deleted', rbac: false, multitenancy: false));
	}

	public function testWithoutCreateRightsBothHiddenKindsAnswerAlike(): void {
		$auth = ['update' => ['planners'], 'read' => ['planners'], 'create' => ['editors']];
		$foreign = $this->answer($auth, 'none');
		$same = $this->answer($auth, 'organisation');
		$visible = $this->answer($auth, 'caller');
		$this->assertSame($foreign, $same);
		$this->assertStringStartsWith('405', $visible);
	}

	public function testNoWriteReachesTheSaveForAnyStoredRow(): void {
		foreach (['none', 'organisation', 'caller'] as $v) {
			foreach ([self::FULL, ['create' => ['planners'], 'update' => ['planners']]] as $auth) {
				$this->assertStringStartsNotWith('PASS', $this->answer($auth, $v), $v);
			}
		}
	}
}

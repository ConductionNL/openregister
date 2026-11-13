<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A bulk save or import keeps to the caller's organisations, per row, before any write.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SafeguardBatchRun;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * An org-A caller with a group update rule sends another organisation's row by uuid. The
 * permission handler and the condition matcher are the REAL ones; the mapper says which
 * organisation scope holds the stored row and whether the caller's own read finds it.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses \OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectDefaults
 * @uses \OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectOwnership
 * @covers \OCA\OpenRegister\Service\Object\BulkSafeguardRun
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\BulkObjectSlugs
 * @uses   \OCA\OpenRegister\Service\ConditionMatcher
 * @uses   \OCA\OpenRegister\Service\OperatorEvaluator
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Service\ConditionUserResolvers
 * @uses   \OCA\OpenRegister\Service\Object\BulkRowChecks
 * @uses   \OCA\OpenRegister\Service\Object\BulkSaveReporting
 * @uses   \OCA\OpenRegister\Service\Object\BulkSaveSession
 * @uses   \OCA\OpenRegister\Service\Object\BulkSaveStores
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses   \OCA\OpenRegister\Service\Object\DraftCaller
 * @uses   \OCA\OpenRegister\Service\Object\DraftSubject
 * @uses   \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses   \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses   \OCA\OpenRegister\Service\Object\SafeguardBatchRun
 * @uses   \OCA\OpenRegister\Service\Object\SafeguardRejection
 * @uses   \OCA\OpenRegister\Service\Rbac\MaskedWrite
 * @uses   \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses   \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 * @uses   \OCA\OpenRegister\Service\Rbac\UndeclaredPropertyConditions
 * @uses   \OCA\OpenRegister\Db\EmptyJsonHydration
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Service\Object\BulkCreateCheck
 * @uses   \OCA\OpenRegister\Service\Object\ObjectUpdateOptions
 * @uses   \OCA\OpenRegister\Service\Object\RelationDetectionTrait
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\BulkSlugCaller
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\RelationScanLevel
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkChunkRun
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkInverseRelations
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkRelationScanner
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkSchemaAnalysis
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\IdentifiedRow
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\PersistedChunk
 */
class SaveObjectsOrganisationBoundaryTest extends TestCase {

	private const FOREIGN = '22222222-2222-2222-2222-222222222222';

	/**
	 * Whether the caller's organisation scope holds the stored row.
	 */
	private bool $held = false;

	/**
	 * What the caller's own read finds: true (the stored row), false (nothing), 'throws', or
	 * 'other' (an own-organisation row whose slug or id equals the stored row's uuid).
	 */
	private bool|string $readable = false;

	/**
	 * Whether the batch prefetch fails, so each row is looked up on its own.
	 */
	private bool $prefetchFails = false;

	/**
	 * What the per-row lookup past the read filters answers after a failed prefetch:
	 * 'stored', 'missing', 'ambiguous' or 'fails'.
	 */
	private string $fallback = 'stored';

	/**
	 * The stored row's status.
	 */
	private ?string $storedStatus = null;

	/**
	 * Whether the caller is an administrator.
	 */
	private bool $admin = false;

	/**
	 * The schema's update rules.
	 *
	 * @var array<int, mixed>
	 */
	private array $updateRules = ['planners'];

	/**
	 * The stored row's organisation.
	 */
	private ?string $storedOrganisation = 'org-b';

	/**
	 * The rows the organisation scope was asked about, and the caller-scoped reads.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $propName) {
			$prop = $ref->getProperty($propName);
			$prop->setAccessible(true);
			$prop->setValue(null, []);
		}
	}//end setUp()

	/**
	 * The bulk handler for alice of org-a, a planner, over one stored row of org-b.
	 *
	 * @return SaveObjects
	 */
	private function handler(): SaveObjects {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['planners']);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => ($uid === 'alice' && $this->admin));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(static fn (string $a, string $k, bool $d = false): bool => $d);
		$container = $this->createMock(ContainerInterface::class);
		$logger = new NullLogger();
		$permissionHandler = \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			new ConditionMatcher(new ConditionUserResolvers($userSession), $container, new OperatorEvaluator($logger), $logger),
			$appConfig,
			$logger,
			$container
		);

		$stored = new ObjectEntity();
		$stored->setUuid(self::FOREIGN);
		$stored->setOrganisation($this->storedOrganisation);
		$stored->setOwner('bob');
		$stored->setObject(['title' => 'B']);
		$stored->setStatus($this->storedStatus);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findObjectsByUuidsInRegisterSchema')->willReturnCallback(
			function () use ($stored): array {
				if ($this->prefetchFails === true) {
					throw new \RuntimeException('prefetch failed');
				}

				return [self::FOREIGN => $stored];
			}
		);
		$mapper->method('callerOrganisationsHold')->willReturnCallback(
			function (ObjectEntity $row): bool {
				$this->calls[] = 'hold '.$row->getUuid().' '.$row->getOrganisation();
				return $this->held;
			}
		);
		$mapper->method('find')->willReturnCallback(
			function (string|int $identifier, $register = null, $schema = null, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true) use ($stored): ObjectEntity {
				$this->calls[] = 'find '.json_encode([$identifier, $register?->getId(), $schema?->getId(), $_rbac, $_multitenancy]);
				if ($_rbac === false) {
					return match ($this->fallback) {
						'stored' => $stored,
						'missing' => throw new DoesNotExistException('missing'),
						'ambiguous' => throw new \OCP\AppFramework\Db\MultipleObjectsReturnedException('two rows'),
						default => throw new \RuntimeException('lookup failed'),
					};
				}

				if ($this->readable === 'throws') {
					throw new \RuntimeException('database gone');
				}

				if ($this->readable === 'other') {
					$own = new ObjectEntity();
					$own->setUuid('own-4');
					$own->setSlug(self::FOREIGN);
					$own->setOrganisation('org-a');
					return $own;
				}

				if ($this->readable === true) {
					return $stored;
				}

				throw new DoesNotExistException('not in scope');
			}
		);
		$orgs = $this->createMock(OrganisationService::class);
		$orgs->method('getOrganisationForNewEntity')->willReturn('org-a');

		return new SaveObjects(
			stores: new BulkSaveStores(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $mapper), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $userSession, organisationService: $orgs, groupManager: $groupManager),
			reporting: new BulkSaveReporting(logger: $logger),
			checks: new BulkRowChecks(permissionHandler: $permissionHandler, validateHandler: $this->createMock(ValidateObject::class))
		);
	}//end handler()

	/**
	 * The schema planners create, update and read in.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(681);
		$schema->setSlug('planned');
		$schema->setTitle('planned');
		$schema->setAuthorization(['create' => ['planners'], 'update' => $this->updateRules, 'read' => ['planners']]);
		return $schema;
	}//end schema()

	/**
	 * The register the schema lives in.
	 *
	 * @return Register
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(10);
		$register->setConfiguration([]);
		return $register;
	}//end register()

	/**
	 * Run the safeguards over one row.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param array<string, bool>  $flags The caller's flags.
	 *
	 * @return array{passed: array, invalid: array}
	 */
	private function safeguard(array $row, array $flags = ['rbac' => true, 'validation' => false, 'multitenancy' => true]): array {
		$result = ['invalid' => [], 'statistics' => ['invalid' => 0, 'errors' => 0]];
		$method = new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards');
		$method->setAccessible(true);
		$batch  = new SafeguardBatchRun(objects: [$row], defaults: new BulkBatchDefaults($this->register(), $this->schema()), flags: $flags, result: $result);
		$passed = $method->invokeArgs($this->handler(), [$batch]);
		$result = $batch->result;

		return ['passed' => $passed, 'invalid' => $result['invalid']];
	}//end safeguard()

	/**
	 * The reasons the safeguards gave for refused rows.
	 *
	 * @param array $invalid The invalid bucket.
	 *
	 * @return array<int, string>
	 */
	private static function reasons(array $invalid): array {
		return array_map(static fn (array $entry): string => (string) ($entry['error'] ?? ''), $invalid);
	}//end reasons()

	public function testAnUpdateOntoAnotherOrganisationsRowIsRefused(): void {
		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten']);

		$this->assertSame([], $out['passed']);
		$this->assertSame(['Permission denied for action create on schema planned'], self::reasons($out['invalid']));
		$this->assertSame(['hold '.self::FOREIGN.' org-b'], $this->calls);
	}

	public function testTheRowIsRefusedWhicheverIdentityKeyNamesIt(): void {
		$byId = $this->safeguard(row: ['id' => self::FOREIGN, 'title' => 'overwritten']);
		$bySelfId = $this->safeguard(row: ['@self' => ['id' => self::FOREIGN], 'title' => 'overwritten']);

		$this->assertSame([], $byId['passed']);
		$this->assertSame([], $bySelfId['passed']);
	}

	public function testAnOrganisationTheRowClaimsDoesNotCount(): void {
		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN, 'organisation' => 'org-a', 'owner' => 'alice'], 'organisation' => 'org-a', 'title' => 'overwritten']);

		$this->assertSame([], $out['passed']);
		$this->assertSame(['hold '.self::FOREIGN.' org-b'], $this->calls);
	}

	public function testARowInsideTheCallersOrganisationsIsLeftToTheUpdateRule(): void {
		$this->held = true;

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'changed']);

		$this->assertCount(1, $out['passed']);
		$this->assertSame(['hold '.self::FOREIGN.' org-b'], $this->calls);
	}

	public function testAnotherOrganisationsRowTheCallerReadsIsRefusedWithoutAMarkedUpdateRule(): void {
		$this->readable = true;

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'changed']);

		$this->assertSame([], $out['passed']);
		$this->assertSame(['Permission denied for action create on schema planned'], self::reasons($out['invalid']));
	}

	public function testAMarkedUpdateRuleReachesAnotherOrganisationsRowTheCallerReads(): void {
		$this->readable = true;
		$this->updateRules = [['group' => 'planners', 'crossOrganisation' => true]];

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'changed']);

		$this->assertCount(1, $out['passed']);
	}

	public function testAMarkedUpdateRuleOfAnotherGroupDoesNotReachTheRow(): void {
		$this->readable = true;
		$this->updateRules = ['planners', ['group' => 'central-team', 'crossOrganisation' => true]];

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'changed']);

		$this->assertSame([], $out['passed']);
	}

	public function testAMarkedUpdateRuleStillNeedsTheCallerToReadTheRow(): void {
		$this->updateRules = [['group' => 'planners', 'crossOrganisation' => true]];

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'changed']);

		$this->assertSame([], $out['passed']);
	}

	public function testARowWithoutOrganisationTheCallerReadsIsLeftToTheUpdateRule(): void {
		$this->readable = true;
		$this->storedOrganisation = null;

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'changed']);

		$this->assertCount(1, $out['passed']);
	}

	public function testAReadableOwnRowWhoseSlugIsTheStoredUuidDoesNotCountAsReadingIt(): void {
		$this->updateRules = [['group' => 'planners', 'crossOrganisation' => true]];
		$this->readable = 'other';

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten']);

		$this->assertSame([], $out['passed']);
		$this->assertSame(['Permission denied for action create on schema planned'], self::reasons($out['invalid']));
	}

	public function testAReadableOwnRowWhoseIdIsTheStoredNumericUuidDoesNotCountAsReadingIt(): void {
		$this->updateRules = [['group' => 'planners', 'crossOrganisation' => true]];
		$this->readable = 'other';
		$this->prefetchFails = true;

		$out = $this->safeguard(row: ['id' => '9', 'title' => 'overwritten']);

		$this->assertSame([], $out['passed']);
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function fallbacks(): array {
		return [
			'an ambiguous lookup' => ['ambiguous', 0],
			'a failed lookup' => ['fails', 0],
			'a missing row is a create' => ['missing', 1],
		];
	}//end fallbacks()

	/**
	 * @dataProvider fallbacks
	 */
	public function testAfterAFailedPrefetchOnlyAMissingRowIsACreate(string $fallback, int $passed): void {
		$this->prefetchFails = true;
		$this->fallback = $fallback;

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'x']);

		$this->assertCount($passed, $out['passed']);
		if ($passed === 0) {
			$this->assertSame(['Permission denied for action create on schema planned'], self::reasons($out['invalid']));
		}
	}

	public function testAnUnresolvedRowIsRefusedAlsoForAnAdministratorWithRbacOff(): void {
		$this->admin = true;
		$this->prefetchFails = true;
		$this->fallback = 'ambiguous';

		$out = $this->safeguard(
			row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'x'],
			flags: ['rbac' => false, 'validation' => false, 'multitenancy' => false]
		);

		$this->assertSame([], $out['passed']);
	}

	public function testAnotherOrganisationsDraftGetsTheSameAnswerAsItsOtherRows(): void {
		$this->storedStatus = 'draft';

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten']);

		$this->assertSame(['Permission denied for action create on schema planned'], self::reasons($out['invalid']));
	}

	public function testAReadThatFailsRefusesTheRow(): void {
		$this->updateRules = [['group' => 'planners', 'crossOrganisation' => true]];
		$this->readable = 'throws';

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten']);

		$this->assertSame([], $out['passed']);
	}

	public function testANewUuidIsACreateAndNeverAsked(): void {
		$out = $this->safeguard(row: ['@self' => ['uuid' => '33333333-3333-3333-3333-333333333333'], 'title' => 'new']);

		$this->assertCount(1, $out['passed']);
		$this->assertSame([], $this->calls);
	}

	public function testANonAdministratorCannotSwitchTheBoundaryOff(): void {
		$out = $this->safeguard(
			row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten'],
			flags: ['rbac' => false, 'validation' => false, 'multitenancy' => false]
		);

		$this->assertSame([], $out['passed']);
	}

	public function testAnAdministratorWhoseWriteDoesNotKeepToOrganisationsIsNotRefusedForIt(): void {
		$this->admin = true;

		$out = $this->safeguard(
			row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'changed'],
			flags: ['rbac' => true, 'validation' => false, 'multitenancy' => false]
		);

		$this->assertCount(1, $out['passed']);
		$this->assertSame([], $this->calls);
	}

	public function testAnAdministratorWhoseWriteKeepsToOrganisationsIsRefusedOutsideThem(): void {
		$this->admin = true;

		$out = $this->safeguard(row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten']);

		$this->assertSame([], $out['passed']);
	}

	public function testAnAdministratorWithRbacOffIsNotAskedAboutOrganisations(): void {
		$this->admin = true;

		$out = $this->safeguard(
			row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'changed'],
			flags: ['rbac' => false, 'validation' => false, 'multitenancy' => true]
		);

		$this->assertCount(1, $out['passed']);
		$this->assertSame([], $this->calls);
	}

	public function testOnAnAppendOnlySchemaTheRowsExistenceStaysUnannounced(): void {
		$schema = $this->schema();
		$schema->setAppendOnly(true);
		$result = ['invalid' => [], 'statistics' => ['invalid' => 0, 'errors' => 0]];
		$method = new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards');
		$method->setAccessible(true);
		$row = ['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten'];
		$batch = new SafeguardBatchRun(objects: [$row], defaults: new BulkBatchDefaults($this->register(), $schema), flags: ['rbac' => true, 'validation' => false], result: $result);
		$method->invokeArgs($this->handler(), [$batch]);
		$result = $batch->result;

		$this->assertSame(['Permission denied for action create on schema planned'], self::reasons($result['invalid']));
	}

	public function testABatchWithoutAMultitenancyFlagKeepsToOrganisations(): void {
		$this->admin = true;

		$out = $this->safeguard(
			row: ['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten'],
			flags: ['rbac' => true, 'validation' => false]
		);

		$this->assertSame([], $out['passed']);
	}

	public function testTheBulkSaveRefusesTheRowBeforeAnyWrite(): void {
		$result = $this->handler()->saveObjects(
			[['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten']],
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema()), validation: false, events: false, audit: true)
		);

		$this->assertSame([], $result['saved']);
		$this->assertSame([], $result['updated']);
		$this->assertSame(['Permission denied for action create on schema planned'], self::reasons($result['invalid']));
	}

	public function testTheBulkSaveHandsTheMultitenancyFlagToTheSafeguards(): void {
		$this->admin = true;

		$kept = $this->handler()->saveObjects(
			[['@self' => ['uuid' => self::FOREIGN], 'title' => 'overwritten']],
			new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema()), validation: false, events: false, audit: true),
			_multitenancy: true
		);
		$this->calls = [];
		try {
			$this->handler()->saveObjects(
				[['@self' => ['uuid' => self::FOREIGN], 'title' => 'changed']],
				new BulkSaveOptions(new BulkBatchDefaults($this->register(), $this->schema()), validation: false, events: false, audit: true),
				_multitenancy: false
			);
		} catch (\Throwable) {
			// The row went on past the safeguards, into the writer this test does not build.
		}

		$this->assertCount(1, $kept['invalid']);
		$this->assertNotContains('hold '.self::FOREIGN.' org-b', $this->calls);
	}
}//end class

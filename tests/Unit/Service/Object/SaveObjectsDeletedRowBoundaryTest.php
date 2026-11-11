<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A bulk save or import does not take over another organisation's soft-deleted row.
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
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SafeguardBatchRun;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\BulkBatchDefaults;
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
 * Caller `alice` of org-a, a planner who may create and update planned rows. The bulk writer
 * upserts on `_uuid` with no deleted filter, so a row whose uuid is a soft-deleted row's uuid
 * is written onto that row and re-stamped with the caller's owner and organisation. The mapper
 * answers as the real one does: deleted rows only when asked for them, and the caller's own
 * read finds org-a rows only.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @covers \OCA\OpenRegister\Service\Object\BulkSafeguardRun
 * @uses   \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\RecordMetadataConditions
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Rbac\ExplicitReveal
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
 */
class SaveObjectsDeletedRowBoundaryTest extends TestCase {

	private const FOREIGN_DELETED = '66666666-6666-6666-6666-666666666666';

	private const OWN_DELETED = '44444444-4444-4444-4444-444444444444';

	private const FOREIGN_LIVE = '99999999-9999-9999-9999-999999999999';

	private const OWN_DELETED_DRAFT = '55555555-5555-5555-5555-555555555555';

	private const ROWS = [
		self::OWN_DELETED_DRAFT => ['org' => 'org-a', 'deleted' => true, 'status' => 'draft'],
		self::FOREIGN_DELETED => ['org' => 'org-b', 'deleted' => true],
		self::OWN_DELETED => ['org' => 'org-a', 'deleted' => true],
		self::FOREIGN_LIVE => ['org' => 'org-b', 'deleted' => false],
	];

	/**
	 * Whether the batch prefetch fails, so each row is looked up on its own.
	 */
	private bool $prefetchFails = false;

	/**
	 * Whether the caller is an administrator.
	 */
	private bool $admin = false;

	/**
	 * Every stored-row lookup, as [identifier or uuid list, includeDeleted].
	 *
	 * @var list<array{0: mixed, 1: bool}>
	 */
	private array $lookups = [];

	protected function setUp(): void {
		$reflection = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $cache) {
			$reflection->getProperty($cache)->setValue(null, []);
		}
	}//end setUp()

	/**
	 * The stored row of a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function stored(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOrganisation(self::ROWS[$uuid]['org']);
		$entity->setOwner(self::ROWS[$uuid]['org'] === 'org-a' ? 'carol' : 'bob');
		$entity->setObject(['title' => 'stored']);
		$entity->setRegister('10');
		$entity->setSchema('681');
		$entity->setStatus(self::ROWS[$uuid]['status'] ?? null);
		if (self::ROWS[$uuid]['deleted'] === true) {
			$entity->setDeleted(['deleted' => '2026-11-01T00:00:00+00:00', 'deletedBy' => 'bob']);
		}

		return $entity;
	}//end stored()

	/**
	 * Whether a stored row is visible to a lookup.
	 *
	 * @param string $uuid           The uuid.
	 * @param bool   $includeDeleted Whether the lookup asks for deleted rows.
	 * @param bool   $callerScope    Whether the lookup keeps to the caller's organisation.
	 *
	 * @return bool
	 */
	private static function visible(string $uuid, bool $includeDeleted, bool $callerScope): bool {
		if (isset(self::ROWS[$uuid]) === false) {
			return false;
		}

		if (self::ROWS[$uuid]['deleted'] === true && $includeDeleted === false) {
			return false;
		}

		return $callerScope === false || self::ROWS[$uuid]['org'] === 'org-a';
	}//end visible()

	/**
	 * The bulk save handler over the real permission handler.
	 *
	 * @return SaveObjects
	 */
	private function handler(): SaveObjects {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'alice' ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(static fn (IUser $who): array => $who->getUID() === 'alice' ? ['planners'] : []);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $this->admin === true && $uid === 'alice');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(static fn (string $app, string $key, bool $default = false): bool => $default);
		$container = $this->createMock(ContainerInterface::class);
		$logger = new NullLogger();
		$permissions = \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			$session,
			$users,
			$groups,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			new ConditionMatcher(new ConditionUserResolvers($session), $container, new OperatorEvaluator($logger), $logger),
			$appConfig,
			$logger,
			$container
		);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findObjectsByUuidsInRegisterSchema')->willReturnCallback(
			function (Register $register, Schema $schema, array $uuids, bool $includeDeleted = false): array {
				$this->lookups[] = [$uuids, $includeDeleted];
				if ($this->prefetchFails === true) {
					throw new \RuntimeException('prefetch failed');
				}

				$found = [];
				foreach ($uuids as $uuid) {
					if (self::visible(uuid: $uuid, includeDeleted: $includeDeleted, callerScope: false) === true) {
						$found[$uuid] = self::stored(uuid: $uuid);
					}
				}

				return $found;
			}
		);
		$mapper->method('find')->willReturnCallback(
			function (string|int $identifier, $register = null, $schema = null, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->lookups[] = [$identifier, $includeDeleted];
				$callerScope = ($_rbac === true || $_multitenancy === true);
				if (self::visible(uuid: (string)$identifier, includeDeleted: $includeDeleted, callerScope: $callerScope) === false) {
					throw new DoesNotExistException('none');
				}

				return self::stored(uuid: (string)$identifier);
			}
		);
		$mapper->method('callerOrganisationsHold')->willReturnCallback(static fn (ObjectEntity $row): bool => $row->getOrganisation() === 'org-a');
		$organisations = $this->createMock(OrganisationService::class);
		$organisations->method('getOrganisationForNewEntity')->willReturn('org-a');

		return new SaveObjects(
			stores: new BulkSaveStores(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $mapper), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $session, organisationService: $organisations, groupManager: $groups),
			reporting: new BulkSaveReporting(logger: $logger),
			checks: new BulkRowChecks(permissionHandler: $permissions, validateHandler: $this->createMock(ValidateObject::class))
		);
	}//end handler()

	/**
	 * Screen a batch as alice, and answer each row's refusal, or null where it passes.
	 *
	 * @param list<array> $rows  The rows.
	 * @param array       $flags The rbac and multitenancy flags sent.
	 * @param array       $rules The schema's authorization.
	 *
	 * @return list<string|null>
	 */
	private function screenBatch(
		array $rows,
		array $flags = ['rbac' => true, 'multitenancy' => true],
		array $rules = ['create' => ['planners'], 'update' => ['planners'], 'read' => ['planners']]
	): array {
		$schema = new Schema();
		$schema->setId(681);
		$schema->setSlug('planned');
		$schema->setTitle('planned');
		$schema->setAuthorization($rules);
		$register = new Register();
		$register->setId(10);
		$register->setConfiguration([]);

		$rows = array_map(static fn (array $row): array => $row + ['title' => 'overwritten'], $rows);
		$result = ['invalid' => [], 'statistics' => ['invalid' => 0, 'errors' => 0]];
		$batch = new SafeguardBatchRun(objects: $rows, defaults: new BulkBatchDefaults($register, $schema), flags: $flags + ['validation' => false], result: $result);
		$passed = (new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards'))->invokeArgs($this->handler(), [$batch]);
		$result = $batch->result;
		$passedIds = array_map(static fn (array $row): mixed => $row['id'] ?? $row['@self']['id'] ?? null, $passed);

		$answers = [];
		$refusals = array_column($result['invalid'], 'error');
		foreach ($rows as $row) {
			$id = ($row['id'] ?? $row['@self']['id'] ?? null);
			$answers[] = in_array($id, $passedIds, true) === true ? null : (array_shift($refusals) ?? 'refused without a reason');
		}

		return $answers;
	}//end screenBatch()

	/**
	 * Screen one row as alice, and answer its refusal, or null when it passes.
	 *
	 * @param array $row   The row.
	 * @param bool  $flags False to send rbac and multitenancy off.
	 *
	 * @return string|null
	 */
	private function screen(array $row, bool $flags = true): ?string {
		return $this->screenBatch(rows: [$row], flags: ['rbac' => $flags, 'multitenancy' => $flags])[0];
	}//end screen()

	public function testAnotherOrganisationsDeletedRowIsRefused(): void {
		$this->assertSame(
			expected: 'Permission denied for action create on schema planned',
			actual: $this->screen(row: ['id' => self::FOREIGN_DELETED])
		);
	}

	public function testAnotherOrganisationsDeletedRowIsRefusedWhenThePrefetchFails(): void {
		$this->prefetchFails = true;

		$this->assertSame(
			expected: 'Permission denied for action create on schema planned',
			actual: $this->screen(row: ['@self' => ['id' => self::FOREIGN_DELETED]])
		);
	}

	public function testANonAdministratorSendingTheFlagsOffIsStillRefused(): void {
		$this->assertSame(
			expected: 'Permission denied for action create on schema planned',
			actual: $this->screen(row: ['id' => self::FOREIGN_DELETED], flags: false)
		);
	}

	public function testTheRefusalReadsLikeTheOneForALiveRow(): void {
		$this->assertSame(
			expected: $this->screen(row: ['id' => self::FOREIGN_LIVE]),
			actual: $this->screen(row: ['id' => self::FOREIGN_DELETED])
		);
	}

	public function testTheCallersOwnDeletedRowIsJudgedAsBefore(): void {
		$this->assertNull($this->screen(row: ['id' => self::OWN_DELETED]));
	}

	public function testTheCallersOwnDeletedRowIsJudgedAsBeforeWhenThePrefetchFails(): void {
		$this->prefetchFails = true;

		$this->assertNull($this->screen(row: ['id' => self::OWN_DELETED]));
	}

	public function testAnAdministratorWithTheFlagsOffIsNotKeptFromIt(): void {
		$this->admin = true;

		$this->assertNull($this->screen(row: ['id' => self::FOREIGN_DELETED], flags: false));
	}

	public function testAnAdministratorKeepingRbacButNotTheOrganisationsIsNotKeptFromIt(): void {
		$this->admin = true;

		$this->assertSame(
			expected: [null],
			actual: $this->screenBatch(rows: [['id' => self::FOREIGN_DELETED]], flags: ['rbac' => true, 'multitenancy' => false])
		);
	}

	public function testTheCallersOwnDeletedRowStillCountsAsACreate(): void {
		$createOnly = ['create' => ['planners'], 'read' => ['planners']];

		$this->assertSame(expected: [null], actual: $this->screenBatch(rows: [['id' => self::OWN_DELETED]], rules: $createOnly));
	}

	public function testADeletedRowIsNotJudgedAsTheRowBeingUpdated(): void {
		$this->assertNull($this->screen(row: ['id' => self::OWN_DELETED_DRAFT]));
	}

	public function testARefusedDeletedRowDoesNotRefuseTheNextRow(): void {
		$this->assertSame(
			expected: ['Permission denied for action create on schema planned', null],
			actual: $this->screenBatch(rows: [['id' => self::FOREIGN_DELETED], ['id' => '77777777-7777-7777-7777-777777777777']])
		);
	}

	public function testANewUuidIsACreate(): void {
		$this->assertNull($this->screen(row: ['id' => '77777777-7777-7777-7777-777777777777']));
	}

	public function testTheStoredRowsAreLookedUpWithTheDeletedOnes(): void {
		$this->screen(row: ['id' => self::FOREIGN_DELETED]);

		$this->assertSame(expected: [[[self::FOREIGN_DELETED], true]], actual: array_slice($this->lookups, 0, 1));
	}
}//end class

<?php

/**
 * OrganisationWriteGuardTrait unit test.
 *
 * What a mapper stamps on a new entity (organisation and owner) and what it
 * refuses on a write: an entity of another organisation, and shared master
 * data its holder did not give the caller to change, each logged with the
 * actor pseudonymised.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Tenancy
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

namespace OCA\OpenRegister\Tests\Unit\Db\Tenancy;

use Exception;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\OrganisationWriteGuardTrait;
use OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait;
use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCA\OpenRegister\Service\DeclaredRow;
use OCA\OpenRegister\Service\SharedMasterDataServiceInterface;
use OCP\AppFramework\Db\Entity;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An entity with an organisation and an owner column.
 *
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 */
class WriteGuardEntity extends Entity {

	/**
	 * The holding organisation.
	 *
	 * @var string|null
	 */
	protected ?string $organisation = null;

	/**
	 * The owner uid.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;
}//end class

/**
 * An entity with neither column.
 */
class WriteGuardBareEntity extends Entity {
}//end class

/**
 * A mapper-shaped host for the write guard, with only the collaborators a case gives it.
 */
final class WriteGuardHost {
	use TenancyIdentityTrait;
	use OrganisationWriteGuardTrait;

	/**
	 * The logger, when the case gives one.
	 *
	 * @var LoggerInterface
	 */
	protected LoggerInterface $logger;

	/**
	 * The app config, when the case gives one.
	 *
	 * @var IAppConfig
	 */
	protected IAppConfig $appConfig;

	/**
	 * The session, when the case gives one.
	 *
	 * @var IUserSession
	 */
	protected IUserSession $userSession;

	/**
	 * The organisation mapper, when the case gives one.
	 *
	 * @var OrganisationMapper
	 */
	protected OrganisationMapper $organisationMapper;

	/**
	 * The table this host maps.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Build the host with the given collaborators.
	 *
	 * @param array<string, object> $deps  Collaborators by property name; `shared` is the shared master data service.
	 * @param string                $table The mapped table.
	 */
	public function __construct(array $deps, string $table='openregister_views') {
		foreach (['logger', 'appConfig', 'userSession', 'organisationMapper'] as $name) {
			if (isset($deps[$name]) === true) {
				$this->{$name} = $deps[$name];
			}
		}

		if (isset($deps['shared']) === true) {
			$this->sharedMasterData = $deps['shared'];
		}

		$this->table = $table;
	}//end __construct()

	/**
	 * The mapped table.
	 *
	 * @return string
	 */
	public function getTableName(): string {
		return $this->table;
	}//end getTableName()

	/**
	 * Run a trait method.
	 *
	 * @param string $method The method.
	 * @param array  $args   Named arguments.
	 *
	 * @return mixed
	 */
	public function call(string $method, array $args=[]): mixed {
		return $this->{$method}(...$args);
	}//end call()
}//end class

/**
 * @covers \OCA\OpenRegister\Db\Tenancy\OrganisationWriteGuardTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 * @uses   \OCA\OpenRegister\Exception\SharedMasterDataWriteException
 * @uses   \OCA\OpenRegister\Service\DeclaredRow
 * @uses   \OCA\OpenRegister\Service\TenancyCollaborators
 * @uses   \OCA\OpenRegister\Service\TenantLogRedactor
 */
final class OrganisationWriteGuardTraitTest extends TestCase {

	private const ORG = 'aaaaaaaa-0000-0000-0000-000000000001';

	private const OTHER = 'bbbbbbbb-0000-0000-0000-000000000001';

	/**
	 * What the logger received, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * A session signed in as the given user, or with nobody signed in.
	 *
	 * @param string|null $uid The user id, or null for nobody.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * An organisation mapper whose signed-in user is active in the given organisation.
	 *
	 * @param string|null $active The active organisation, or null for none.
	 *
	 * @return OrganisationMapper
	 */
	private function organisations(?string $active): OrganisationMapper {
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn($active);
		$organisations->method('getOrganisationHierarchy')->willReturn($active === null ? [] : [$active]);

		return $organisations;
	}//end organisations()

	/**
	 * A logger recording its warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return $logger;
	}//end logger()

	/**
	 * An entity of the given organisation and id.
	 *
	 * @param string|null $organisation The organisation.
	 * @param int|null    $id           The id.
	 *
	 * @return WriteGuardEntity
	 */
	private static function entity(?string $organisation, ?int $id=7): WriteGuardEntity {
		$entity = new WriteGuardEntity();
		$entity->setOrganisation($organisation);
		if ($id !== null) {
			$entity->setId($id);
		}

		return $entity;
	}//end entity()

	/**
	 * A new entity takes the active organisation; without one, the configured default.
	 *
	 * @return void
	 */
	public function testANewEntityTakesTheActiveOrDefaultOrganisation(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($key === 'defaultOrganisation' ? self::OTHER : $default)
		);
		$emptyConfig = $this->createMock(IAppConfig::class);
		$emptyConfig->method('getValueString')->willReturn('');

		$active = self::entity(organisation: null, id: null);
		(new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $this->organisations(active: self::ORG)]))
			->call(method: 'setOrganisationOnCreate', args: ['entity' => $active]);

		$default = self::entity(organisation: null, id: null);
		(new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $this->organisations(active: null), 'appConfig' => $config]))
			->call(method: 'setOrganisationOnCreate', args: ['entity' => $default]);

		$none = self::entity(organisation: null, id: null);
		(new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $this->organisations(active: null), 'appConfig' => $emptyConfig]))
			->call(method: 'setOrganisationOnCreate', args: ['entity' => $none]);

		$kept = self::entity(organisation: self::ORG, id: null);
		(new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $this->organisations(active: null), 'appConfig' => $config]))
			->call(method: 'setOrganisationOnCreate', args: ['entity' => $kept]);

		$this->assertSame(self::ORG, $active->getOrganisation());
		$this->assertSame(self::OTHER, $default->getOrganisation());
		$this->assertNull($none->getOrganisation());
		$this->assertSame(self::ORG, $kept->getOrganisation(), 'an organisation already set is not replaced by the default');
	}//end testANewEntityTakesTheActiveOrDefaultOrganisation()

	/**
	 * A new entity's owner is the signed-in user, unless it already has one.
	 *
	 * @return void
	 */
	public function testANewEntityIsOwnedByTheSignedInUser(): void {
		$fresh = self::entity(organisation: null, id: null);
		(new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna')]))->call(method: 'setOwnerOnCreate', args: ['entity' => $fresh]);

		$owned = self::entity(organisation: null, id: null);
		$owned->setOwner('bram');
		(new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna')]))->call(method: 'setOwnerOnCreate', args: ['entity' => $owned]);

		$anonymous = self::entity(organisation: null, id: null);
		(new WriteGuardHost(deps: ['userSession' => $this->session(uid: null)]))->call(method: 'setOwnerOnCreate', args: ['entity' => $anonymous]);

		$sessionless = self::entity(organisation: null, id: null);
		(new WriteGuardHost(deps: []))->call(method: 'setOwnerOnCreate', args: ['entity' => $sessionless]);

		$bare = new WriteGuardBareEntity();
		(new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna')]))->call(method: 'setOwnerOnCreate', args: ['entity' => $bare]);
		(new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna')]))->call(method: 'setOrganisationOnCreate', args: ['entity' => $bare]);

		$this->assertSame('anna', $fresh->getOwner());
		$this->assertSame('bram', $owned->getOwner());
		$this->assertNull($anonymous->getOwner());
		$this->assertNull($sessionless->getOwner());
	}//end testANewEntityIsOwnedByTheSignedInUser()

	/**
	 * The own organisation, an entity without one, or without the column, passes.
	 *
	 * @return void
	 */
	public function testTheOwnOrganisationPasses(): void {
		$host = new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $this->organisations(active: self::ORG), 'logger' => $this->logger()]);

		$host->call(method: 'verifyOrganisationAccess', args: ['entity' => self::entity(organisation: self::ORG)]);
		$host->call(method: 'verifyOrganisationAccess', args: ['entity' => self::entity(organisation: null)]);
		$host->call(method: 'verifyOrganisationAccess', args: ['entity' => new WriteGuardBareEntity()]);

		$this->assertSame([], $this->warnings);
	}//end testTheOwnOrganisationPasses()

	/**
	 * Another organisation's entity is refused with 403 and the denial is logged without the user id.
	 *
	 * @return void
	 */
	public function testAnotherOrganisationIsRefusedAndLogged(): void {
		$host = new WriteGuardHost(deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $this->organisations(active: self::ORG), 'logger' => $this->logger()]);

		try {
			$host->call(method: 'verifyOrganisationAccess', args: ['entity' => self::entity(organisation: self::OTHER, id: 12)]);
			$this->fail('a cross-organisation write was not refused');
		} catch (Exception $e) {
			$this->assertSame(403, $e->getCode());
			$this->assertStringContainsString('different organisation', $e->getMessage());
		}

		$this->assertCount(1, $this->warnings);
		[$message, $context] = $this->warnings[0];
		$this->assertSame('[MultiTenancyTrait] Cross-tenant access denied', $message);
		$this->assertSame('cross_tenant_access_denied', $context['type']);
		$this->assertSame(self::ORG, $context['sourceOrganisation']);
		$this->assertSame(self::OTHER, $context['targetOrganisation']);
		$this->assertSame(12, $context['entityId']);
		$this->assertStringStartsWith('actor:', $context['actor']);
		$this->assertStringNotContainsString('anna', (string)json_encode($context));
	}//end testAnotherOrganisationIsRefusedAndLogged()

	/**
	 * On a shared register the holder's refusal is logged and thrown as it is.
	 *
	 * @return void
	 */
	public function testSharedMasterDataIsRefusedByItsHolder(): void {
		$refusal = new SharedMasterDataWriteException(holderUuid: self::OTHER, holderName: 'Gemeente B', resourceType: 'register');
		$shared = $this->createMock(SharedMasterDataServiceInterface::class);
		$shared->expects($this->once())->method('assertWritable')
			->with(
				$this->callback(static fn (DeclaredRow $row): bool => $row->table === SharedMasterDataServiceInterface::REGISTERS && $row->id === 12),
				[self::ORG]
			)
			->willThrowException($refusal);
		$shared->expects($this->never())->method('clearCache');

		$host = new WriteGuardHost(
			deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $this->organisations(active: self::ORG), 'logger' => $this->logger(), 'shared' => $shared],
			table: SharedMasterDataServiceInterface::REGISTERS
		);

		try {
			$host->call(method: 'verifyOrganisationAccess', args: ['entity' => self::entity(organisation: self::OTHER, id: 12)]);
			$this->fail('a write to shared master data was not refused');
		} catch (SharedMasterDataWriteException $e) {
			$this->assertSame($refusal, $e);
		}

		$this->assertCount(1, $this->warnings);
		[$message, $context] = $this->warnings[0];
		$this->assertSame('[MultiTenancyTrait] Shared master data is read-only to a consumer', $message);
		$this->assertSame('shared_master_data_write_denied', $context['type']);
		$this->assertSame(self::OTHER, $context['holderOrganisation']);
		$this->assertSame('register', $context['resourceType']);
		$this->assertStringStartsWith('actor:', $context['actor']);
	}//end testSharedMasterDataIsRefusedByItsHolder()

	/**
	 * A shared row the caller may write clears the shared cache and is still refused as another organisation's.
	 *
	 * @return void
	 */
	public function testAWritableSharedRowStillBelongsToItsOrganisation(): void {
		$shared = $this->createMock(SharedMasterDataServiceInterface::class);
		$shared->expects($this->once())->method('assertWritable');
		$shared->expects($this->once())->method('clearCache');

		$host = new WriteGuardHost(
			deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $this->organisations(active: self::ORG), 'shared' => $shared],
			table: SharedMasterDataServiceInterface::SCHEMAS
		);

		$this->expectExceptionCode(403);
		$host->call(method: 'verifyOrganisationAccess', args: ['entity' => self::entity(organisation: self::OTHER, id: 3)]);
	}//end testAWritableSharedRowStillBelongsToItsOrganisation()

	/**
	 * A shared-table entity without an integer id is not checked against the holder.
	 *
	 * @return void
	 */
	public function testASharedEntityWithoutAnIdIsNotChecked(): void {
		$shared = $this->createMock(SharedMasterDataServiceInterface::class);
		$shared->expects($this->never())->method('assertWritable');

		$host = new WriteGuardHost(
			deps: ['userSession' => $this->session(uid: 'anna'), 'organisationMapper' => $this->organisations(active: self::ORG), 'shared' => $shared],
			table: SharedMasterDataServiceInterface::REGISTERS
		);

		$this->expectExceptionCode(403);
		$host->call(method: 'verifyOrganisationAccess', args: ['entity' => self::entity(organisation: self::OTHER, id: null)]);
	}//end testASharedEntityWithoutAnIdIsNotChecked()
}//end class

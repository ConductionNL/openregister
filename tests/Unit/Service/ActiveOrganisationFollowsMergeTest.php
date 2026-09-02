<?php

/**
 * Tenant resolution follows the merge chain.
 *
 * Task 3.1 built `resolveMergeTarget()` and 3.2 built its read counterpart, but
 * nothing on the live path called either, so a merge was recorded and never
 * followed: every scoped query for a user whose active organisation had been
 * merged away still ran under the merged-away UUID. That is the silent kind of
 * defect, because the row still loads and the query still returns rows.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks the merge walk onto the live tenant-resolution path.
 */
class ActiveOrganisationFollowsMergeTest extends TestCase {
	/**
	 * @var OrganisationMapper|MockObject
	 */
	private $organisationMapper;

	/**
	 * @var IConfig|MockObject
	 */
	private $config;

	/**
	 * @var ISession|MockObject
	 */
	private $session;

	/**
	 * @var IUserSession|MockObject
	 */
	private $userSession;

	/**
	 * @var LoggerInterface|MockObject
	 */
	private $logger;

	/**
	 * @var OrganisationService
	 */
	private OrganisationService $service;

	/**
	 * Build the service over mocked collaborators and clear the static caches.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$reflection = new \ReflectionClass(OrganisationService::class);
		foreach (['defaultOrgCache' => null, 'defaultOrgCacheTs' => null, 'userOrgsCache' => []] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue(null, $value);
		}

		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->config = $this->createMock(IConfig::class);
		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		// No session cache, so every call reaches the database path.
		$this->session->method('get')->willReturn(null);

		$this->service = new OrganisationService(
			organisationMapper: $this->organisationMapper,
			userSession: $this->userSession,
			session: $this->session,
			config: $this->config,
			appConfig: $this->createMock(IAppConfig::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			logger: $this->logger
		);

	}//end setUp()

	/**
	 * Build an organisation row.
	 *
	 * @param string      $uuid       The organisation uuid.
	 * @param string|null $mergedInto The uuid it was merged into, if any.
	 * @param array       $users      The member user ids.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(string $uuid, ?string $mergedInto = null, array $users = ['alice']): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setName('Org ' . $uuid);
		$organisation->setUsers($users);
		$organisation->setCreated(new \DateTime('2024-01-01'));
		$organisation->setMergedInto($mergedInto);

		return $organisation;

	}//end organisation()

	/**
	 * The stored active organisation was merged away, so the survivor is
	 * returned instead of the row the UUID names.
	 *
	 * @return void
	 */
	public function testAMergedAwayActiveOrganisationResolvesToTheSurvivor(): void {
		$merged = $this->organisation(uuid: 'dead-uuid', mergedInto: 'survivor-uuid');
		$survivor = $this->organisation(uuid: 'survivor-uuid');

		$this->config->method('getUserValue')->willReturn('dead-uuid');
		$this->organisationMapper->method('findByUuid')->willReturn($merged);
		$this->organisationMapper->method('findByUuidFollowingMerge')
			->with(uuid: 'dead-uuid')
			->willReturn($survivor);

		$this->assertSame('survivor-uuid', $this->service->getActiveOrganisation()?->getUuid());

	}//end testAMergedAwayActiveOrganisationResolvesToTheSurvivor()

	/**
	 * The followed merge is written back, so the walk is a one-off per user
	 * rather than a cost every read pays forever.
	 *
	 * @return void
	 */
	public function testTheFollowedMergeIsWrittenBackToUserConfig(): void {
		$merged = $this->organisation(uuid: 'dead-uuid', mergedInto: 'survivor-uuid');
		$survivor = $this->organisation(uuid: 'survivor-uuid');

		$this->config->method('getUserValue')->willReturn('dead-uuid');
		$this->organisationMapper->method('findByUuid')->willReturn($merged);
		$this->organisationMapper->method('findByUuidFollowingMerge')->willReturn($survivor);

		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', 'openregister', $this->anything(), 'survivor-uuid');

		$this->service->getActiveOrganisation();

	}//end testTheFollowedMergeIsWrittenBackToUserConfig()

	/**
	 * An organisation that was never merged is not walked at all: the flag
	 * lives on the row that was merged away, so a lookup here would be a query
	 * per request for the case that is almost always true.
	 *
	 * @return void
	 */
	public function testAnUnmergedOrganisationIsNeverWalked(): void {
		$this->config->method('getUserValue')->willReturn('live-uuid');
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation(uuid: 'live-uuid'));

		$this->organisationMapper->expects($this->never())->method('findByUuidFollowingMerge');
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertSame('live-uuid', $this->service->getActiveOrganisation()?->getUuid());

	}//end testAnUnmergedOrganisationIsNeverWalked()

	/**
	 * A user the merge did not carry over is not a member of the survivor, so
	 * the stale setting is cleared and resolution falls through to the user's
	 * own organisations. It fails closed: no tenant is handed out on the
	 * strength of a membership that ended.
	 *
	 * @return void
	 */
	public function testAUserTheMergeDidNotCarryOverFallsThrough(): void {
		$merged = $this->organisation(uuid: 'dead-uuid', mergedInto: 'survivor-uuid');
		$survivor = $this->organisation(uuid: 'survivor-uuid', mergedInto: null, users: ['bob']);
		$own = $this->organisation(uuid: 'own-uuid');

		$this->config->method('getUserValue')->willReturn('dead-uuid');
		$this->organisationMapper->method('findByUuid')->willReturn($merged);
		$this->organisationMapper->method('findByUuidFollowingMerge')->willReturn($survivor);
		$this->organisationMapper->method('findByUserId')->willReturn([$own]);

		$this->config->expects($this->once())->method('deleteUserValue');

		$this->assertSame('own-uuid', $this->service->getActiveOrganisation()?->getUuid());

	}//end testAUserTheMergeDidNotCarryOverFallsThrough()

	/**
	 * With no stored setting the oldest membership is auto-picked, and that
	 * list can name a merged-away organisation too. Without the walk here the
	 * very first login after a merge writes the dead UUID straight back into
	 * config, which is the defect re-creating itself.
	 *
	 * @return void
	 */
	public function testTheAutoPickedOldestOrganisationFollowsTheMergeToo(): void {
		$merged = $this->organisation(uuid: 'dead-uuid', mergedInto: 'survivor-uuid');
		$survivor = $this->organisation(uuid: 'survivor-uuid');

		$this->config->method('getUserValue')->willReturn('');
		$this->organisationMapper->method('findByUserId')->willReturn([$merged]);
		$this->organisationMapper->method('findByUuidFollowingMerge')->willReturn($survivor);

		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', 'openregister', $this->anything(), 'survivor-uuid');

		$this->assertSame('survivor-uuid', $this->service->getActiveOrganisation()?->getUuid());

	}//end testTheAutoPickedOldestOrganisationFollowsTheMergeToo()

	/**
	 * A merge pointing at a row that cannot be loaded is a data defect, not a
	 * reason to hand the caller nothing. The organisation already in hand is
	 * real, so resolution keeps it rather than throwing out of a read path.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSurvivorKeepsTheOrganisationAsStored(): void {
		$merged = $this->organisation(uuid: 'dead-uuid', mergedInto: 'ghost-uuid');

		$this->config->method('getUserValue')->willReturn('dead-uuid');
		$this->organisationMapper->method('findByUuid')->willReturn($merged);
		$this->organisationMapper->method('findByUuidFollowingMerge')
			->willThrowException(new DoesNotExistException('gone'));

		$this->logger->expects($this->once())->method('warning');

		$this->assertSame('dead-uuid', $this->service->getActiveOrganisation()?->getUuid());

	}//end testAnUnresolvableSurvivorKeepsTheOrganisationAsStored()
}//end class

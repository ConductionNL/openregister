<?php

/**
 * Unit tests for OrganisationService inside an organisation context.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationContext;
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
 * Inside an organisation context the active organisation is the context's,
 * whoever holds the session, and new objects are stamped with it.
 *
 * @covers \OCA\OpenRegister\Service\OrganisationService
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Db\Organisation
 */
class OrganisationServiceContextTest extends TestCase {

	/**
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper $mapper;

	/**
	 * @var ISession&MockObject
	 */
	private ISession $session;

	private OrganisationService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(OrganisationMapper::class);
		$this->mapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid): Organisation {
				if (in_array($uuid, ['org-a', 'org-b', 'org-parent'], true) === false) {
					throw new DoesNotExistException('no such organisation');
				}

				$organisation = new Organisation();
				$organisation->setUuid($uuid);
				return $organisation;
			}
		);
		$this->mapper->method('findParentChain')->willReturnCallback(
			static fn (string $uuid): array => ($uuid === 'org-a') ? ['org-parent'] : []
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->session = $this->createMock(ISession::class);

		$this->service = new OrganisationService(
			$this->mapper,
			$userSession,
			$this->session,
			$this->createMock(IConfig::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	public function testTheActiveOrganisationIsTheContextsWhoeverIsSignedIn(): void {
		$this->session->expects($this->never())->method('get');

		$uuid = OrganisationContext::run('org-b', fn (): ?string => $this->service->getActiveOrganisation()?->getUuid());

		$this->assertSame('org-b', $uuid);
	}//end testTheActiveOrganisationIsTheContextsWhoeverIsSignedIn()

	public function testTheActiveSetIsTheContextsOrganisationAndItsParents(): void {
		$uuids = OrganisationContext::run('org-a', fn (): array => $this->service->getUserActiveOrganisations());

		$this->assertSame(['org-a', 'org-parent'], $uuids);
	}//end testTheActiveSetIsTheContextsOrganisationAndItsParents()

	public function testANewObjectIsStampedWithTheContextsOrganisation(): void {
		$uuid = OrganisationContext::run('org-a', fn (): ?string => $this->service->getOrganisationForNewEntity());

		$this->assertSame('org-a', $uuid);
	}//end testANewObjectIsStampedWithTheContextsOrganisation()

	public function testAnUnknownContextOrganisationIsNoOrganisation(): void {
		$result = OrganisationContext::run(
			'org-x',
			fn (): array => [$this->service->getActiveOrganisation(), $this->service->getUserActiveOrganisations()]
		);

		$this->assertSame([null, []], $result);
	}//end testAnUnknownContextOrganisationIsNoOrganisation()

	public function testTheContextOrganisationIsLookedUpOncePerUuid(): void {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->expects($this->exactly(2))->method('findByUuid')->willReturnCallback(
			static function (string $uuid): Organisation {
				if ($uuid === 'org-x') {
					throw new DoesNotExistException('no such organisation');
				}

				$organisation = new Organisation();
				$organisation->setUuid($uuid);
				return $organisation;
			}
		);
		$service = new OrganisationService(
			$mapper,
			$this->createMock(IUserSession::class),
			$this->session,
			$this->createMock(IConfig::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class)
		);

		$first = $service->findContextOrganisation(uuid: 'org-a');
		$this->assertSame($first, $service->findContextOrganisation(uuid: 'org-a'));
		$this->assertNull($service->findContextOrganisation(uuid: 'org-x'));
		$this->assertNull($service->findContextOrganisation(uuid: 'org-x'));
		$this->assertSame('org-a', $first?->getUuid());
	}//end testTheContextOrganisationIsLookedUpOncePerUuid()

	public function testAnInactiveContextOrganisationIsNoOrganisation(): void {
		$inactive = new Organisation();
		$inactive->setUuid('org-off');
		$inactive->setActive(false);

		$this->assertNull($this->serviceFinding(organisations: ['org-off' => $inactive])->findContextOrganisation(uuid: 'org-off'));
	}//end testAnInactiveContextOrganisationIsNoOrganisation()

	public function testAMergedAwayContextOrganisationAnswersWithItsSurvivor(): void {
		$merged = new Organisation();
		$merged->setUuid('org-old');
		$merged->setMergedInto('org-new');
		$survivor = new Organisation();
		$survivor->setUuid('org-new');

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturn($merged);
		$mapper->method('findByUuidFollowingMerge')->with('org-old')->willReturn($survivor);

		$this->assertSame('org-new', $this->serviceOver(mapper: $mapper)->findContextOrganisation(uuid: 'org-old')?->getUuid());
	}//end testAMergedAwayContextOrganisationAnswersWithItsSurvivor()

	/**
	 * A service whose mapper finds the given organisations by uuid.
	 *
	 * @param array<string, Organisation> $organisations The organisations by uuid.
	 *
	 * @return OrganisationService The service.
	 */
	private function serviceFinding(array $organisations): OrganisationService {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			static fn (string $uuid): Organisation => ($organisations[$uuid] ?? throw new DoesNotExistException('no such organisation'))
		);

		return $this->serviceOver(mapper: $mapper);
	}//end serviceFinding()

	/**
	 * A service over the given mapper, with no one signed in.
	 *
	 * @param OrganisationMapper $mapper The mapper.
	 *
	 * @return OrganisationService The service.
	 */
	private function serviceOver(OrganisationMapper $mapper): OrganisationService {
		return new OrganisationService(
			$mapper,
			$this->createMock(IUserSession::class),
			$this->session,
			$this->createMock(IConfig::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end serviceOver()

	public function testOutsideAContextAnAnonymousCallerHasNoOrganisation(): void {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->expects($this->never())->method('findByUuid');
		$service = new OrganisationService(
			$mapper,
			$this->createMock(IUserSession::class),
			$this->session,
			$this->createMock(IConfig::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertNull($service->getActiveOrganisation());
	}//end testOutsideAContextAnAnonymousCallerHasNoOrganisation()
}//end class

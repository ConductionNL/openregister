<?php

/**
 * The default organisation's slugs are kept from users
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Exception\OrganisationSlugConflictException;
use OCA\OpenRegister\Service\Organisation\NewOrganisation;
use OCA\OpenRegister\Service\OrganisationService;
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
use ReflectionMethod;

/**
 * A user cannot take the default organisation's slug, and a row a user owns under it is never adopted as the default.
 */
class OrganisationServiceDefaultSlugTest extends TestCase {

	/**
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper&MockObject $mapper;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/** @var array<string, Organisation> Stored organisations by slug. */
	private array $stored = [];

	/**
	 * @var OrganisationService
	 */
	private OrganisationService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(OrganisationMapper::class);
		$this->mapper->method('save')->willReturnCallback(
			function (Organisation $organisation): Organisation {
				if (isset($this->stored[$organisation->getSlug()]) === true) {
					throw new OrganisationSlugConflictException(slug: $organisation->getSlug());
				}

				$this->stored[$organisation->getSlug()] = $organisation;
				return $organisation;
			}
		);
		$this->mapper->method('findBySlug')->willReturnCallback(fn (string $slug): Organisation => $this->stored[$slug]);

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'root');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('existing-default');

		$this->service = new OrganisationService(
			$this->mapper,
			$this->userSession,
			$this->createMock(ISession::class),
			$this->createMock(IConfig::class),
			$appConfig,
			$this->groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
			null
		);
	}//end setUp()

	private function signedIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signedIn()

	private function held(string $slug, ?string $owner): Organisation {
		$organisation = new Organisation();
		$organisation->setSlug($slug);
		$organisation->setOwner($owner);
		$organisation->setUuid('uuid-' . $slug);
		$this->stored[$slug] = $organisation;

		return $organisation;
	}//end held()

	private function createDefault(): Organisation {
		$method = new ReflectionMethod(OrganisationService::class, 'createDefaultOrganisation');
		return $method->invoke($this->service);
	}//end createDefault()

	public function testAUserCannotCreateAnOrganisationUnderTheDefaultSlug(): void {
		$this->signedIn(uid: 'mallory');

		$this->expectException(OrganisationSlugConflictException::class);
		$this->service->createOrganisation(details: new NewOrganisation(name: 'Default  organisation!', description: ''), addCurrentUser: true);
	}//end testAUserCannotCreateAnOrganisationUnderTheDefaultSlug()

	public function testAnAdministratorMayCreateAnOrganisationUnderTheDefaultSlug(): void {
		$this->signedIn(uid: 'root');

		$created = $this->service->createOrganisation(details: new NewOrganisation(name: 'Default Organisation', description: ''), addCurrentUser: true);

		$this->assertSame('default-organisation', $created->getSlug());
	}//end testAnAdministratorMayCreateAnOrganisationUnderTheDefaultSlug()

	public function testAnOwnerlessHolderOfTheSlugIsAdopted(): void {
		$holder = $this->held(slug: 'default-organisation', owner: null);

		$this->assertSame($holder, $this->createDefault());
	}//end testAnOwnerlessHolderOfTheSlugIsAdopted()

	public function testAUserOwnedHolderIsPassedOverForTheSystemName(): void {
		$this->held(slug: 'default-organisation', owner: 'mallory');

		$default = $this->createDefault();

		$this->assertSame('default-organisation-system', $default->getSlug());
		$this->assertNull($default->getOwner());
	}//end testAUserOwnedHolderIsPassedOverForTheSystemName()

	public function testWhenEveryReservedSlugIsUserOwnedNothingIsAdopted(): void {
		$this->held(slug: 'default-organisation', owner: 'mallory');
		$this->held(slug: 'default-organisation-system', owner: 'mallory');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('reserved names are held by user organisations');
		$this->createDefault();
	}//end testWhenEveryReservedSlugIsUserOwnedNothingIsAdopted()
}//end class

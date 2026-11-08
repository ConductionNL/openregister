<?php

/**
 * Golden test pinning who may read and write through the organisation projection.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\OrganisationActingUser;
use OCA\OpenRegister\Service\ObjectSource\OrganisationObjectSourceProvider;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the allowed and refused outcomes of the projection's read and write checks.
 *
 * An instance admin reads every organisation; a member reads only their own; an
 * outsider and an anonymous caller read nothing. A write needs the organisation's
 * administrator, and is refused to everyone else.
 */
class OrganisationObjectSourceAccessGoldenTest extends TestCase {

	/**
	 * The organisation mapper double.
	 *
	 * @var OrganisationMapperInterface&MockObject
	 */
	private $organisationMapper;

	/**
	 * The organisation lifecycle double.
	 *
	 * @var OrganisationServiceInterface&MockObject
	 */
	private $organisationService;

	/**
	 * The acting user's session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * Wire fresh doubles for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->organisationMapper  = $this->createMock(OrganisationMapperInterface::class);
		$this->organisationService = $this->createMock(OrganisationServiceInterface::class);
		$this->userSession         = $this->createMock(IUserSession::class);
		$this->groupManager        = $this->createMock(IGroupManager::class);

	}//end setUp()

	/**
	 * Build the provider over the current doubles.
	 *
	 * @return OrganisationObjectSourceProvider The provider under test.
	 */
	private function provider(): OrganisationObjectSourceProvider {
		return new OrganisationObjectSourceProvider(
			organisationMapper: $this->organisationMapper,
			actingUser: new OrganisationActingUser(userSession: $this->userSession, groupManager: $this->groupManager),
			logger: $this->createMock(LoggerInterface::class),
			organisationService: $this->organisationService
		);

	}//end provider()

	/**
	 * Say who is acting, and whether they are an instance admin.
	 *
	 * @param string|null $uid   The user id, or null for an anonymous caller.
	 * @param bool        $admin Whether the user is an instance admin.
	 *
	 * @return void
	 */
	private function acting(?string $uid, bool $admin = false): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturnMap([[$uid, $admin]]);

	}//end acting()

	/**
	 * An organisation with one member.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $member The member's user id.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisation(string $uuid, string $member): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setName('Organisation ' . $uuid);
		$organisation->setUsers([$member]);

		return $organisation;

	}//end organisation()

	/**
	 * Read one organisation through the provider.
	 *
	 * @return bool Whether the read was allowed.
	 */
	private function findAllowed(): bool {
		$this->organisationMapper->method('findByUuidFollowingMerge')
			->willReturn($this->organisation(uuid: 'org-1', member: 'member'));

		return $this->provider()->find(register: new Register(), schema: new Schema(), id: 'org-1') !== null;

	}//end findAllowed()

	/**
	 * Data for the find matrix.
	 *
	 * @return array<string, array{0: string|null, 1: bool, 2: bool}>
	 */
	public static function findCases(): array {
		return [
			'instance admin, not a member' => ['admin', true, true],
			'member'                       => ['member', false, true],
			'outsider'                     => ['outsider', false, false],
			'anonymous'                    => [null, false, false],
		];

	}//end findCases()

	/**
	 * Who may read one organisation.
	 *
	 * @param string|null $uid      The acting user.
	 * @param bool        $admin    Whether they are an instance admin.
	 * @param bool        $expected Whether the read is allowed.
	 *
	 * @return void
	 *
	 * @dataProvider findCases
	 */
	public function testFindIsAllowedOnlyToAnAdminOrAMember(?string $uid, bool $admin, bool $expected): void {
		$this->acting(uid: $uid, admin: $admin);

		$this->assertSame($expected, $this->findAllowed());

	}//end testFindIsAllowedOnlyToAnAdminOrAMember()

	/**
	 * An instance admin lists every organisation.
	 *
	 * @return void
	 */
	public function testAnAdminListsEveryOrganisation(): void {
		$this->acting(uid: 'admin', admin: true);
		$this->organisationMapper->expects($this->once())->method('findAll')
			->willReturn([$this->organisation(uuid: 'org-1', member: 'x'), $this->organisation(uuid: 'org-2', member: 'y')]);
		$this->organisationMapper->expects($this->never())->method('findByUserId');

		$this->assertCount(2, $this->provider()->findAll(register: new Register(), schema: new Schema()));

	}//end testAnAdminListsEveryOrganisation()

	/**
	 * Anyone else lists only the organisations they belong to.
	 *
	 * @return void
	 */
	public function testAMemberListsOnlyTheirOwn(): void {
		$this->acting(uid: 'member', admin: false);
		$this->organisationMapper->expects($this->never())->method('findAll');
		$this->organisationMapper->expects($this->once())->method('findByUserId')->with('member')
			->willReturn([$this->organisation(uuid: 'org-1', member: 'member')]);

		$this->assertCount(1, $this->provider()->findAll(register: new Register(), schema: new Schema()));

	}//end testAMemberListsOnlyTheirOwn()

	/**
	 * An anonymous caller lists nothing and the mapper is not asked.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerListsNothing(): void {
		$this->acting(uid: null);
		$this->organisationMapper->expects($this->never())->method('findAll');
		$this->organisationMapper->expects($this->never())->method('findByUserId');

		$this->assertSame([], $this->provider()->findAll(register: new Register(), schema: new Schema()));

	}//end testAnAnonymousCallerListsNothing()

	/**
	 * The organisation's administrator may write it.
	 *
	 * @return void
	 */
	public function testTheAdministratorMayWrite(): void {
		$this->acting(uid: 'member');
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation(uuid: 'org-1', member: 'member'));
		$this->organisationService->method('isOrganisationAdmin')->willReturn(true);
		$this->organisationMapper->expects($this->once())->method('save')->willReturnArgument(0);

		$entity = $this->provider()->update(register: new Register(), schema: new Schema(), id: 'org-1', data: ['name' => 'New']);

		$this->assertSame('New', $entity->getObject()['name']);

	}//end testTheAdministratorMayWrite()

	/**
	 * A member who does not administer it may not, and neither may an anonymous caller.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function refusedWriters(): array {
		return [
			'member, not administrator' => ['member'],
			'anonymous'                 => [null],
		];

	}//end refusedWriters()

	/**
	 * Everyone but the administrator is refused a write, before anything is saved.
	 *
	 * @param string|null $uid The acting user.
	 *
	 * @return void
	 *
	 * @dataProvider refusedWriters
	 */
	public function testAWriteIsRefusedToAnyoneButTheAdministrator(?string $uid): void {
		$this->acting(uid: $uid);
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation(uuid: 'org-1', member: 'member'));
		$this->organisationService->method('isOrganisationAdmin')->willReturn(false);
		$this->organisationMapper->expects($this->never())->method('save');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('does not exist');

		$this->provider()->update(register: new Register(), schema: new Schema(), id: 'org-1', data: ['name' => 'New']);

	}//end testAWriteIsRefusedToAnyoneButTheAdministrator()
}//end class

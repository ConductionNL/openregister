<?php

/**
 * Who may use what agent_use and llm_use guard, read from one organisation.
 *
 * Decision 239: the special rights a caller uses are enforced. These tests pin
 * the answer for a holder, a non-holder, an administrator, `*`, a key the
 * organisation never set, an organisation that cannot be found, and the
 * upgrade writer that opens a right to everybody.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights
 * @uses   \OCA\OpenRegister\Db\Organisation
 *
 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
 */
class OrganisationSpecialRightsUseTest extends TestCase {

	private OrganisationMapper&MockObject $organisations;

	private IGroupManager&MockObject $groupManager;

	private OrganisationSpecialRights $rights;

	protected function setUp(): void {
		parent::setUp();

		$this->organisations = $this->createMock(OrganisationMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, ['alice' => ['ai-users'], 'bob' => ['staff']][$uid] ?? [], true)
		);

		$this->rights = new OrganisationSpecialRights(
			$this->organisations,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			new NullLogger(),
			$this->groupManager
		);
	}//end setUp()

	/**
	 * Organisation `org-1` with one authorization block.
	 *
	 * @param array<string, mixed> $authorization The stored block.
	 *
	 * @return Organisation The organisation.
	 */
	private function organisationWith(array $authorization): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setAuthorization($authorization);
		$this->organisations->method('findByUuid')->with('org-1')->willReturn($organisation);

		return $organisation;
	}//end organisationWith()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function rightsInUse(): array {
		return [
			'agent_use' => [OrganisationSpecialRights::AGENT_USE],
			'llm_use' => [OrganisationSpecialRights::LLM_USE],
		];
	}//end rightsInUse()

	/**
	 * @dataProvider rightsInUse
	 */
	public function testAHolderMay(string $right): void {
		$this->organisationWith([$right => ['ai-users']]);

		$this->assertTrue($this->rights->mayUse($right, 'alice', 'org-1'));
	}//end testAHolderMay()

	/**
	 * @dataProvider rightsInUse
	 */
	public function testANonHolderMayNot(string $right): void {
		$this->organisationWith([$right => ['ai-users']]);

		$this->assertFalse($this->rights->mayUse($right, 'bob', 'org-1'));
		$this->assertFalse($this->rights->mayUse($right, 'carol', 'org-1'), 'a user in no group');
	}//end testANonHolderMayNot()

	/**
	 * @dataProvider rightsInUse
	 */
	public function testAnAdministratorMayWithoutBeingListed(string $right): void {
		$this->organisationWith([$right => []]);

		$this->assertTrue($this->rights->mayUse($right, 'admin', 'org-1'));
	}//end testAnAdministratorMayWithoutBeingListed()

	/**
	 * @dataProvider rightsInUse
	 */
	public function testAnEmptyListAdmitsOnlyAdministrators(string $right): void {
		$this->organisationWith([$right => []]);

		$this->assertFalse($this->rights->mayUse($right, 'alice', 'org-1'));
	}//end testAnEmptyListAdmitsOnlyAdministrators()

	/**
	 * @dataProvider rightsInUse
	 */
	public function testEveryoneAdmitsEverySignedInUserIncludingOneInNoGroup(string $right): void {
		$this->organisationWith([$right => ['ai-users', '*']]);

		$this->assertTrue($this->rights->mayUse($right, 'bob', 'org-1'));
		$this->assertTrue($this->rights->mayUse($right, 'carol', 'org-1'));
		$this->assertFalse($this->rights->mayUse($right, '', 'org-1'), 'nobody signed in');
	}//end testEveryoneAdmitsEverySignedInUserIncludingOneInNoGroup()

	/**
	 * @dataProvider rightsInUse
	 */
	public function testAKeyTheOrganisationNeverSetIsNotNarrowed(string $right): void {
		$this->organisationWith(['object' => ['read' => ['staff']]]);

		$this->assertTrue($this->rights->mayUse($right, 'bob', 'org-1'));
	}//end testAKeyTheOrganisationNeverSetIsNotNarrowed()

	public function testAValueThatIsNotAListRefuses(): void {
		$this->organisationWith([OrganisationSpecialRights::LLM_USE => 'ai-users']);

		$this->assertFalse($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'alice', 'org-1'));
	}//end testAValueThatIsNotAListRefuses()

	public function testANarrowedEntryIsNotAGroupForARightInUse(): void {
		$this->organisationWith([OrganisationSpecialRights::AGENT_USE => [['group' => 'ai-users', 'register' => 'r']]]);

		$this->assertFalse($this->rights->mayUse(OrganisationSpecialRights::AGENT_USE, 'alice', 'org-1'));
	}//end testANarrowedEntryIsNotAGroupForARightInUse()

	public function testNoOrganisationNamedReadsTheDefaultOrganisation(): void {
		$this->organisationWith([OrganisationSpecialRights::LLM_USE => ['ai-users']]);
		$this->organisations->method('getDefaultOrganisationFromConfig')->willReturn('org-1');

		$this->assertTrue($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'alice', null));
		$this->assertFalse($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'bob', ''));
	}//end testNoOrganisationNamedReadsTheDefaultOrganisation()

	public function testNoOrganisationAndNoDefaultLeavesTheRightUnnarrowed(): void {
		$this->organisations->method('getDefaultOrganisationFromConfig')->willReturn(null);
		$this->organisations->expects($this->never())->method('findByUuid');

		$this->assertTrue($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'bob', null));
	}//end testNoOrganisationAndNoDefaultLeavesTheRightUnnarrowed()

	public function testAnOrganisationThatCannotBeLoadedRefuses(): void {
		$this->organisations->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));

		$this->assertFalse($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'bob', 'org-gone'));
		$this->assertTrue($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'admin', 'org-gone'));
	}//end testAnOrganisationThatCannotBeLoadedRefuses()

	public function testGrantManageIsNeverAnsweredAsARightInUse(): void {
		$this->organisationWith([OrganisationSpecialRights::GRANT_MANAGE => ['*', 'ai-users']]);

		$this->assertFalse($this->rights->mayUse(OrganisationSpecialRights::GRANT_MANAGE, 'alice', 'org-1'));
		$this->assertFalse($this->rights->mayUse(OrganisationSpecialRights::GRANT_MANAGE, 'admin', 'org-1'));
	}//end testGrantManageIsNeverAnsweredAsARightInUse()

	public function testANewOrganisationOpensTheFourRightsAndNotGrantManage(): void {
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$this->organisations->method('findByUuid')->willReturn($organisation);

		$this->assertTrue($this->rights->mayUse(OrganisationSpecialRights::AGENT_USE, 'carol', 'org-1'));
		$this->assertTrue($this->rights->mayUse(OrganisationSpecialRights::LLM_USE, 'carol', 'org-1'));
		$this->assertSame([], $organisation->getAuthorization()[OrganisationSpecialRights::GRANT_MANAGE]);
	}//end testANewOrganisationOpensTheFourRightsAndNotGrantManage()

	public function testOpeningToEveryoneAddsTheWildcardAndKeepsTheGroups(): void {
		$narrowed = new Organisation();
		$narrowed->setUuid('org-1');
		$narrowed->setAuthorization([OrganisationSpecialRights::LLM_USE => ['admin'], 'object' => ['read' => ['staff']]]);
		$open = new Organisation();
		$open->setUuid('org-2');
		$open->setAuthorization([OrganisationSpecialRights::LLM_USE => ['*']]);

		$this->organisations->method('findAll')->willReturn([$narrowed, $open]);
		$this->organisations->expects($this->once())->method('update')->with($narrowed)->willReturnArgument(0);

		$answer = $this->rights->openToEveryoneInEveryOrganisation(OrganisationSpecialRights::LLM_USE);

		$this->assertSame(['organisations' => 2, 'changed' => 1], $answer);
		$this->assertSame(['admin', '*'], $narrowed->getAuthorization()[OrganisationSpecialRights::LLM_USE]);
		$this->assertSame(['read' => ['staff']], $narrowed->getAuthorization()['object']);
	}//end testOpeningToEveryoneAddsTheWildcardAndKeepsTheGroups()

	public function testGrantManageIsNeverOpenedToEveryone(): void {
		$this->organisations->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->rights->openToEveryoneInEveryOrganisation(OrganisationSpecialRights::GRANT_MANAGE);
	}//end testGrantManageIsNeverOpenedToEveryone()
}//end class

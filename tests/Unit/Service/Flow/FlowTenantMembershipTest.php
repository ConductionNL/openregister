<?php

/**
 * Whether a user belongs to the tenant a flow runs for.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowTenantMembership;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Membership is the organisation's own user list; an unknown organisation has no members.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowTenantMembership
 * @uses \OCA\OpenRegister\Db\Organisation
 */
final class FlowTenantMembershipTest extends TestCase {

	/**
	 * A listed user is a member and an unlisted one is not.
	 *
	 * @return void
	 */
	public function testMembershipFollowsTheOrganisationsUsers(): void {
		$organisation = new Organisation();
		$organisation->setUsers(['alice']);
		$mapper = $this->createMock(OrganisationMapperInterface::class);
		$mapper->method('findByUuid')->with('org-1')->willReturn($organisation);
		$membership = new FlowTenantMembership(organisations: $mapper);

		$this->assertTrue($membership->isMember(uid: 'alice', organisation: 'org-1'));
		$this->assertFalse($membership->isMember(uid: 'mallory', organisation: 'org-1'));
	}//end testMembershipFollowsTheOrganisationsUsers()

	/**
	 * An organisation that cannot be found has no members.
	 *
	 * @return void
	 */
	public function testAnUnknownOrganisationHasNoMembers(): void {
		$mapper = $this->createMock(OrganisationMapperInterface::class);
		$mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));

		$this->assertFalse((new FlowTenantMembership(organisations: $mapper))->isMember(uid: 'alice', organisation: 'org-x'));
	}//end testAnUnknownOrganisationHasNoMembers()
}//end class

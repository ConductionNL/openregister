<?php

/**
 * Golden outcomes of GrantManageGuard: who may manage a record's grants, and who may not let itself in.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Rbac\GrantCaller;
use OCA\OpenRegister\Service\Rbac\GrantedPrincipal;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\GrantManageGuard
 */
final class GrantManageGuardGoldenTest extends TestCase {

	/**
	 * A guard over the real scope resolver and a special right that holds for the `deputies` group only.
	 *
	 * @return GrantManageGuard The guard.
	 */
	private function guard(): GrantManageGuard {
		$rights = $this->createMock(OrganisationSpecialRights::class);
		$rights->method('holdsOn')->willReturnCallback(
			static fn (string $right, array $userGroups, ObjectEntity $object): bool => in_array('deputies', $userGroups, true)
		);

		return new GrantManageGuard(scopeResolver: new ObjectScopeResolver(), specialRights: $rights);
	}//end guard()

	/**
	 * A record owned by `ann`.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setOwner('ann');
		$object->setAuthorization([]);

		return $object;
	}//end record()

	/**
	 * Owner, administrator and special-right holder are admitted as such; anyone else, and an anonymous holder, are refused.
	 *
	 * @return void
	 */
	public function testAdmittedAsPinsEveryOutcome(): void {
		$guard  = $this->guard();
		$object = $this->record();

		$this->assertSame(GrantManageGuard::AS_OWNER, $guard->admittedAs(new GrantCaller('ann', []), $object));
		$this->assertSame(GrantManageGuard::AS_OWNER, $guard->admittedAs(new GrantCaller('bob', ['admin']), $object));
		$this->assertSame(GrantManageGuard::AS_HOLDER, $guard->admittedAs(new GrantCaller('bob', ['deputies']), $object));
		$this->assertNull($guard->admittedAs(new GrantCaller('bob', ['staff']), $object));
		$this->assertNull($guard->admittedAs(new GrantCaller(null, ['deputies']), $object));
	}//end testAdmittedAsPinsEveryOutcome()

	/**
	 * Only a caller admitted by the special right is stopped from naming itself, a group it is in, or a remote principal.
	 *
	 * @return void
	 */
	public function testLetsItselfInPinsEveryOutcome(): void {
		$guard  = $this->guard();
		$holder = GrantManageGuard::AS_HOLDER;
		$groups = ['deputies', 'staff'];

		$this->assertTrue($guard->letsItselfIn($holder, new GrantCaller('bob', $groups), new GrantedPrincipal(user: 'bob')));
		$this->assertTrue($guard->letsItselfIn($holder, new GrantCaller('bob', $groups), new GrantedPrincipal(group: 'staff')));
		$this->assertTrue($guard->letsItselfIn($holder, new GrantCaller('bob', $groups), new GrantedPrincipal(remote: 'x@remote.example')));
		$this->assertFalse($guard->letsItselfIn($holder, new GrantCaller('bob', $groups), new GrantedPrincipal(user: 'cas')));
		$this->assertFalse($guard->letsItselfIn($holder, new GrantCaller('bob', $groups), new GrantedPrincipal(group: 'board')));
		$this->assertFalse($guard->letsItselfIn(GrantManageGuard::AS_OWNER, new GrantCaller('bob', $groups), new GrantedPrincipal(user: 'bob')));
		$this->assertFalse($guard->letsItselfIn(null, new GrantCaller('bob', $groups), new GrantedPrincipal(remote: 'x@remote.example')));
	}//end testLetsItselfInPinsEveryOutcome()
}//end class

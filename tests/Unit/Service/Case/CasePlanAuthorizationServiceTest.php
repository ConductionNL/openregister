<?php

/**
 * Authorization decides, fail-closed: a non-member is denied; an
 * unresolvable role denies naming the role; no backend denies; no rules
 * anywhere denies everyone but an administrator; rules derive from the
 * nearest ancestor and then the plan root.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Coverage of CasePlanAuthorizationService.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanAuthorizationService
 * @covers \OCA\OpenRegister\Exception\CaseAccessDeniedException
 */
class CasePlanAuthorizationServiceTest extends TestCase {

	/**
	 * A group backend where `alice` is in `behandelaars` and `boss` is admin;
	 * the group `ghost` does not exist.
	 *
	 * @return IGroupManager The mocked backend.
	 */
	private function groupBackend(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'boss');
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => $uid === 'alice' && $gid === 'behandelaars');
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid !== 'ghost');

		return $groups;
	}//end groups()

	/**
	 * A tree: a root stage (rules: behandelaars) with a child without rules
	 * and a grandchild with its own rules (user:carol).
	 *
	 * @return array{CasePlanTree, CaseItem, CaseItem, CaseItem} tree, root, child, grandchild.
	 */
	private function tree(): array {
		$root = CaseFixtures::row(id: 1, key: 'root', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$root->setAuthorizationRules(['behandelaars']);
		$root->setPlanSettings(['authorization' => ['role:beslissers']]);
		$child = CaseFixtures::row(id: 2, key: 'child', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE, parentId: 1);
		$child->setPlanSettings(['authorization' => ['role:beslissers']]);
		$grandchild = CaseFixtures::row(id: 3, key: 'gc', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE, parentId: 2);
		$grandchild->setAuthorizationRules(['user:carol']);
		$grandchild->setPlanSettings(['authorization' => ['role:beslissers']]);

		return [new CasePlanTree(items: [$root, $child, $grandchild]), $root, $child, $grandchild];
	}//end tree()

	/**
	 * A member passes; a non-member is denied; rules derive from the ancestor.
	 *
	 * @return void
	 */
	public function testMembershipDecidesAndRulesDeriveFromTheNearestAncestor(): void {
		[$tree, $root, $child, $grandchild] = $this->tree();
		$service = new CasePlanAuthorizationService(groupManager: $this->groupBackend());

		$service->assertMayAct(verb: 'enable', item: $root, tree: $tree, uid: 'alice');
		$service->assertMayAct(verb: 'enable', item: $child, tree: $tree, uid: 'alice');
		$service->assertMayAct(verb: 'enable', item: $grandchild, tree: $tree, uid: 'carol');
		$this->assertSame(['behandelaars'], $service->effectiveRules(item: $child, tree: $tree));
		$this->assertSame(['role:beslissers'], $service->effectiveRules(item: null, tree: $tree), 'The root derives from the plan settings.');

		try {
			$service->assertMayAct(verb: 'enable', item: $grandchild, tree: $tree, uid: 'alice');
			$this->fail('alice is not carol');
		} catch (CaseAccessDeniedException $denial) {
			$this->assertStringContainsString("Verb 'enable' denied", $denial->getMessage());
		}

		$this->expectException(CaseAccessDeniedException::class);
		$service->assertMayAct(verb: 'attach', item: $root, tree: $tree, uid: 'stranger');
	}//end testMembershipDecidesAndRulesDeriveFromTheNearestAncestor()

	/**
	 * An administrator passes everything; nobody passes anonymously.
	 *
	 * @return void
	 */
	public function testAdministratorsPassAndAnonymousNeverDoes(): void {
		[$tree, , , $grandchild] = $this->tree();
		$service = new CasePlanAuthorizationService(groupManager: $this->groupBackend());

		$service->assertMayAct(verb: 'enable', item: $grandchild, tree: $tree, uid: 'boss');
		$service->assertMayAdminister(verb: 'delete-plan', settings: [], uid: 'boss');
		$this->assertTrue($service->isAdministrator(uid: 'boss'));
		$this->assertFalse($service->isAdministrator(uid: null));
		$this->assertSame('alice', $service->assertIdentified(uid: ' alice ', verb: 'x'));

		foreach ([null, '', '  '] as $anonymous) {
			try {
				$service->assertMayAct(verb: 'enable', item: $grandchild, tree: $tree, uid: $anonymous);
				$this->fail('anonymous must be denied');
			} catch (CaseAccessDeniedException $denial) {
				$this->assertStringContainsString('no acting identity', $denial->getMessage());
			}
		}
	}//end testAdministratorsPassAndAnonymousNeverDoes()

	/**
	 * An unresolvable role denies NAMING THE ROLE; a resolvable one is a membership test.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRoleDeniesNamingIt(): void {
		$service = new CasePlanAuthorizationService(groupManager: $this->groupBackend());
		$item = CaseFixtures::row(id: 1, key: 'advice', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$item->setAuthorizationRules(['role:ghost']);
		$tree = new CasePlanTree(items: [$item]);

		try {
			$service->assertMayAct(verb: 'enable', item: $item, tree: $tree, uid: 'alice');
			$this->fail('ghost does not resolve');
		} catch (CaseAccessDeniedException $denial) {
			$this->assertStringContainsString("role 'ghost' does not resolve", $denial->getMessage());
		}

		$item->setAuthorizationRules(['role:behandelaars']);
		$service->assertMayAct(verb: 'enable', item: $item, tree: $tree, uid: 'alice');
		$this->addToAssertionCount(1);
	}//end testAnUnresolvableRoleDeniesNamingIt()

	/**
	 * No group backend: every membership decision denies, a role cannot resolve, nobody is admin.
	 *
	 * @return void
	 */
	public function testWithoutABackendEverythingDenies(): void {
		$service = new CasePlanAuthorizationService(groupManager: null);
		$item = CaseFixtures::row(id: 1, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$item->setAuthorizationRules(['behandelaars']);
		$tree = new CasePlanTree(items: [$item]);

		$this->assertFalse($service->isAdministrator(uid: 'boss'));
		try {
			$service->assertMayAct(verb: 'enable', item: $item, tree: $tree, uid: 'alice');
			$this->fail('no backend, no membership');
		} catch (CaseAccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		$item->setAuthorizationRules(['role:behandelaars']);
		try {
			$service->assertMayAct(verb: 'enable', item: $item, tree: $tree, uid: 'alice');
			$this->fail('no backend, no role');
		} catch (CaseAccessDeniedException $denial) {
			$this->assertStringContainsString('no group backend', $denial->getMessage());
		}

		// user: rules need no backend.
		$item->setAuthorizationRules(['user:alice']);
		$service->assertMayAct(verb: 'enable', item: $item, tree: $tree, uid: 'alice');
	}//end testWithoutABackendEverythingDenies()

	/**
	 * A throwing backend grants nothing; no rules anywhere denies non-admins;
	 * malformed rules admit nobody.
	 *
	 * @return void
	 */
	public function testAThrowingBackendAndMissingOrMalformedRulesDeny(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willThrowException(new RuntimeException('ldap down'));
		$groups->method('isInGroup')->willThrowException(new RuntimeException('ldap down'));
		$groups->method('groupExists')->willThrowException(new RuntimeException('ldap down'));
		$service = new CasePlanAuthorizationService(groupManager: $groups);

		$this->assertFalse($service->isAdministrator(uid: 'boss'));
		$item = CaseFixtures::row(id: 1, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$item->setPlanSettings([]);
		$tree = new CasePlanTree(items: [$item]);
		try {
			$service->assertMayAct(verb: 'enable', item: $item, tree: $tree, uid: 'alice');
			$this->fail('no rules anywhere');
		} catch (CaseAccessDeniedException $denial) {
			$this->assertStringContainsString('no authorization is declared', $denial->getMessage());
		}

		$item->setAuthorizationRules(['behandelaars', 42, '', 'role:ghost']);
		try {
			$service->assertMayAct(verb: 'enable', item: $item, tree: $tree, uid: 'alice');
			$this->fail('throwing backend');
		} catch (CaseAccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		$this->expectException(CaseAccessDeniedException::class);
		$service->assertMayAdminister(verb: 'create-plan', settings: ['authorization' => 'not-a-list'], uid: 'alice');
	}//end testAThrowingBackendAndMissingOrMalformedRulesDeny()
}//end class

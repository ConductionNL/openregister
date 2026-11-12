<?php

/**
 * Golden (characterisation) matrix for CasePlanAuthorizationService.
 *
 * Pins every ALLOWED and every REFUSED outcome of the case layer's
 * authorization, with the exact denial messages, the rule inheritance
 * (own, nearest ancestor, plan root) and the order rules are tried in,
 * so the class can be restructured without moving a single decision.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCA\OpenRegister\Service\Case\CasePlanAuthorizationService;
use OCA\OpenRegister\Service\Case\CasePlanTree;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use OCA\OpenRegister\Service\Case\CasePlanAct;

/**
 * Every branch of the public decisions, pinned.
 *
 * @covers \OCA\OpenRegister\Service\Case\CasePlanAuthorizationService
 * @uses \OCA\OpenRegister\Db\CaseItem
 * @uses \OCA\OpenRegister\Service\Case\CasePlanTree
 * @uses \OCA\OpenRegister\Exception\CaseAccessDeniedException
 */
final class CasePlanAuthorizationServiceGoldenTest extends TestCase {

	private const ALLOWED = 'allowed';

	/**
	 * The service under a described backend: `none`, `throws`, or
	 * `admins` / `members` (group => uids) / `groups` (existing ids).
	 *
	 * @param array<string, mixed> $backend The backend description.
	 *
	 * @return CasePlanAuthorizationService The service.
	 */
	private function service(array $backend): CasePlanAuthorizationService {
		if (($backend['none'] ?? false) === true) {
			return new CasePlanAuthorizationService(groupManager: null);
		}

		$groups = $this->createMock(IGroupManager::class);
		if (($backend['throws'] ?? false) === true) {
			$groups->method('isAdmin')->willThrowException(new RuntimeException('down'));
			$groups->method('isInGroup')->willThrowException(new RuntimeException('down'));
			$groups->method('groupExists')->willThrowException(new RuntimeException('down'));

			return new CasePlanAuthorizationService(groupManager: $groups);
		}

		$admins  = ($backend['admins'] ?? []);
		$members = ($backend['members'] ?? []);
		$exists  = ($backend['groups'] ?? []);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $admins, true));
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($uid, ($members[$gid] ?? []), true)
		);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, $exists, true));

		return new CasePlanAuthorizationService(groupManager: $groups);
	}//end service()

	/**
	 * One row.
	 *
	 * @param integer      $id       The id.
	 * @param integer|null $parentId The parent.
	 * @param mixed        $rules    Its own rules.
	 * @param mixed        $settings Its plan settings.
	 *
	 * @return CaseItem The row.
	 */
	private static function row(int $id, ?int $parentId, mixed $rules, mixed $settings): CaseItem {
		$row = new CaseItem();
		$row->setId($id);
		$row->setUuid('item-' . $id);
		$row->setParentItemId($parentId);
		$row->setAuthorizationRules($rules);
		$row->setPlanSettings($settings);

		return $row;
	}//end row()

	/**
	 * A root > mid > leaf tree, plus the leaf.
	 *
	 * @param array<string, mixed> $rules Rules per row (`root`, `mid`, `leaf`) and `plan` settings rules.
	 *
	 * @return array{0: CasePlanTree, 1: CaseItem} The tree and its leaf.
	 */
	private static function tree(array $rules): array {
		$settings = [];
		if (array_key_exists('plan', $rules) === true) {
			$settings = ['authorization' => $rules['plan']];
		}

		$root = self::row(id: 1, parentId: null, rules: ($rules['root'] ?? null), settings: $settings);
		$mid  = self::row(id: 2, parentId: 1, rules: ($rules['mid'] ?? null), settings: $settings);
		$leaf = self::row(id: 3, parentId: 2, rules: ($rules['leaf'] ?? null), settings: $settings);

		return [new CasePlanTree(items: [$root, $mid, $leaf]), $leaf];
	}//end tree()

	/**
	 * Every assertMayAct() branch: rules, backend, uid, whether the plan root
	 * (null item) is acted on, expected.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string|null, 3: bool, 4: string}>
	 */
	public static function actCases(): array {
		$none  = "Verb 'enable' denied: the caller holds none of the item's authorizations.";
		$empty = "Verb 'enable' denied: no authorization is declared for this item or its plan, so only an administrator may perform it.";
		$ghost = "Verb 'enable' denied: role 'ghost' does not resolve to any group.";
		$staff = ['groups' => ['staff', 'r1'], 'members' => ['staff' => ['alice'], 'r1' => ['rob']]];

		return [
			'no identity' => [['leaf' => ['user:alice']], ['admins' => ['boss']], null, false, "Verb 'enable' denied: no acting identity."],
			'blank identity' => [['leaf' => ['user:alice']], ['admins' => ['boss']], '   ', false, "Verb 'enable' denied: no acting identity."],
			'admin without any rules' => [[], ['admins' => ['boss']], 'boss', false, self::ALLOWED],
			'admin identity is trimmed' => [[], ['admins' => ['boss']], ' boss ', false, self::ALLOWED],
			'admin backend throws' => [[], ['throws' => true], 'boss', false, $empty],
			'no rules anywhere' => [[], $staff, 'alice', false, $empty],
			'empty rule lists everywhere' => [['leaf' => [], 'mid' => [], 'root' => [], 'plan' => []], $staff, 'alice', false, $empty],
			'non-list plan rules' => [['plan' => 'staff'], $staff, 'alice', false, $empty],
			'own user rule' => [['leaf' => ['user:alice'], 'mid' => ['user:zed']], $staff, 'alice', false, self::ALLOWED],
			'own user rule, trimmed identity' => [['leaf' => [' user:alice ']], $staff, ' alice ', false, self::ALLOWED],
			'own user rule, other user' => [['leaf' => ['user:alice']], $staff, 'bob', false, $none],
			'own rule beats ancestor' => [['leaf' => ['user:zed'], 'mid' => ['user:alice']], $staff, 'alice', false, $none],
			'nearest ancestor' => [['mid' => ['user:alice'], 'root' => ['user:zed']], $staff, 'alice', false, self::ALLOWED],
			'farther ancestor' => [['root' => ['staff'], 'plan' => ['user:zed']], $staff, 'alice', false, self::ALLOWED],
			'plan root settings' => [['plan' => ['staff']], $staff, 'alice', false, self::ALLOWED],
			'plan root acted on ignores row rules' => [['leaf' => ['user:alice'], 'plan' => ['user:zed']], $staff, 'alice', true, $none],
			'plan root acted on, no settings' => [['leaf' => ['user:alice']], $staff, 'alice', true, $empty],
			'group member' => [['leaf' => ['staff']], $staff, 'alice', false, self::ALLOWED],
			'group non-member' => [['leaf' => ['staff']], $staff, 'bob', false, $none],
			'group check throws' => [['leaf' => ['staff']], ['throws' => true], 'alice', false, $none],
			'group without backend' => [['leaf' => ['staff']], ['none' => true], 'alice', false, $none],
			'role member' => [['leaf' => ['role:r1']], $staff, 'rob', false, self::ALLOWED],
			'role non-member' => [['leaf' => ['role:r1']], $staff, 'alice', false, $none],
			'role does not resolve' => [['leaf' => ['role:ghost']], $staff, 'alice', false, $ghost],
			'role exists check throws' => [['leaf' => ['role:ghost']], ['throws' => true], 'alice', false, $ghost],
			'role without backend' => [
				['leaf' => ['role:r1']],
				['none' => true],
				'alice',
				false,
				"Verb 'enable' denied: role 'r1' cannot be resolved because no group backend is available.",
			],
			'empty role name' => [['leaf' => ['role:']], ['groups' => ['']], 'alice', false, $none],
			'admitting rule before a bad role' => [['leaf' => ['user:alice', 'role:ghost']], $staff, 'alice', false, self::ALLOWED],
			'bad role before an admitting rule' => [['leaf' => ['role:ghost', 'user:alice']], $staff, 'alice', false, $ghost],
			'non-string and blank rules skipped' => [
				['leaf' => [42, null, '  ', ['user:alice'], 'user:alice']],
				$staff,
				'alice',
				false,
				self::ALLOWED,
			],
			'only junk rules' => [['leaf' => [42, '  ']], $staff, 'alice', false, $none],
			'user prefix is exact' => [['leaf' => ['user:alice2', 'User:alice']], ['members' => ['User:alice' => ['bob']]], 'alice', false, $none],
		];
	}//end actCases()

	/**
	 * Every assertMayAct() outcome is pinned.
	 *
	 * @param array<string, mixed> $rules    Rules per row.
	 * @param array<string, mixed> $backend  Backend description.
	 * @param string|null          $uid      The caller.
	 * @param boolean              $onRoot   Act on the plan root (null item).
	 * @param string               $expected `allowed` or the exact message.
	 *
	 * @return void
	 */
	#[DataProvider('actCases')]
	public function testAssertMayActOutcome(array $rules, array $backend, ?string $uid, bool $onRoot, string $expected): void {
		[$tree, $leaf] = self::tree(rules: $rules);
		$item = $leaf;
		if ($onRoot === true) {
			$item = null;
		}

		$outcome = self::ALLOWED;
		try {
			$this->service(backend: $backend)->assertMayAct(act: new CasePlanAct(verb: 'enable', actor: $uid), item: $item, tree: $tree);
		} catch (CaseAccessDeniedException $denied) {
			$outcome = $denied->getMessage();
		}

		$this->assertSame($expected, $outcome);
	}//end testAssertMayActOutcome()

	/**
	 * Every assertMayAdminister() branch.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string|null, 3: string}>
	 */
	public static function administerCases(): array {
		$staff = ['groups' => ['staff'], 'members' => ['staff' => ['alice']]];

		return [
			'no identity' => [['authorization' => ['staff']], $staff, null, "Verb 'delete' denied: no acting identity."],
			'admin without rules' => [[], ['admins' => ['boss']], 'boss', self::ALLOWED],
			'no rules' => [
				[],
				$staff,
				'alice',
				"Verb 'delete' denied: no authorization is declared for this item or its plan, so only an administrator may perform it.",
			],
			'member' => [['authorization' => ['staff']], $staff, 'alice', self::ALLOWED],
			'user rule' => [['authorization' => ['user:carol']], $staff, 'carol', self::ALLOWED],
			'non-member' => [
				['authorization' => ['staff']],
				$staff,
				'bob',
				"Verb 'delete' denied: the caller holds none of the item's authorizations.",
			],
			'bad role' => [['authorization' => ['role:ghost']], $staff, 'bob', "Verb 'delete' denied: role 'ghost' does not resolve to any group."],
		];
	}//end administerCases()

	/**
	 * Every assertMayAdminister() outcome is pinned.
	 *
	 * @param array<string, mixed> $settings Plan settings.
	 * @param array<string, mixed> $backend  Backend description.
	 * @param string|null          $uid      The caller.
	 * @param string               $expected `allowed` or the exact message.
	 *
	 * @return void
	 */
	#[DataProvider('administerCases')]
	public function testAssertMayAdministerOutcome(array $settings, array $backend, ?string $uid, string $expected): void {
		$outcome = self::ALLOWED;
		try {
			$this->service(backend: $backend)->assertMayAdminister(act: new CasePlanAct(verb: 'delete', actor: $uid), settings: $settings);
		} catch (CaseAccessDeniedException $denied) {
			$outcome = $denied->getMessage();
		}

		$this->assertSame($expected, $outcome);
	}//end testAssertMayAdministerOutcome()

	/**
	 * assertIdentified() trims and refuses blanks; isAdministrator() fails closed.
	 *
	 * @return void
	 */
	public function testIdentityAndAdministrator(): void {
		$service = $this->service(backend: ['admins' => ['boss']]);
		$this->assertSame('alice', $service->assertIdentified(uid: ' alice ', verb: 'x'));

		foreach ([null, '', "\t"] as $blank) {
			try {
				$service->assertIdentified(uid: $blank, verb: 'x');
				$this->fail('A blank identity was accepted.');
			} catch (CaseAccessDeniedException $denied) {
				$this->assertSame("Verb 'x' denied: no acting identity.", $denied->getMessage());
			}
		}

		$this->assertTrue($service->isAdministrator(uid: 'boss'));
		$this->assertFalse($service->isAdministrator(uid: ' boss'));
		$this->assertFalse($service->isAdministrator(uid: 'alice'));
		$this->assertFalse($service->isAdministrator(uid: null));
		$this->assertFalse($service->isAdministrator(uid: ' '));
		$this->assertFalse($this->service(backend: ['none' => true])->isAdministrator(uid: 'boss'));
		$this->assertFalse($this->service(backend: ['throws' => true])->isAdministrator(uid: 'boss'));
	}//end testIdentityAndAdministrator()

	/**
	 * effectiveRules() returns own, nearest ancestor, then root settings, else null.
	 *
	 * @return void
	 */
	public function testEffectiveRules(): void {
		$service = $this->service(backend: []);

		[$tree, $leaf] = self::tree(rules: ['leaf' => ['a'], 'mid' => ['b'], 'plan' => ['c']]);
		$this->assertSame(['a'], $service->effectiveRules(item: $leaf, tree: $tree));
		$this->assertSame(['c'], $service->effectiveRules(item: null, tree: $tree));

		[$tree, $leaf] = self::tree(rules: ['leaf' => [], 'root' => ['r'], 'plan' => ['c']]);
		$this->assertSame(['r'], $service->effectiveRules(item: $leaf, tree: $tree));

		[$tree, $leaf] = self::tree(rules: ['plan' => 'raw']);
		$this->assertSame('raw', $service->effectiveRules(item: $leaf, tree: $tree));

		[$tree, $leaf] = self::tree(rules: []);
		$this->assertNull($service->effectiveRules(item: $leaf, tree: $tree));
	}//end testEffectiveRules()
}//end class

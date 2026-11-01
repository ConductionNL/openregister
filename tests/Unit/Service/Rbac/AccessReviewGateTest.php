<?php

/**
 * Who may read an object's grants list, and that nobody gets in by default.
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
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\AccessReviewGate;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReport;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tasks 4.2 and 4.3.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\AccessReviewGate
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantManageGuard
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 */
class AccessReviewGateTest extends TestCase {

	/**
	 * The `readGrants` rule dossiq writes on its case schema.
	 *
	 * @var array<string, mixed>
	 */
	private const ASSIGNEE_RULE = [
		'read' => ['authenticated'],
		'readGrants' => [['group' => 'authenticated', 'match' => ['assignee' => '$userId']]],
	];

	/**
	 * A gate answering for one caller on one record.
	 *
	 * @param string               $userId        The caller.
	 * @param string[]             $groups        The caller's groups.
	 * @param array<string, mixed> $resolved      The cascaded block for the record.
	 * @param bool                 $holdsRight    Whether the caller holds `grant_manage` on the record.
	 * @param callable|null        $hasPermission Stands in for PermissionHandler::hasPermission().
	 * @param array|null           $authorization The record's own `_authorization` block.
	 * @param array<string, mixed> $objectLevel   Rules the cascade adds only when it is asked with the record.
	 *
	 * @return AccessReviewGate The gate.
	 */
	private function gateFor(
		string $userId,
		array $groups = [],
		array $resolved = self::ASSIGNEE_RULE,
		bool $holdsRight = false,
		?callable $hasPermission = null,
		?array $authorization = null,
		array $objectLevel = [],
	): AccessReviewGate {
		$schema = new Schema();
		$schema->setId(7);

		$handler = $this->createMock(PermissionHandler::class);
		$handler->method('resolveAuthorization')->willReturnCallback(
			static fn (Schema $schema, ?ObjectEntity $object = null): array => ($objectLevel !== [] && $object !== null)
				? array_merge($resolved, $objectLevel)
				: $resolved
		);
		$handler->method('hasPermission')->willReturnCallback($hasPermission ?? static fn (): bool => false);
		$handler->method('hasGroupPermission')->willReturnCallback([self::class, 'entryAdmits']);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getSchema')->willReturn(7);
		$objectService->method('getPermissionHandler')->willReturn($handler);

		$report = $this->createMock(ObjectAccessReport::class);
		$report->method('schema')->willReturn($schema);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$rights = $this->createMock(OrganisationSpecialRights::class);
		$rights->method('holdsOn')->willReturn($holdsRight);

		$this->record = new ObjectEntity();
		$this->record->setUuid('case-1');
		$this->record->setOwner('bea');
		$this->record->setObject(['assignee' => 'tvisser']);
		$this->record->setAuthorization($authorization);

		return new AccessReviewGate(
			objectService: $objectService,
			report: $report,
			userSession: $session,
			groupManager: $groupManager,
			grantManage: new GrantManageGuard(new ObjectScopeResolver(), $rights)
		);
	}//end gateFor()

	/**
	 * The record the last gate answers for.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $record;

	/**
	 * The owner, an administrator and the owning group read the grants.
	 *
	 * @return void
	 */
	public function testTheOwnerTheAdministratorAndTheOwningGroupAreAdmitted(): void {
		$gate = $this->gateFor(userId: 'bea');
		$this->assertTrue($gate->mayReadGrants($this->record), 'the owner');

		$gate = $this->gateFor(userId: 'root', groups: ['admin']);
		$this->assertTrue($gate->mayReadGrants($this->record), 'an administrator');

		$gate = $this->gateFor(userId: 'kees', groups: ['team-a'], authorization: [ObjectScopeResolver::OWNER_GROUP_KEY => 'team-a']);
		$this->assertTrue($gate->mayReadGrants($this->record), 'a member of the owning group');
	}//end testTheOwnerTheAdministratorAndTheOwningGroupAreAdmitted()

	/**
	 * A holder of `manage` through a written rule is admitted to both reads.
	 *
	 * @return void
	 */
	public function testAWrittenManageHolderIsAdmitted(): void {
		$gate = $this->gateFor(
			userId: 'mvries',
			groups: ['beheerders'],
			resolved: self::ASSIGNEE_RULE + ['manage' => ['beheerders']],
			hasPermission: static fn (Schema $schema, string $action): bool => $action === 'manage'
		);

		$this->assertTrue($gate->mayReviewAccess($this->record));
		$this->assertTrue($gate->mayReadGrants($this->record));
	}//end testAWrittenManageHolderIsAdmitted()

	/**
	 * A `grant_manage` holder reads the grants, and not the `/permissions` audit view.
	 *
	 * @return void
	 */
	public function testAGrantManageHolderReadsTheGrants(): void {
		$gate = $this->gateFor(userId: 'service', groups: ['beheer'], holdsRight: true);

		$this->assertTrue($gate->mayReadGrants($this->record));
		$this->assertFalse($gate->mayReviewAccess($this->record), '/permissions keeps its own guard');
	}//end testAGrantManageHolderReadsTheGrants()

	/**
	 * The assignee reads the grants through the conditional `readGrants` rule, asked with the record.
	 *
	 * @return void
	 */
	public function testTheAssigneeIsAdmittedByTheReadGrantsRule(): void {
		$asked = [];
		$gate = $this->gateFor(
			userId: 'tvisser',
			hasPermission: function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null) use (&$asked): bool {
				$asked[] = $action;
				return $action === 'readGrants' && $object !== null && ($object->getObject()['assignee'] ?? null) === $userId;
			}
		);

		$this->assertTrue($gate->mayReadGrants($this->record));
		$this->assertContains('readGrants', $asked);
	}//end testTheAssigneeIsAdmittedByTheReadGrantsRule()

	/**
	 * A reader of the record without an admitting right is refused.
	 *
	 * @return void
	 */
	public function testAnOrdinaryReaderIsRefused(): void {
		$gate = $this->gateFor(
			userId: 'stagiair',
			resolved: ['read' => ['authenticated']],
			hasPermission: static fn (Schema $schema, string $action): bool => $action === 'read'
		);

		$this->assertFalse($gate->mayReadGrants($this->record));
	}//end testAnOrdinaryReaderIsRefused()

	/**
	 * 🔴 A schema that writes nothing admits nobody through `readGrants`.
	 *
	 * `hasPermission()` grants every verb on an empty block, so the stand-in
	 * here answers yes to everything: the gate must not ask it.
	 *
	 * @return void
	 */
	public function testASchemaThatWritesNothingIsNotOpen(): void {
		$gate = $this->gateFor(userId: 'stagiair', resolved: [], hasPermission: static fn (): bool => true);

		$this->assertFalse($gate->mayReadGrants($this->record));
	}//end testASchemaThatWritesNothingIsNotOpen()

	/**
	 * A block that writes rules but no `readGrants` admits nobody through it either.
	 *
	 * @return void
	 */
	public function testABlockWithoutReadGrantsAdmitsNobodyThroughIt(): void {
		$asked = [];
		$gate = $this->gateFor(
			userId: 'stagiair',
			resolved: ['read' => ['authenticated']],
			hasPermission: function (Schema $schema, string $action) use (&$asked): bool {
				$asked[] = $action;
				return $action === 'readGrants';
			}
		);

		$this->assertFalse($gate->mayReadGrants($this->record));
		$this->assertNotContains('readGrants', $asked);
	}//end testABlockWithoutReadGrantsAdmitsNobodyThroughIt()
	/**
	 * Stands in for PermissionHandler::hasGroupPermission() on one verb's list: a
	 * bare group, a `{group, match}` entry over the record, or `user:<uid>`.
	 * Like the real handler it answers yes to everything on a block carrying
	 * `"public": true`, so a gate that hands it more than the verb's own list
	 * is caught.
	 *
	 * @param array|null  $authorization The block.
	 * @param string      $groupId       The group being tried.
	 * @param string      $action        The verb.
	 * @param string|null $userId        The caller.
	 * @param string|null $userGroup     Unused.
	 * @param string|null $objectOwner   Unused.
	 * @param array|null  $objectData    The record.
	 *
	 * @return bool Whether an entry admits the caller through this group.
	 */
	public static function entryAdmits(?array $authorization, string $groupId, string $action, ?string $userId = null, ?string $userGroup = null, ?string $objectOwner = null, ?array $objectData = null): bool {
		if (($authorization['public'] ?? false) === true) {
			return true;
		}

		foreach (($authorization[$action] ?? []) as $entry) {
			if ($entry === $groupId || ($userId !== null && $entry === 'user:' . $userId)) {
				return true;
			}

			if (is_array($entry) === true && ($entry['group'] ?? null) === $groupId) {
				$match = ($entry['match'] ?? []);
				if ($match === [] || (($match['assignee'] ?? null) === '$userId' && ($objectData['assignee'] ?? null) === $userId)) {
					return true;
				}
			}
		}

		return false;
	}//end entryAdmits()

	/**
	 * 🔴 `"public": true` does not make every reader a `manage` holder, for either read.
	 *
	 * PermissionHandler answers yes to every verb on such a block; the stand-in
	 * here does the same.
	 *
	 * @return void
	 */
	public function testAPublicBlockGrantsNobodyManage(): void {
		$gate = $this->gateFor(userId: 'stagiair', resolved: ['public' => true, 'read' => ['public']], hasPermission: static fn (): bool => true);

		$this->assertFalse($gate->mayReviewAccess($this->record), '/permissions');
		$this->assertFalse($gate->mayReadGrants($this->record), '/grants');
	}//end testAPublicBlockGrantsNobodyManage()

	/**
	 * 🔴 With `"public": true`, a `readGrants` list still admits only the people it names.
	 *
	 * @return void
	 */
	public function testAPublicBlockKeepsReadGrantsToItsOwnList(): void {
		$block = ['public' => true, 'readGrants' => [['group' => 'auditors']]];
		$everything = static fn (): bool => true;

		$staff = $this->gateFor(userId: 'stagiair', groups: ['staff'], resolved: $block, hasPermission: $everything);
		$this->assertFalse($staff->mayReadGrants($this->record));

		$auditor = $this->gateFor(userId: 'auditor', groups: ['auditors'], resolved: $block, hasPermission: $everything);
		$this->assertTrue($auditor->mayReadGrants($this->record), 'the control: the list still admits its own group');
	}//end testAPublicBlockKeepsReadGrantsToItsOwnList()

	/**
	 * 🔴 A written list beside `"public": true` admits only its own group, on both reads.
	 *
	 * The case the fix exists for: the block names the verb, so the gate gets
	 * past the empty-list check, and only matching that list on its own keeps
	 * `public` from admitting everybody.
	 *
	 * @return void
	 */
	public function testAPublicBlockBesideAWrittenListAdmitsOnlyThatList(): void {
		$everything = static fn (): bool => true;

		$readGrants = $this->gateFor(userId: 'stagiair', groups: ['staff'], resolved: ['public' => true, 'readGrants' => [['group' => 'auditors']]], hasPermission: $everything);
		$this->assertFalse($readGrants->mayReadGrants($this->record), '/grants through readGrants');

		$manage = $this->gateFor(userId: 'stagiair', groups: ['staff'], resolved: ['public' => true, 'manage' => ['auditors']], hasPermission: $everything);
		$this->assertFalse($manage->mayReviewAccess($this->record), '/permissions through manage');
		$this->assertFalse($manage->mayReadGrants($this->record), '/grants through manage');

		$auditor = $this->gateFor(userId: 'auditor', groups: ['auditors'], resolved: ['public' => true, 'manage' => ['auditors']], hasPermission: $everything);
		$this->assertTrue($auditor->mayReviewAccess($this->record), 'the control: the list still admits its own group');
	}//end testAPublicBlockBesideAWrittenListAdmitsOnlyThatList()

	/**
	 * A `readGrants` written on the record itself admits, so the cascade is asked with the record.
	 *
	 * @return void
	 */
	public function testAnObjectLevelReadGrantsAdmits(): void {
		$gate = $this->gateFor(
			userId: 'kvries',
			resolved: ['read' => ['authenticated']],
			hasPermission: static fn (Schema $schema, string $action): bool => $action === 'readGrants',
			objectLevel: ['readGrants' => ['user:kvries']]
		);

		$this->assertTrue($gate->mayReadGrants($this->record));
	}//end testAnObjectLevelReadGrantsAdmits()

	/**
	 * An entry that names the caller does not outrank the full decision, so an enforced deny still refuses.
	 *
	 * @return void
	 */
	public function testTheFullDecisionStillHasTheLastWord(): void {
		$gate = $this->gateFor(userId: 'tvisser', hasPermission: static fn (): bool => false);

		$this->assertFalse($gate->mayReadGrants($this->record));
	}//end testTheFullDecisionStillHasTheLastWord()
}//end class

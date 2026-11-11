<?php

/**
 * Golden outcomes of AccessReviewGate's written-rule check, at the edges the scenario golden does not reach.
 *
 * Pins, on the code before writtenRuleAdmits() was split: a resolved block
 * that is not an array, a block without the action, a later group that
 * admits after earlier ones refuse (and that the first admitting group stops
 * the walk), the exact arguments the group check receives, and that the full
 * decision is asked only after a group admitted. Allowed AND refused outcomes
 * are both asserted.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\GroupRuleRequest;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\AccessReviewGate;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReport;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\AccessReviewGate
 */
final class AccessReviewGateWrittenRuleGoldenTest extends TestCase {

	/**
	 * Group checks made, as [groupId, action, userId, objectData, organisation, authorization].
	 *
	 * @var array<int, array>
	 */
	private array $groupChecks = [];

	/**
	 * Full decisions asked, as actions.
	 *
	 * @var string[]
	 */
	private array $decisions = [];

	/**
	 * A gate for a signed-in caller over the given resolved block.
	 *
	 * @param mixed    $resolved       What resolveAuthorization() returns.
	 * @param string[] $groups         The caller's groups.
	 * @param string[] $admittingGroup Groups the group check admits.
	 * @param bool     $decision       What the full decision answers.
	 *
	 * @return AccessReviewGate
	 */
	private function gate(mixed $resolved, array $groups, array $admittingGroup, bool $decision): AccessReviewGate {
		$checks    = &$this->groupChecks;
		$decisions = &$this->decisions;

		$handler = $this->createMock(PermissionHandlerInterface::class);
		$handler->method('resolveAuthorization')->willReturn($resolved);
		$handler->method('hasGroupPermission')->willReturnCallback(
			static function (GroupRuleRequest $request) use (&$checks, $admittingGroup): bool {
				$checks[] = [
					$request->groupId,
					$request->action,
					$request->userId,
					$request->objectData,
					$request->objectOrganisation,
					$request->authorization,
					$request->userGroup,
					$request->objectOwner,
				];
				return in_array($request->groupId, $admittingGroup, true)
					&& in_array($request->groupId, ($request->authorization[$request->action] ?? []), true);
			}
		);
		$handler->method('hasPermission')->willReturnCallback(
			static function (Schema $schema, string $action) use (&$decisions, $decision): bool {
				$decisions[] = $action;
				return $decision;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getPermissionHandler')->willReturn($handler);

		$schema = new Schema();
		$schema->setId(7);
		$report = $this->createMock(ObjectAccessReport::class);
		$report->method('schema')->willReturn($schema);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$rights = $this->createMock(OrganisationSpecialRights::class);
		$rights->method('holdsOn')->willReturn(false);

		return new AccessReviewGate(
			$objectService,
			$report,
			new CallerContext(userSession: $session, groupManager: $groupManager),
			new GrantManageGuard(new ObjectScopeResolver(), $rights)
		);
	}//end gate()

	/**
	 * A record owned by somebody else.
	 *
	 * @return ObjectEntity
	 */
	private function record(): ObjectEntity {
		$record = new ObjectEntity();
		$record->setUuid('case-1');
		$record->setOwner('bea');
		$record->setOrganisation('org-1');
		$record->setObject(['assignee' => 'tvisser']);
		return $record;
	}//end record()

	/**
	 * Resolved blocks that cannot admit are refused without a group check.
	 *
	 * @return void
	 */
	public function testUnusableBlocksAreRefused(): void {
		foreach (['null' => null, 'empty' => [], 'no-action' => ['read' => ['team']], 'false-list' => ['readGrants' => false]] as $label => $resolved) {
			$this->groupChecks = [];
			$this->decisions   = [];
			$gate = $this->gate($resolved, ['team'], ['team'], true);
			$this->assertFalse($gate->mayReadGrants($this->record()), $label);
			$this->assertFalse($gate->mayReviewAccess($this->record()), $label);
			$this->assertSame([], $this->groupChecks, $label);
			$this->assertSame([], $this->decisions, $label);
		}
	}//end testUnusableBlocksAreRefused()

	/**
	 * Groups are walked in order, authenticated first, and the first that admits stops the walk.
	 *
	 * @return void
	 */
	public function testALaterGroupAdmitsAndStopsTheWalk(): void {
		$resolved = ['readGrants' => ['team-b'], 'read' => ['x'], 'manage' => ['y']];
		$gate     = $this->gate($resolved, ['team-a', 'team-b', 'team-c'], ['team-b'], true);
		$record   = $this->record();

		$this->assertTrue($gate->mayReadGrants($record));

		// mayReadGrants asks the schema-manage rule first (no record), then readGrants.
		$manage = array_values(array_filter($this->groupChecks, static fn (array $c): bool => $c[1] === 'manage'));
		$grants = array_values(array_filter($this->groupChecks, static fn (array $c): bool => $c[1] === 'readGrants'));
		$this->assertSame(['authenticated', 'team-a', 'team-b', 'team-c'], array_column($manage, 0));
		$this->assertSame([null, null, ['manage' => ['y']]], [$manage[0][3], $manage[0][4], $manage[0][5]]);
		$this->assertSame(['authenticated', 'team-a', 'team-b'], array_column($grants, 0));
		$this->assertSame(
			['team-b', 'readGrants', 'anna', $record->getMatchEnvelope(), 'org-1', ['readGrants' => ['team-b']], null, null],
			$grants[2]
		);
		$this->assertSame(['readGrants'], $this->decisions);
	}//end testALaterGroupAdmitsAndStopsTheWalk()

	/**
	 * A group admits but the full decision refuses; no group admits and the decision is never asked.
	 *
	 * @return void
	 */
	public function testTheFullDecisionHasTheLastWord(): void {
		$resolved = ['readGrants' => ['team-a']];

		$gate = $this->gate($resolved, ['team-a'], ['team-a'], false);
		// Only readGrants names team-a, so only that walk reaches the decision.
		$this->assertFalse($gate->mayReadGrants($this->record()));
		$this->assertSame(['readGrants'], $this->decisions);

		$this->groupChecks = [];
		$this->decisions   = [];
		$gate = $this->gate($resolved, ['team-a', 7], [], true);
		$this->assertFalse($gate->mayReadGrants($this->record()));
		$this->assertSame(['authenticated', 'team-a', '7'], array_column($this->groupChecks, 0));
		$this->assertSame(['readGrants', 'readGrants', 'readGrants'], array_column($this->groupChecks, 1));
		$this->assertSame([], $this->decisions);
	}//end testTheFullDecisionHasTheLastWord()
}//end class

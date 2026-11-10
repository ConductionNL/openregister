<?php

/**
 * Golden outcomes of the access-review gate, pinned before its refactor.
 *
 * `/permissions` and `/grants` both ask this gate whether the caller may see
 * who else holds access to a record. Every scenario below records both
 * answers (`mayReviewAccess`, `mayReadGrants`) the gate gave before the
 * caller's session and group manager were bundled, for admitted callers
 * (owner, administrator, schema manager, owning group, grant_manage holder,
 * readGrants rule) and refused ones (ordinary reader, anonymous caller,
 * unresolvable schema or rules, the full decision saying no). `resolve()` is
 * pinned the same way: a record that cannot be read is null, never an error.
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

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
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
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins who may review a record's access and read its grants.
 */
class AccessReviewGateGoldenTest extends TestCase {

	/**
	 * Build a gate for one caller.
	 *
	 * @param ObjectService $objectService The object service double.
	 * @param ObjectAccessReport $report   The report double.
	 * @param IUserSession  $session       The caller's session.
	 * @param IGroupManager $groupManager  The group manager double.
	 * @param GrantManageGuard $guard      The grant_manage guard.
	 *
	 * @return AccessReviewGate The gate.
	 */
	private function build(
		ObjectService $objectService,
		ObjectAccessReport $report,
		IUserSession $session,
		IGroupManager $groupManager,
		GrantManageGuard $guard
	): AccessReviewGate {
		return new AccessReviewGate(
			objectService: $objectService,
			report: $report,
			caller: new CallerContext(userSession: $session, groupManager: $groupManager),
			grantManage: $guard
		);
	}//end build()

	/**
	 * The scenarios and the answers the pre-refactor code gave.
	 *
	 * Arguments: caller uid (null = anonymous), groups, resolved rules
	 * ('throw' to throw), grant_manage held, the full decision's answer per
	 * action, whether the schema resolves, the record's own authorization,
	 * expected [mayReviewAccess, mayReadGrants].
	 *
	 * @return array<string, array<int, mixed>> Scenario name => arguments.
	 */
	public static function scenarios(): array {
		$rules = [
			'read' => ['authenticated'],
			'manage' => ['beheerders'],
			'readGrants' => ['dossierteam', 'user:tvisser'],
		];
		$owningGroup = [ObjectScopeResolver::OWNER_GROUP_KEY => 'team-a'];

		return [
			'owner' => ['bea', [], $rules, false, [], true, null, [true, true]],
			'administrator' => ['root', ['admin'], $rules, false, [], true, null, [true, true]],
			'schema manager' => ['mvries', ['beheerders'], $rules, false, ['manage'], true, null, [true, true]],
			'manage rule but decision says no' => ['mvries', ['beheerders'], $rules, false, [], true, null, [false, false]],
			'owning group member' => ['kees', ['team-a'], $rules, false, [], true, $owningGroup, [false, true]],
			'grant_manage holder' => ['service', ['beheer'], $rules, true, [], true, null, [false, true]],
			'readGrants group' => ['anna', ['dossierteam'], $rules, false, ['readGrants'], true, null, [false, true]],
			'readGrants user entry' => ['tvisser', [], $rules, false, ['readGrants'], true, null, [false, true]],
			'readGrants but decision says no' => ['anna', ['dossierteam'], $rules, false, [], true, null, [false, false]],
			'ordinary reader' => ['stagiair', ['medewerkers'], $rules, false, ['read', 'readGrants', 'manage'], true, null, [false, false]],
			'anonymous' => [null, [], $rules, false, ['read', 'readGrants', 'manage'], true, null, [false, false]],
			'anonymous with authenticated manage' => [null, [], ['manage' => ['authenticated']], false, ['manage'], true, null, [true, true]],
			'schema does not resolve' => ['mvries', ['beheerders'], $rules, false, ['manage', 'readGrants'], false, null, [false, false]],
			'rules do not resolve' => ['mvries', ['beheerders'], 'throw', false, ['manage', 'readGrants'], true, null, [false, false]],
			'action list not a list' => ['anna', ['dossierteam'], ['readGrants' => 'dossierteam'], false, ['readGrants'], true, null, [false, false]],
			'empty action list' => ['anna', ['dossierteam'], ['readGrants' => []], false, ['readGrants'], true, null, [false, false]],
		];
	}//end scenarios()

	/**
	 * Both answers match the pinned ones.
	 *
	 * @param string|null           $uid           The caller.
	 * @param string[]              $groups        The caller's groups.
	 * @param array|string          $resolved      The cascaded rules, or 'throw'.
	 * @param boolean               $holdsRight    Whether grant_manage is held.
	 * @param string[]              $decisionAllows Actions the full decision admits.
	 * @param boolean               $schemaResolves Whether the schema resolves.
	 * @param array|null            $authorization The record's own authorization.
	 * @param array<int, bool>      $expected      [mayReviewAccess, mayReadGrants].
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testTheAnswersArePinned(
		?string $uid,
		array $groups,
		array|string $resolved,
		bool $holdsRight,
		array $decisionAllows,
		bool $schemaResolves,
		?array $authorization,
		array $expected
	): void {
		$handler = $this->createMock(PermissionHandlerInterface::class);
		if ($resolved === 'throw') {
			$handler->method('resolveAuthorization')->willThrowException(new RuntimeException('rules'));
		} else {
			$handler->method('resolveAuthorization')->willReturn($resolved);
		}

		$handler->method('hasGroupPermission')->willReturnCallback(
			static function (GroupRuleRequest $request): bool {
				foreach (($request->authorization[$request->action] ?? []) as $entry) {
					if ($entry === $request->groupId || ($request->userId !== null && $entry === 'user:' . $request->userId)) {
						return true;
					}
				}

				return false;
			}
		);
		$handler->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
			static fn (Schema $schema, string $action): bool => in_array($action, $decisionAllows, true)
		));

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getSchema')->willReturn(7);
		$objectService->method('getPermissionHandler')->willReturn($handler);

		$report = $this->createMock(ObjectAccessReport::class);
		if ($schemaResolves === true) {
			$schema = new Schema();
			$schema->setId(7);
			$report->method('schema')->willReturn($schema);
		} else {
			$report->method('schema')->willThrowException(new RuntimeException('schema'));
		}

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$rights = $this->createMock(OrganisationSpecialRights::class);
		$rights->method('holdsOn')->willReturn($holdsRight);

		$record = new ObjectEntity();
		$record->setUuid('case-1');
		$record->setOwner('bea');
		$record->setObject(['assignee' => 'tvisser']);
		$record->setAuthorization($authorization);

		$gate = $this->build(
			$objectService,
			$report,
			$session,
			$groupManager,
			new GrantManageGuard(new ObjectScopeResolver(), $rights)
		);

		$this->assertSame($expected, [$gate->mayReviewAccess($record), $gate->mayReadGrants($record)]);
	}//end testTheAnswersArePinned()

	/**
	 * A record that cannot be read resolves to null; a readable one to itself.
	 *
	 * @return void
	 */
	public function testResolveIsPinned(): void {
		$record = new ObjectEntity();
		$answers = [$record, new RuntimeException('not found'), null];

		foreach ($answers as $answer) {
			$objectService = $this->createMock(ObjectService::class);
			$objectService->expects($this->once())->method('setRegister')->with('reg');
			$objectService->expects($this->once())->method('setSchema')->with('sch');
			$objectService->expects($this->once())->method('setObject')->with('id-1');
			if ($answer instanceof RuntimeException) {
				$objectService->method('getObject')->willThrowException($answer);
			} else {
				$objectService->method('getObject')->willReturn($answer);
			}

			$gate = $this->build(
				$objectService,
				$this->createMock(ObjectAccessReport::class),
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				new GrantManageGuard(new ObjectScopeResolver(), $this->createMock(OrganisationSpecialRights::class))
			);

			$expected = null;
			if ($answer === $record) {
				$expected = $record;
			}

			$this->assertSame($expected, $gate->resolve(new ObjectRef('reg', 'sch', 'id-1')));
		}
	}//end testResolveIsPinned()
}//end class

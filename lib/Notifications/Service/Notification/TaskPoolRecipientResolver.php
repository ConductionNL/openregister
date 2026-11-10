<?php

/**
 * Resolves a pooled task's recipients: the members of its candidate groups
 * plus its candidate users, and nobody else.
 *
 * Fail-closed on every unresolvable input (ADR-005): an unknown group adds
 * nobody, an unknown user is dropped, an empty pool notifies nobody. There
 * is no branch that widens to "everyone".
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroup;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Notifications\Service\PrincipalDirectory;

/**
 * `kind: expression` resolver for the candidate pool.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */
class TaskPoolRecipientResolver implements RecipientResolverInterface {

	/**
	 * The start of a warning about one candidate group.
	 */
	private const GROUP_LOG = '[TaskPoolRecipientResolver] Group "%s" ';

	/**
	 * Constructor.
	 *
	 * @param PrincipalDirectory $principals Resolves group membership and confirms a candidate user exists.
	 * @param LoggerInterface    $logger     Names groups that could not be resolved.
	 */
	public function __construct(
		private readonly PrincipalDirectory $principals,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The pool's members.
	 *
	 * @param ObjectEntity $object The task adapter.
	 * @param array<string, mixed> $context Trigger context (unused: the pool is on the payload).
	 *
	 * @return array<int, string> Distinct uids.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface passes context; the pool is payload.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function resolve(ObjectEntity $object, array $context): array {
		$payload = ($object->getObject() ?? []);

		$uids = array_merge(
			$this->groupMembers(groupIds: (array)($payload['candidateGroups'] ?? [])),
			$this->existingUsers(uids: (array)($payload['candidateUsers'] ?? []))
		);

		return array_values(array_unique($uids));
	}//end resolve()

	/**
	 * The members of the named groups; an unresolvable group adds nobody.
	 *
	 * @param array<int, mixed> $groupIds The candidate group ids.
	 *
	 * @return array<int, string> Member uids.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	private function groupMembers(array $groupIds): array {
		$uids = [];
		foreach ($groupIds as $groupId) {
			if (is_string($groupId) === true && trim($groupId) !== '') {
				$uids = array_merge($uids, $this->membersOf(groupId: $groupId));
			}
		}

		return $uids;
	}//end groupMembers()

	/**
	 * The members of one group; an unresolvable group adds nobody.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return array<int, string> Member uids.
	 */
	private function membersOf(string $groupId): array {
		$group = $this->findGroup(groupId: $groupId);
		if ($group === null) {
			return [];
		}

		return array_values(array_map(static fn (IUser $user): string => $user->getUID(), $group->getUsers()));
	}//end membersOf()

	/**
	 * The group, or null (logged) when it cannot be resolved or does not exist.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return IGroup|null
	 */
	private function findGroup(string $groupId): ?IGroup {
		try {
			$group = $this->principals->groupManager->get($groupId);
		} catch (Throwable $failure) {
			$this->logger->warning(sprintf(self::GROUP_LOG.'could not be resolved; nobody is notified for it: %s', $groupId, $failure->getMessage()));
			return null;
		}

		if ($group === null) {
			$this->logger->warning(sprintf(self::GROUP_LOG.'does not exist; nobody is notified for it.', $groupId));
		}

		return $group;
	}//end findGroup()

	/**
	 * The candidate users that exist; an unknown uid is dropped.
	 *
	 * @param array<int, mixed> $uids The candidate uids.
	 *
	 * @return array<int, string> Existing uids.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	private function existingUsers(array $uids): array {
		$existing = [];
		foreach ($uids as $uid) {
			if (is_string($uid) === true && trim($uid) !== '' && $this->principals->userManager->userExists($uid) === true) {
				$existing[] = $uid;
			}
		}

		return $existing;
	}//end existingUsers()
}//end class

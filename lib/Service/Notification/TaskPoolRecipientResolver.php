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

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `kind: expression` resolver for the candidate pool.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */
class TaskPoolRecipientResolver implements RecipientResolverInterface {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Resolves group membership.
	 * @param IUserManager $userManager Confirms a candidate user exists.
	 * @param LoggerInterface $logger Names groups that could not be resolved.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
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
			if (is_string($groupId) === false || trim($groupId) === '') {
				continue;
			}

			try {
				$group = $this->groupManager->get($groupId);
			} catch (Throwable $failure) {
				$this->logger->warning(
					sprintf('[TaskPoolRecipientResolver] Group "%s" could not be resolved; nobody is notified for it: %s', $groupId, $failure->getMessage())
				);
				continue;
			}

			if ($group === null) {
				$this->logger->warning(
					sprintf('[TaskPoolRecipientResolver] Group "%s" does not exist; nobody is notified for it.', $groupId)
				);
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$uids[] = $user->getUID();
			}
		}//end foreach

		return $uids;
	}//end groupMembers()

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
			if (is_string($uid) === true && trim($uid) !== '' && $this->userManager->userExists($uid) === true) {
				$existing[] = $uid;
			}
		}

		return $existing;
	}//end existingUsers()
}//end class

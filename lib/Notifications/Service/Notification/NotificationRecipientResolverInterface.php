<?php

/**
 * NotificationRecipientResolverInterface: the public surface of NotificationRecipientResolver, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Every public method of NotificationRecipientResolver. Callers depend on this interface, not on the class.
 */
interface NotificationRecipientResolverInterface {

	/**
	 * Resolve a recipients spec to a deduplicated list of verified uids.
	 *
	 * Supported kinds: `users`, `field`, `relation`, `object-acl`,
	 * `expression`, `groups` — the dispatcher's set — plus `watchers`, spelled
	 * `{"watchers": true}`, which resolves to whoever follows the triggering
	 * object at dispatch time.
	 *
	 * @param array<int, mixed> $recipientsSpec The rule's `recipients` declaration.
	 * @param RecipientSource $source The object, its data, the trigger context and the role assignment.
	 *
	 * @return array<int, string> Verified, deduplicated uids.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function resolve(array $recipientsSpec, RecipientSource $source): array;

	/**
	 * Resolve a recipients spec, and say which entries resolved to nobody.
	 *
	 * The plain {@see resolve()} answers only "who gets told", which cannot
	 * distinguish a group that is deliberately empty from one that no longer
	 * exists. Both end a dispatch, and only the second is a fault. This method
	 * keeps the second: every entry that named something the server does not
	 * have — a deleted group, a role the schema does not assign — comes back in
	 * `unresolved` so the caller can record a failed dispatch naming it rather
	 * than delivering to nobody in silence (ADR-005, fail closed and say so).
	 *
	 * An entry that resolves to a real but EMPTY group is not unresolved: the
	 * group exists, it simply has no members, and reporting that as a fault
	 * would make every quiet team look broken.
	 *
	 * @param array<int, mixed> $recipientsSpec The rule's `recipients` declaration.
	 * @param array<string, mixed> $data The object's stored data (or a flow item's json).
	 * @param ObjectEntity|null $object The object, for `object-acl` and `expression` kinds.
	 * @param array<string, mixed> $context Trigger-specific extras handed to expression resolvers.
	 * @param array<string, array<int, string>> $roleGroups Role name to assigned group ids, for the `role` kind.
	 *
	 * @return array{uids: array<int, string>, unresolved: array<int, array{kind: string, id: string, reason: string}>}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-group-or-a-declared-role-is-a-recipient-req-nrg-001
	 */
	public function resolveWithDiagnostics(
		array $recipientsSpec, array $data, ?ObjectEntity $object = null, array $context = [], array $roleGroups = []
	): array;

	/**
	 * Verify that a uid corresponds to an actual Nextcloud user.
	 *
	 * Backed by a per-request cache; only definitive verdicts are cached. A
	 * `\Throwable` from IUserManager (transient DB/LDAP failure) is NOT a
	 * definitive "user doesn't exist" — caching it would silently drop every
	 * notification for this uid for the rest of the request, even after the
	 * underlying problem clears.
	 *
	 * @param string $uid Candidate Nextcloud user identifier.
	 *
	 * @return bool True when the uid corresponds to a real Nextcloud user.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function userExists(string $uid): bool;

	/**
	 * Whether a group id names a real group.
	 *
	 * Used by the flow messaging service to classify a literal recipient
	 * entry as a group before asking for a `groups` expansion.
	 *
	 * @param string $gid Candidate group id.
	 *
	 * @return bool True when the group exists.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function groupExists(string $gid): bool;

	/**
	 * Extract candidate UIDs from a relation value. The relation value
	 * can be:
	 *   - a string (treat as UID directly)
	 *   - an array of strings (each treated as a UID)
	 *   - an array of objects with a `userId` or `uid` field
	 *   - any nested combination of the above
	 *
	 * @param mixed $value The raw relation value.
	 *
	 * @return array<int, string>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Handles six distinct relation shapes
	 * (null, array-of-strings, array-of-objects with uid/id/userId, plain string); each
	 * shape requires a separate extraction branch that cannot be unified.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function extractUidsFromRelation(mixed $value): array;
}//end interface

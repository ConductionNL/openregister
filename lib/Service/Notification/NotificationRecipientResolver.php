<?php

/**
 * The notification subsystem's recipient resolver, as a call-shared unit.
 *
 * Extracted from AnnotationNotificationDispatcher so both callers — the
 * declarative dispatcher resolving a schema rule's recipients, and the flow
 * messaging service resolving a send node's — expand groups, verify uids and
 * walk relations through ONE implementation. A second resolver is exactly the
 * place where "who gets told" would start answering differently per caller.
 *
 * Behaviour is the dispatcher's, verbatim: every candidate uid is verified
 * against IUserManager before it may receive anything (recipient lists pull
 * strings from object data, which is writeable by anyone with `update` on the
 * object), groups are expanded to their members, and unknown ids are dropped.
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
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves a recipients spec to verified Nextcloud uids.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Six recipient kinds, each with its own
 * verification posture, plus the uid/group existence checks both callers share; PHPMD sums
 * every helper's branches into the class total.
 */
class NotificationRecipientResolver {

	/**
	 * Per-request cache for userExists() lookups.
	 *
	 * @var array<string, bool>
	 */
	private array $userExistsCache = [];

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager User resolver for uid verification.
	 * @param IGroupManager $groupManager Group resolver for `groups` recipient kinds.
	 * @param LoggerInterface $logger Logger for resolution diagnostics.
	 * @param IServerContainer|null $serverContainer Container for `expression` resolvers; null disables that kind.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly ?IServerContainer $serverContainer = null,
	) {

	}//end __construct()

	/**
	 * Resolve a recipients spec to a deduplicated list of verified uids.
	 *
	 * Supported kinds: `users`, `field`, `relation`, `object-acl`,
	 * `expression`, `groups` — the dispatcher's set — plus `watchers`, spelled
	 * `{"watchers": true}`, which resolves to whoever follows the triggering
	 * object at dispatch time.
	 *
	 * @param array<int, mixed> $recipientsSpec The rule's `recipients` declaration.
	 * @param array<string, mixed> $data The object's stored data (or a flow item's json).
	 * @param ObjectEntity|null $object The object, for `object-acl` and `expression` kinds.
	 * @param array<string, mixed> $context Trigger-specific extras handed to expression resolvers.
	 * @param array<string, array<int, string>> $roleGroups Role name to assigned group ids, for the `role` kind.
	 *
	 * @return array<int, string> Verified, deduplicated uids.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function resolve(
		array $recipientsSpec,
		array $data,
		?ObjectEntity $object = null,
		array $context = [],
		array $roleGroups = [],
	): array {
		$resolved = $this->resolveWithDiagnostics(
			recipientsSpec: $recipientsSpec,
			data: $data,
			object: $object,
			context: $context,
			roleGroups: $roleGroups
		);

		return $resolved['uids'];
	}//end resolve()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per recipient kind; each is a distinct
	 * resolution rule that cannot be merged without losing the kind's own verification posture.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Kind dispatch times per-kind guards; all required.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) One branch per recipient kind, moved verbatim
	 * from the dispatcher; splitting per kind would scatter the shared verification posture.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-group-or-a-declared-role-is-a-recipient-req-nrg-001
	 */
	public function resolveWithDiagnostics(
		array $recipientsSpec,
		array $data,
		?ObjectEntity $object = null,
		array $context = [],
		array $roleGroups = [],
	): array {
		$uids = [];
		$unresolved = [];
		foreach ($recipientsSpec as $r) {
			if (is_array($r) === false) {
				continue;
			}

			$kind = (string)($r['kind'] ?? '');

			// `{"watchers": true}` carries no `kind`: it names a subscription
			// list, not a value to look up. Normalised here so the branch below
			// reads like every other kind.
			if (($r['watchers'] ?? null) === true) {
				$kind = 'watchers';
			}

			if ($kind === 'watchers') {
				if ($object !== null) {
					foreach ($this->resolveWatcherRecipients(object: $object) as $uid) {
						$uids[] = $uid;
					}
				}

				continue;
			}

			if ($kind === 'users') {
				foreach ((array)($r['users'] ?? []) as $u) {
					if (is_string($u) === true && $u !== '' && $this->userExists(uid: $u) === true) {
						$uids[] = $u;
					}
				}

				continue;
			}

			if ($kind === 'field') {
				// The field's value comes from the object's stored data,
				// which is writeable by anyone with `update` permission
				// on the object. An attacker who controls the field
				// could otherwise direct notifications at any uid string,
				// including admins, with an attacker-shaped subject.
				// Verify the value names a real Nextcloud user before
				// adding it to the recipient list.
				$field = (string)($r['field'] ?? '');
				$value = ($data[$field] ?? null);
				if (is_string($value) === true && $value !== '' && $this->userExists(uid: $value) === true) {
					$uids[] = $value;
				}

				continue;
			}

			if ($kind === 'relation') {
				// Resolve a typed relation (declared via x-openregister-relations).
				// Same attacker-controlled-input reasoning as the `field`
				// kind above — every extracted uid is checked against
				// IUserManager::userExists().
				$relName = (string)($r['relation'] ?? '');
				if ($relName === '') {
					continue;
				}

				$value = ($data[$relName] ?? null);
				foreach ($this->extractUidsFromRelation(value: $value) as $uid) {
					if ($this->userExists(uid: $uid) === true) {
						$uids[] = $uid;
					}
				}

				continue;
			}//end if

			if ($kind === 'object-acl') {
				if ($object !== null) {
					$perm = (string)($r['permission'] ?? 'read');
					foreach ($this->resolveObjectAclRecipients(object: $object, permission: $perm) as $uid) {
						$uids[] = $uid;
					}
				}

				continue;
			}

			if ($kind === 'expression') {
				if ($object !== null) {
					$resolverTag = (string)($r['resolver'] ?? '');
					$resolved = $this->resolveExpressionRecipients(
						resolverTag: $resolverTag,
						object: $object,
						context: $context
					);
					foreach ($resolved as $uid) {
						$uids[] = $uid;
					}
				}

				continue;
			}

			if ($kind === 'groups') {
				foreach ((array)($r['groups'] ?? []) as $gid) {
					if (is_string($gid) === false || $gid === '') {
						continue;
					}

					$expanded = $this->expandGroup(gid: $gid, kind: 'groups');
					foreach ($expanded['uids'] as $uid) {
						$uids[] = $uid;
					}

					foreach ($expanded['unresolved'] as $entry) {
						$unresolved[] = $entry;
					}
				}

				continue;
			}//end if

			if ($kind === 'role') {
				// A role is the schema's own vocabulary: `authorization.roles`
				// assigns each named role a set of Nextcloud groups, the same
				// map a lifecycle transition reads. Addressing the role rather
				// than the groups is what lets the assignment change without
				// every rule being rewritten.
				$roleName = (string)($r['role'] ?? '');
				if ($roleName === '') {
					continue;
				}

				$assigned = ($roleGroups[$roleName] ?? null);
				if (is_array($assigned) === false) {
					// The rule names a role this schema does not assign. That is
					// a rule addressing nobody, which is the case this reports.
					$unresolved[] = [
						'kind' => 'role',
						'id' => $roleName,
						'reason' => 'role-not-assigned',
					];
					continue;
				}

				foreach ($assigned as $gid) {
					if (is_string($gid) === false || $gid === '') {
						continue;
					}

					$expanded = $this->expandGroup(gid: $gid, kind: 'role');
					foreach ($expanded['uids'] as $uid) {
						$uids[] = $uid;
					}

					foreach ($expanded['unresolved'] as $entry) {
						$unresolved[] = $entry;
					}
				}
			}//end if
		}//end foreach

		return [
			'uids' => array_values(array_unique($uids)),
			'unresolved' => $unresolved,
		];
	}//end resolveWithDiagnostics()

	/**
	 * Expand one group id to its members, saying so when the group is absent.
	 *
	 * Shared by the `groups` and `role` kinds so both report a vanished group
	 * the same way — a second expansion is where "the team was warned" and "the
	 * team was silently skipped" would start differing per kind.
	 *
	 * @param string $gid The Nextcloud group id.
	 * @param string $kind The recipient kind asking, carried into the report.
	 *
	 * @return array{uids: array<int, string>, unresolved: array<int, array{kind: string, id: string, reason: string}>}
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-a-group-or-a-declared-role-is-a-recipient-req-nrg-001
	 */
	private function expandGroup(string $gid, string $kind): array {
		try {
			$group = $this->groupManager->get($gid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationRecipientResolver] group "%s" lookup failed: %s', $gid, $e->getMessage())
			);
			return [
				'uids' => [],
				'unresolved' => [['kind' => $kind, 'id' => $gid, 'reason' => 'group-lookup-failed']],
			];
		}

		if ($group === null) {
			return [
				'uids' => [],
				'unresolved' => [['kind' => $kind, 'id' => $gid, 'reason' => 'group-not-found']],
			];
		}

		$uids = [];
		try {
			foreach ($group->getUsers() as $user) {
				$uids[] = $user->getUID();
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationRecipientResolver] group "%s" member read failed: %s', $gid, $e->getMessage())
			);
			return [
				'uids' => [],
				'unresolved' => [['kind' => $kind, 'id' => $gid, 'reason' => 'group-lookup-failed']],
			];
		}

		// A real group with no members is not a fault: the members are resolved
		// at dispatch, and an empty team is a quiet team, not a broken rule.
		return ['uids' => $uids, 'unresolved' => []];
	}//end expandGroup()

	/**
	 * Resolve the object's watchers, checking read at DISPATCH time.
	 *
	 * Two things happen here and both matter.
	 *
	 * The read check runs now, not when the person subscribed. Group membership
	 * changes; a case becomes sensitive. Checking only at subscribe time would
	 * leave a standing subscription that keeps delivering after the access that
	 * justified it is gone, which is the exact leak this recipient kind would
	 * otherwise introduce.
	 *
	 * And the list HEALS: a watcher who has lost read is not merely skipped, the
	 * subscription is removed, so the object's audience stops carrying people
	 * who are not in it. The removal is best-effort and never blocks a dispatch.
	 *
	 * An EMPTY readable-user list means "no targeted audience" — the schema's
	 * read rule is open, or the authorization could not be resolved and the
	 * evaluator failed closed by returning nothing. Neither is evidence that a
	 * particular watcher lost access, so nothing is dropped in that case and
	 * every watcher is kept.
	 *
	 * @param ObjectEntity $object The triggering object.
	 *
	 * @return array<int, string> The watching uids that may still read the object.
	 *
	 * @spec openspec/changes/object-watchers/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
	 */
	private function resolveWatcherRecipients(ObjectEntity $object): array {
		if ($this->serverContainer === null) {
			return [];
		}

		try {
			$watchers = $this->serverContainer->get(WatcherService::class);
			$uids = $watchers->watcherUids(objectUuid: (string)$object->getUuid());
			if ($uids === []) {
				return [];
			}

			$readable = $this->serverContainer->get(PermissionHandler::class)
				->getReadableByUsers(object: $object);
			if ($readable === []) {
				return $uids;
			}

			$kept = [];
			$lost = [];
			foreach ($uids as $uid) {
				if (in_array($uid, $readable, true) === true) {
					$kept[] = $uid;
					continue;
				}

				$lost[] = $uid;
			}

			if ($lost !== []) {
				$watchers->dropWatchers(objectUuid: (string)$object->getUuid(), userIds: $lost);
			}

			return $kept;
		} catch (\Throwable $e) {
			// Fail CLOSED: an unresolvable watcher list tells nobody rather than
			// telling everybody.
			$this->logger->warning(
				sprintf('[NotificationRecipientResolver] watcher resolution failed: %s', $e->getMessage())
			);
			return [];
		}//end try
	}//end resolveWatcherRecipients()

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
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function userExists(string $uid): bool {
		if ($uid === '') {
			return false;
		}

		if (isset($this->userExistsCache[$uid]) === true) {
			return $this->userExistsCache[$uid];
		}

		try {
			$exists = $this->userManager->userExists($uid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationRecipientResolver] userExists check failed for "%s" (not cached, will retry): %s', $uid, $e->getMessage())
			);
			return false;
		}

		$this->userExistsCache[$uid] = (bool)$exists;
		return $this->userExistsCache[$uid];
	}//end userExists()

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
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function groupExists(string $gid): bool {
		if ($gid === '') {
			return false;
		}

		try {
			return $this->groupManager->groupExists($gid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationRecipientResolver] groupExists check failed for "%s": %s', $gid, $e->getMessage())
			);
			return false;
		}
	}//end groupExists()

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
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function extractUidsFromRelation(mixed $value): array {
		if ($value === null) {
			return [];
		}

		if (is_string($value) === true && $value !== '') {
			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && $entry !== '') {
				$out[] = $entry;
				continue;
			}

			if (is_array($entry) === true) {
				$candidate = ($entry['userId'] ?? $entry['uid'] ?? $entry['user_id'] ?? null);
				if (is_string($candidate) === true && $candidate !== '') {
					$out[] = $candidate;
				}
			}
		}

		return $out;
	}//end extractUidsFromRelation()

	/**
	 * Resolve recipients from the object's per-object ACL.
	 *
	 * V1 implementation: best-effort. Reads the object's `groups` and
	 * `owner` fields directly. Per-object ACL granularity (read vs
	 * manage) is treated as: `read` matches any user/group in the ACL;
	 * `manage` matches only the object owner.
	 *
	 * @param ObjectEntity $object The object whose ACL should be read.
	 * @param string $permission The required permission (`read` or `manage`).
	 *
	 * @return array<int, string>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Walks owner, per-user, per-role and per-group
	 * ACL entries; each needs its own null-guard and uid extraction.
	 */
	private function resolveObjectAclRecipients(ObjectEntity $object, string $permission): array {
		$uids = [];
		$owner = $object->getOwner();
		if (is_string($owner) === true && $owner !== '') {
			$uids[] = $owner;
		}

		if ($permission === 'manage') {
			return $uids;
		}

		// Read permission: also include groups via getGroups(). The
		// Entity base uses __call magic for accessors, so method_exists()
		// is unreliable — fall through and let the magic call surface
		// the value (or throw, which is caught below).
		try {
			$groupsRaw = $object->getGroups();
			if (is_array($groupsRaw) === true) {
				foreach ($groupsRaw as $gid) {
					if (is_string($gid) === false || $gid === '') {
						continue;
					}

					$group = $this->groupManager->get($gid);
					if ($group === null) {
						continue;
					}

					foreach ($group->getUsers() as $user) {
						$uids[] = $user->getUID();
					}
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationRecipientResolver] object-acl read resolution failed: %s', $e->getMessage())
			);
		}//end try

		return $uids;
	}//end resolveObjectAclRecipients()

	/**
	 * Resolve recipients via a DI-tagged RecipientResolverInterface.
	 *
	 * Looks up the resolver via the injected IServerContainer so apps
	 * can register their resolver class by FQCN and have NC autowire
	 * its dependencies. Skips silently when the resolver doesn't exist
	 * or doesn't implement the interface.
	 *
	 * @param string $resolverTag DI tag (or FQCN) of the resolver service.
	 * @param ObjectEntity $object The object whose recipients are being resolved.
	 * @param array<string, mixed> $context Per-event context passed through to the resolver.
	 *
	 * @return array<int, string>
	 */
	private function resolveExpressionRecipients(string $resolverTag, ObjectEntity $object, array $context): array {
		if ($resolverTag === '' || $this->serverContainer === null) {
			return [];
		}

		try {
			$resolver = $this->serverContainer->get($resolverTag);
			if (($resolver instanceof RecipientResolverInterface) === false) {
				$this->logger->warning(
					sprintf('[NotificationRecipientResolver] expression resolver "%s" does not implement RecipientResolverInterface', $resolverTag)
				);
				return [];
			}

			return array_values($resolver->resolve($object, $context));
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NotificationRecipientResolver] expression resolver "%s" failed: %s', $resolverTag, $e->getMessage())
			);
			return [];
		}
	}//end resolveExpressionRecipients()
}//end class

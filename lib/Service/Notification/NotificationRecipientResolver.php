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
	 * `expression`, `groups` — the dispatcher's set, unchanged.
	 *
	 * @param array<int, mixed> $recipientsSpec The rule's `recipients` declaration.
	 * @param array<string, mixed> $data The object's stored data (or a flow item's json).
	 * @param ObjectEntity|null $object The object, for `object-acl` and `expression` kinds.
	 * @param array<string, mixed> $context Trigger-specific extras handed to expression resolvers.
	 *
	 * @return array<int, string> Verified, deduplicated uids.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per recipient kind; each is a distinct
	 * resolution rule that cannot be merged without losing the kind's own verification posture.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Kind dispatch times per-kind guards; all required.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) One branch per recipient kind, moved verbatim
	 * from the dispatcher; splitting per kind would scatter the shared verification posture.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function resolve(array $recipientsSpec, array $data, ?ObjectEntity $object = null, array $context = []): array {
		$uids = [];
		foreach ($recipientsSpec as $r) {
			if (is_array($r) === false) {
				continue;
			}

			$kind = (string)($r['kind'] ?? '');
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

					try {
						$group = $this->groupManager->get($gid);
						if ($group === null) {
							continue;
						}

						foreach ($group->getUsers() as $user) {
							$uids[] = $user->getUID();
						}
					} catch (\Throwable $e) {
						$this->logger->warning(
							sprintf('[NotificationRecipientResolver] group "%s" lookup failed: %s', $gid, $e->getMessage())
						);
					}
				}
			}//end if
		}//end foreach

		return array_values(array_unique($uids));
	}//end resolve()

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

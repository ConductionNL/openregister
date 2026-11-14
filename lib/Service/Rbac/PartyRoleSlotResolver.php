<?php

/**
 * A grant to a slot: whoever holds a party role on the record, right now.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the slot a rule entry names, and who occupies it on one object.
 *
 * A per-object grant may name a party role on the record instead of a
 * principal: `{"partyRole": "behandelaar"}` in an action's rule list of the
 * object's own `_authorization` block. The role is the one
 * `party-roles-beyond-the-requester` (REQ-PRM-001) and `people-on-objects`
 * keep in the link table: one row per person per role per object, with a
 * period. Nothing new is stored and no grant is ever copied onto a person.
 *
 * RESOLVED AT EVALUATION, NEVER COPIED (design D-1). The occupant is read
 * from the link rows each time the question is asked, so replacing the
 * occupant moves the access with no grant edited, and an occupant whose
 * period has ended holds nothing from that day on.
 *
 * WHO CAN OCCUPY A SLOT FOR ACCESS. Only a link naming a Nextcloud user: a
 * party without an account and a CardDAV contact cannot sign in, so neither
 * can be the caller a verdict is about. They hold the role; they never hold
 * access through it.
 *
 * FAIL CLOSED, EVERYWHERE. A missing or malformed role value names no slot;
 * a blank caller or object holds none; a lookup that throws holds none. Every
 * one of those answers "nobody holds this slot", which hides access rather
 * than handing it out.
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
 */
class PartyRoleSlotResolver {

	/**
	 * The rule-entry key naming the slot.
	 *
	 * @var string
	 */
	public const ENTRY_KEY = 'partyRole';

	/**
	 * Keys of an authorization block that are settings, not rule lists.
	 *
	 * @var string[]
	 */
	private const NON_RULE_KEYS = ['scope', 'ownerGroup', 'roles', 'deny', 'public', 'matrix', 'onUndecidable'];

	/**
	 * Constructor.
	 *
	 * @param ContactLinkMapperInterface $links  The link rows a party role lives in.
	 * @param LoggerInterface            $logger Says why a lookup answered "nobody".
	 */
	public function __construct(
		private readonly ContactLinkMapperInterface $links,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The role a rule entry names as its slot, or null when it names none.
	 *
	 * Only an array entry whose `partyRole` is a non-blank string names a slot.
	 * A number, a list, null or a blank string is malformed and names nothing,
	 * so it can never match an occupant.
	 *
	 * @param mixed $entry One entry of an action's rule list.
	 *
	 * @return string|null The role, trimmed, or null.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
	 */
	public static function slotOf(mixed $entry): ?string {
		if (is_array($entry) === false || is_string($entry[self::ENTRY_KEY] ?? null) === false) {
			return null;
		}

		$role = trim($entry[self::ENTRY_KEY]);
		if ($role === '') {
			return null;
		}

		return $role;
	}//end slotOf()

	/**
	 * The entries of one rule list that name a slot.
	 *
	 * @param mixed $entries The action's rule list; anything but a list names none.
	 *
	 * @return array<int, array> The slot entries, in order.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
	 */
	public static function slotEntriesOf(mixed $entries): array {
		if (is_array($entries) === false) {
			return [];
		}

		return array_values(array_filter($entries, static fn (mixed $entry): bool => self::slotOf(entry: $entry) !== null));
	}//end slotEntriesOf()

	/**
	 * Every slot a block names, keyed by the action whose rule list names it.
	 *
	 * @param mixed $block An authorization block, typically an object's own.
	 *
	 * @return array<string, array<int, string>> Action => the roles its rules name.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
	 */
	public static function slotsNamedIn(mixed $block): array {
		if (is_array($block) === false) {
			return [];
		}

		$slots = [];
		foreach ($block as $action => $entries) {
			if (is_string($action) === false || in_array($action, self::NON_RULE_KEYS, true) === true) {
				continue;
			}

			$roles = array_map(static fn (array $entry): string => (string)self::slotOf(entry: $entry), self::slotEntriesOf(entries: $entries));
			if ($roles !== []) {
				$slots[$action] = array_values(array_unique($roles));
			}
		}

		return $slots;
	}//end slotsNamedIn()

	/**
	 * The roles a user occupies on one object at this moment.
	 *
	 * Read from the link rows on every call: the occupant is whoever holds the
	 * role now, not whoever held it when a grant was written. A link counts
	 * only when it names this Nextcloud user and its period covers today.
	 *
	 * @param string|null $userId     The caller.
	 * @param string|null $objectUuid The object.
	 *
	 * @return array<int, string> The roles held, empty when none or on any failure.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-a-grant-may-name-a-role-slot-on-the-record-rather-than-a-principal-req-gsr-001
	 */
	public function rolesHeldBy(?string $userId, ?string $objectUuid): array {
		if ($userId === null || $userId === '' || $objectUuid === null || $objectUuid === '') {
			return [];
		}

		try {
			$links = $this->links->findByObjectUuid($objectUuid);
		} catch (Throwable $e) {
			// Fail CLOSED: a slot whose occupant cannot be read is held by
			// nobody for this question.
			$this->logger->warning(
				message: '[PartyRoleSlotResolver] Could not read the role occupants; the slot grants nothing',
				context: ['file' => __FILE__, 'line' => __LINE__, 'objectUuid' => $objectUuid, 'error' => $e->getMessage()]
			);
			return [];
		}

		$roles = [];
		foreach ($links as $link) {
			$role = $this->occupiedRole(link: $link, userId: $userId);
			if ($role !== null) {
				$roles[$role] = true;
			}
		}

		return array_keys($roles);
	}//end rolesHeldBy()

	/**
	 * The role a link gives this user today, or null when it gives none.
	 *
	 * @param mixed  $link   One link row.
	 * @param string $userId The caller.
	 *
	 * @return string|null The role.
	 */
	private function occupiedRole(mixed $link, string $userId): ?string {
		if (($link instanceof ContactLink) === false || $link->isUserLink() === false || $link->getUserId() !== $userId) {
			return null;
		}

		$role = trim((string)($link->getRole() ?? ''));
		if ($role === '') {
			return null;
		}

		try {
			if ($link->isActiveOn() === false) {
				return null;
			}
		} catch (Throwable $e) {
			return null;
		}

		return $role;
	}//end occupiedRole()
}//end class

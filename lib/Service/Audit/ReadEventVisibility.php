<?php

/**
 * ReadEventVisibility - who may see that somebody read an object
 *
 * A `read` audit entry says who opened a record and when. On a case about a
 * family or a health matter that is itself personal data, so it is not handed
 * to every reader of the object the way its changes are. Administrators always
 * see read entries; anyone else only when they are in one of the groups the
 * setting `audit.readEventsVisibleTo` lists (a JSON list of group ids, empty by
 * default). Everyone else gets the history without its read entries.
 *
 * The rule is applied where a non-admin is handed audit entries: one object's
 * history (ObjectHistoryForReaders) and the readable feed
 * (ReadableAuditTrailLister). A client that offers "also who read it" asks the
 * history's `readEventsVisible`, so the offer follows this rule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Throwable;

/**
 * Decides whether the current caller may see `read` audit entries, and drops them when not.
 *
 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
 */
class ReadEventVisibility {

	/**
	 * The app the setting lives under.
	 *
	 * @var string
	 */
	private const APP = 'openregister';

	/**
	 * The setting: a JSON list of the group ids that see read entries.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'audit.readEventsVisibleTo';

	/**
	 * The audit action of a read.
	 *
	 * @var string
	 */
	public const READ_ACTION = 'read';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig    Where the groups are configured.
	 * @param IGroupManager $groupManager Administrators and group membership.
	 * @param IUserSession  $userSession  The caller.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Whether this user may see read entries: an administrator, or a member of a configured group.
	 *
	 * Fails closed: no user, an unreadable setting or a failing group lookup is "no".
	 *
	 * @param string|null $userId The user, or null for the session's.
	 *
	 * @return bool True when read entries may be shown.
	 *
	 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
	 */
	public function maySeeReadEvents(?string $userId = null): bool {
		if ($userId === null) {
			$userId = $this->userSession->getUser()?->getUID();
		}

		if ($userId === null || $userId === '') {
			return false;
		}

		try {
			if ($this->groupManager->isAdmin($userId) === true) {
				return true;
			}

			foreach ($this->groups() as $group) {
				if ($this->groupManager->isInGroup($userId, $group) === true) {
					return true;
				}
			}
		} catch (Throwable $e) {
			return false;
		}

		return false;
	}//end maySeeReadEvents()

	/**
	 * Whether an audit action is a read.
	 *
	 * @param mixed $action The entry's action.
	 *
	 * @return bool True for a read.
	 *
		 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
	 */
	public function isReadAction(mixed $action): bool {
		return is_string($action) === true && strtolower($action) === self::READ_ACTION;
	}//end isReadAction()

	/**
	 * The entries without their read entries, unless this caller may see them.
	 *
	 * @param array<int, array> $entries The entries, each with an `action`.
	 *
	 * @return array<int, array> The entries this caller is given.
	 *
		 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
	 */
	public function filter(array $entries): array {
		if ($this->maySeeReadEvents() === true) {
			return $entries;
		}

		return array_values(
			array_filter($entries, fn (array $entry): bool => $this->isReadAction(action: ($entry['action'] ?? null)) === false)
		);
	}//end filter()

	/**
	 * The configured group ids.
	 *
	 * @return array<int, string> The groups; empty when unset or unreadable.
	 */
	private function groups(): array {
		$raw = $this->appConfig->getValueString(self::APP, self::CONFIG_KEY, '[]');
		$groups = json_decode($raw, true);
		if (is_array($groups) === false) {
			return [];
		}

		return array_values(array_filter($groups, static fn ($group): bool => is_string($group) === true && $group !== ''));
	}//end groups()
}//end class

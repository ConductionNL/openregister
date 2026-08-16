<?php

/**
 * OpenRegister Gdpr CaseAccessControl
 *
 * Case-level access-control check layered ON TOP OF OpenRegister object RBAC
 * (ADR-022, ADR-023). Object RBAC (enforced by ObjectService on every read/write)
 * decides whether the caller may touch the object at all; this check FURTHER
 * RESTRICTS a broadly-authorised caller to the cases assigned to them
 * (handler-scopes-own), unless they hold the configured officer role, which
 * overrides across cases. It never WIDENS access beyond object RBAC.
 *
 * Fail-closed contract (CWE-863 / OWASP A01): an anonymous caller is denied; a
 * caller who is neither the case handler nor a resolvable officer is denied; and
 * if the officer-role determination cannot be resolved (no session user, group
 * lookup unavailable), access is DENIED rather than skipped. There is no
 * `catch → return null → caller-skips-check` fail-open path — the method returns
 * a hard boolean and every non-affirmative outcome denies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Case
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

namespace OCA\OpenRegister\Service\Gdpr\Case;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handler-scopes-own + officer-override case-level access control (fail-closed).
 */
class CaseAccessControl {

	/**
	 * App id for app-config lookups.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * App-config key naming the group that holds the DSAR officer role.
	 *
	 * @var string
	 */
	public const CONFIG_KEY_OFFICER_GROUP = 'dsar_officer_group';

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Current caller.
	 * @param IGroupManager $groupManager Group membership resolver for the officer role.
	 * @param IAppConfig $appConfig Reads the configured officer group.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the current caller may act on the given case.
	 *
	 * Object RBAC is assumed already satisfied by ObjectService on the load;
	 * this narrows to: caller IS the case handler, OR caller holds the
	 * configured officer role. Any inability to resolve the caller or the
	 * officer role denies.
	 *
	 * @param array<string, mixed> $case The loaded case payload (must carry `handler`).
	 *
	 * @return bool True when the caller may act on the case; false (fail-closed) otherwise.
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-case-api/spec.md
	 */
	public function mayAct(array $case): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			// No session identity → cannot establish handler-scope or officer
			// role → deny (fail closed).
			return false;
		}

		$callerId = $user->getUID();

		// Handler-scopes-own: the caller is the assigned handler of this case.
		$handler = (string)($case['handler'] ?? '');
		if ($handler !== '' && $handler === $callerId) {
			return true;
		}

		// Officer override: the caller holds the configured officer role.
		return $this->isOfficer(user: $user);
	}//end mayAct()

	/**
	 * Whether the given user holds the configured DSAR officer role.
	 *
	 * Fail-closed: an unset/empty officer-group configuration, or a group
	 * lookup that cannot be performed, denies (returns false) — it never treats
	 * "cannot determine" as "grant".
	 *
	 * @param \OCP\IUser $user The user to test.
	 *
	 * @return bool True only when the user is confirmed to be in the officer group.
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-case-api/spec.md
	 */
	private function isOfficer(\OCP\IUser $user): bool {
		$officerGroup = '';
		try {
			$officerGroup = (string)$this->appConfig->getValueString(
				app: self::APP_ID,
				key: self::CONFIG_KEY_OFFICER_GROUP,
				default: ''
			);
		} catch (\Throwable $e) {
			// Cannot read the configuration → cannot confirm the officer role →
			// deny (fail closed). We do NOT swallow this into a "skip".
			$this->logger->warning(
				message: '[CaseAccessControl] officer-group config unreadable — denying (fail closed): ' . $e->getMessage()
			);
			return false;
		}

		if ($officerGroup === '') {
			// No officer role configured → no override is possible → deny.
			return false;
		}

		return $this->groupManager->isInGroup($user->getUID(), $officerGroup);
	}//end isOfficer()
}//end class

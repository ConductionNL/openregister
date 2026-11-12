<?php

/**
 * OpenRegister BundleCrossingGuard
 *
 * Refuses a shared bundle whose schemas let a rule cross organisations, unless
 * an administrator installs it. An install writes as the system, so the marker
 * has to be judged here, against the person who asked for the install.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
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
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCA\OpenRegister\Service\Rbac\CrossOrganisationMarkerChange;
use OCP\IGroupManager;
use OCP\IUserSession;
use RuntimeException;

/**
 * Judges the schemas of a bundle for the signed-in user.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
class BundleCrossingGuard {

	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  The session of the person installing.
	 * @param IGroupManager $groupManager Resolves administrator status.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Refuse the bundle when it holds a marked rule and the caller is no administrator.
	 *
	 * @param array<string, mixed> $bundle The bundle.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the bundle may not be installed by this caller.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function assertAllowed(array $bundle): void {
		if ($this->holdsCrossing(bundle: $bundle) === false) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			throw new RuntimeException('Only an administrator may install a bundle that lets a rule read across organisations (crossOrganisation: true)');
		}
	}//end assertAllowed()

	/**
	 * Whether any schema of the bundle holds a marked rule.
	 *
	 * @param array<string, mixed> $bundle The bundle.
	 *
	 * @return bool True when a schema's authorization carries `crossOrganisation: true`.
	 */
	private function holdsCrossing(array $bundle): bool {
		$schemas = ($bundle['components']['schemas'] ?? []);
		if (is_array($schemas) === false) {
			return false;
		}

		$change = new CrossOrganisationMarkerChange();
		foreach ($schemas as $document) {
			if ($change->widens(old: null, new: ($document['authorization'] ?? null)) === true) {
				return true;
			}
		}

		return false;
	}//end holdsCrossing()
}//end class

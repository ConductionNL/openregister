<?php

/**
 * Who may publish and install shared configuration.
 *
 * The source allowlist governs WHERE a bundle may come from; this governs WHO on
 * this instance may push configuration out or pull it in. Beyond "any admin", an
 * org can nominate the groups allowed to publish and to install via app config.
 * Following the source-allowlist idiom, an empty group list means "not yet
 * enforced" (any signed-in user may act), so this is safe to ship before an org
 * curates its roles; admins are always allowed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Per-org role gating for publish and install.
 */
class FederatedConfigAccess {

	/**
	 * The app its config lives under.
	 */
	private const APP_ID = 'openregister';

	/**
	 * App-config key: comma-separated groups allowed to publish.
	 */
	private const PUBLISH_GROUPS = 'federated_config_publish_groups';

	/**
	 * App-config key: comma-separated groups allowed to install.
	 */
	private const INSTALL_GROUPS = 'federated_config_install_groups';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Resolves group membership and admin.
	 * @param IAppConfig $appConfig Reads the allowed-group lists.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Whether a user may publish configuration.
	 *
	 * @param IUser|null $user The acting user (null = no session).
	 *
	 * @return boolean Whether publishing is allowed.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function canPublish(?IUser $user): bool {
		return $this->isAllowed(user: $user, key: self::PUBLISH_GROUPS);
	}//end canPublish()

	/**
	 * Whether a user may install configuration.
	 *
	 * @param IUser|null $user The acting user (null = no session).
	 *
	 * @return boolean Whether installing is allowed.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function canInstall(?IUser $user): bool {
		return $this->isAllowed(user: $user, key: self::INSTALL_GROUPS);
	}//end canInstall()

	/**
	 * Whether a user satisfies a group allowlist.
	 *
	 * @param IUser|null $user The acting user.
	 * @param string $key The app-config key holding the allowed groups.
	 *
	 * @return boolean Whether allowed.
	 */
	private function isAllowed(?IUser $user, string $key): bool {
		if ($user === null) {
			return false;
		}

		// Admins may always act.
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		$raw = trim($this->appConfig->getValueString(self::APP_ID, $key, ''));
		if ($raw === '') {
			// Not yet enforced — any signed-in user may act.
			return true;
		}

		$allowed = array_filter(array_map('trim', explode(',', $raw)));
		foreach ($this->groupManager->getUserGroupIds($user) as $groupId) {
			if (in_array($groupId, $allowed, true) === true) {
				return true;
			}
		}

		return false;
	}//end isAllowed()
}//end class

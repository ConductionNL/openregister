<?php

/**
 * FederatedConfigAccessInterface: what callers outside the component use of FederatedConfigAccess.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCP\IUser;

/**
 * Declares only what callers outside the component use of FederatedConfigAccess; the rest
 * of the class stays its own.
 */
interface FederatedConfigAccessInterface {

	/**
	 * Whether a user may publish configuration.
	 *
	 * @param IUser|null $user The acting user (null = no session).
	 *
	 * @return boolean Whether publishing is allowed.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function canPublish(?IUser $user): bool;

	/**
	 * Whether a user may install configuration.
	 *
	 * @param IUser|null $user The acting user (null = no session).
	 *
	 * @return boolean Whether installing is allowed.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function canInstall(?IUser $user): bool;
}//end interface

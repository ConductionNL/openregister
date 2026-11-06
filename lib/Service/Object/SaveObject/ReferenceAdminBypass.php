<?php

/**
 * OpenRegister ReferenceAdminBypass
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Whether the current user skips reference validation.
 *
 * True when the session user is an administrator and the
 * `reference_validation_admin_bypass` app-config flag resolves to true, its
 * default. The collaborators are optional, as on SaveObject: without one of them
 * validation runs. `occ config:app:set openregister
 * reference_validation_admin_bypass --value=false` enforces it for every user.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class ReferenceAdminBypass {

	/**
	 * The app holding the flag.
	 */
	private const APP_ID = 'openregister';

	/**
	 * The app-config key of the flag.
	 */
	private const CONFIG_KEY = 'reference_validation_admin_bypass';

	/**
	 * @param IGroupManager|null $groupManager Says who is an administrator.
	 * @param IAppConfig|null    $appConfig    Holds the flag.
	 * @param IUserSession       $userSession  Names the current user.
	 */
	public function __construct(
		private readonly ?IGroupManager $groupManager,
		private readonly ?IAppConfig $appConfig,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Whether the current user skips reference validation.
	 *
	 * @return bool True when the bypass applies.
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function applies(): bool {
		if ($this->isSwitchedOn() === false) {
			return false;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager?->isAdmin($user->getUID()) === true;
	}//end applies()

	/**
	 * Whether the bypass can apply at all on this instance.
	 *
	 * @return bool True when both collaborators exist and the flag is on.
	 */
	private function isSwitchedOn(): bool {
		if ($this->groupManager === null || $this->appConfig === null) {
			return false;
		}

		return filter_var(
			$this->appConfig->getValueString(app: self::APP_ID, key: self::CONFIG_KEY, default: 'true'),
			FILTER_VALIDATE_BOOLEAN
		);
	}//end isSwitchedOn()
}//end class

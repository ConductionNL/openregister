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

use OCA\OpenRegister\Service\Rbac\AdminGate;
use OCP\IAppConfig;

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
	 * @param AdminGate       $adminGate Answers whether the current user is an administrator.
	 * @param IAppConfig|null $appConfig Holds the flag.
	 */
	public function __construct(
		private readonly AdminGate $adminGate,
		private readonly ?IAppConfig $appConfig,
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
		if ($this->appConfig === null) {
			return false;
		}

		$enabled = filter_var(
			$this->appConfig->getValueString(app: self::APP_ID, key: self::CONFIG_KEY, default: 'true'),
			FILTER_VALIDATE_BOOLEAN
		);

		return $enabled === true && $this->adminGate->isAdmin() === true;
	}//end applies()
}//end class

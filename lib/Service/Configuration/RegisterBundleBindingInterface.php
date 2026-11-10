<?php

/**
 * RegisterBundleBindingInterface: what callers outside the component use of RegisterBundleBinding.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Register;

/**
 * Declares only what callers outside the component use of RegisterBundleBinding; the rest
 * of the class stays its own.
 */
interface RegisterBundleBindingInterface {

	/**
	 * Why this bundle may not be imported into this register by a manager.
	 *
	 * @param Register             $register The register the bundle was uploaded to.
	 * @param array<string, mixed> $bundle   The bundle.
	 *
	 * @return string|null The reason, or null when the bundle stays inside the register.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-a-bundle-uploaded-by-a-register-manager-stays-inside-that-register
	 */
	public function refusalFor(Register $register, array $bundle): ?string;
}//end interface

<?php

/**
 * OpenRegister edepot settings controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The service bundles of the edepot settings API.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
 */
final class EdepotSettingsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param EdepotSettingsDomain   $domain   The domain bundle.
	 * @param ConnectionReporting $platform The platform bundle.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function __construct(
		public readonly EdepotSettingsDomain $domain,
		public readonly ConnectionReporting $platform,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister settings controller services
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
 * The service bundles of the settings API.
 *
 * @spec openspec/specs/production-observability/spec.md
 */
final class SettingsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param SettingsStore   $store   The store bundle.
	 * @param SettingsRuntime $runtime The runtime bundle.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function __construct(
		public readonly SettingsStore $store,
		public readonly SettingsRuntime $runtime,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister flow controller services
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
 * The service bundles of the flow API.
 *
 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
 */
final class FlowControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowCatalogue   $catalogue   The catalogue bundle.
	 * @param FlowDefinitions $definitions The definitions bundle.
	 *
	 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
	 */
	public function __construct(
		public readonly FlowCatalogue $catalogue,
		public readonly FlowDefinitions $definitions,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister flow node run controller services
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
 * The service bundles of the flow node run API.
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */
final class FlowNodeRunControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowNodeRunMappers   $mappers   The mappers bundle.
	 * @param FlowNodeRunGraph     $graph     The graph bundle.
	 * @param FlowNodeRunExecution $execution The execution bundle.
	 * @param DsarCasePlatform     $platform  The platform bundle.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
	 */
	public function __construct(
		public readonly FlowNodeRunMappers $mappers,
		public readonly FlowNodeRunGraph $graph,
		public readonly FlowNodeRunExecution $execution,
		public readonly DsarCasePlatform $platform,
	) {
	}//end __construct()
}//end class

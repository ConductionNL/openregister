<?php

/**
 * The collaborators the flow engine routes, places items and attributes with.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The collaborators the flow engine routes, places items and attributes with.
 *
 * Every one is defaulted: the router and the placement hold no state, so a
 * locally-made one is indistinguishable from an injected one, and the
 * attribution stack and the mootness hook are optional so the engine stays
 * unit-testable without a container.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowEngineServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowTokenRouter       $router     Decides which exit a token takes.
	 * @param FlowItemPlacement     $placement  Decides which items sit on which place.
	 * @param FlowRunContext|null   $runContext The ambient attribution stack; absent,
	 *                                          writes are simply unattributed rather
	 *                                          than mis-attributed.
	 * @param FlowTaskMootness|null $mootness   Told which places a routing decision
	 *                                          cleared, so a user task waiting on one
	 *                                          of them is terminated rather than
	 *                                          orphaned; absent, run-terminality
	 *                                          propagation is the only backstop.
	 */
	public function __construct(
		public readonly FlowTokenRouter $router=new FlowTokenRouter(),
		public readonly FlowItemPlacement $placement=new FlowItemPlacement(),
		public readonly ?FlowRunContext $runContext=null,
		public readonly ?FlowTaskMootness $mootness=null,
	) {
	}//end __construct()
}//end class

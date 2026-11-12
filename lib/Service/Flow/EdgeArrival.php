<?php

/**
 * EdgeArrival
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * One edge arriving at one of its target nodes.
 *
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */
final class EdgeArrival {

	/**
	 * Constructor.
	 *
	 * @param string $nodeId The target node.
	 * @param string $edgeId The edge arriving at it.
	 */
	public function __construct(
		public readonly string $nodeId,
		public readonly string $edgeId,
	) {
	}//end __construct()
}//end class

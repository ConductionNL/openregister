<?php

/**
 * FlowNodeExits: one firing node's exits, read within its flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The flow document, the node that fired and the output places its transition
 * can reach. Built by FlowTokenRouter for one routing decision and never leaves it.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class FlowNodeExits {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $flow       The flow document.
	 * @param string               $nodeId     The node that fired (the transition name).
	 * @param array<int, string>   $candidates The transition's output places.
	 */
	public function __construct(
		public readonly array $flow,
		public readonly string $nodeId,
		public readonly array $candidates,
	) {
	}//end __construct()
}//end class

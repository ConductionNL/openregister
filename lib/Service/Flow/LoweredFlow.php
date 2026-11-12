<?php

/**
 * A flow document's validated nodes and normalised edges, as the lowering
 * into a Petri net reads them.
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
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The nodes and edges {@see FlowDefinitionBuilder} lowers, carried together.
 */
final class LoweredFlow {

	/**
	 * Constructor.
	 *
	 * @param array<string, array>                                              $nodes Node id => node, in declaration order.
	 * @param array<int, array{id: string, from: array<string>, to: array<string>}> $edges The normalised edges.
	 */
	public function __construct(
		public readonly array $nodes,
		public readonly array $edges,
	) {

	}//end __construct()
}//end class

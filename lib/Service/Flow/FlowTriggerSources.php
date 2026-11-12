<?php

/**
 * Where a flow's trigger rows come from: its published graph, the derivation from its nodes, and the slugs they are matched on.
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
 * Where a flow's trigger rows come from: its published graph, the derivation from its nodes, and the slugs they are matched on.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-trigger-matching-must-not-scale-with-the-number-of-flows
 */
final class FlowTriggerSources {
	/**
	 * Bundle the services.
	 *
	 * @param FlowTriggerDerivation      $derivation  Nodes to triggers.
	 * @param FlowTriggerSlugs           $slugs       Normalises a derived triple to the slugs matching runs on.
	 * @param FlowPublishedGraph|null    $published   Resolves the published graph.
	 */
	public function __construct(
		public readonly FlowTriggerDerivation $derivation,
		public readonly FlowTriggerSlugs $slugs,
		public readonly ?FlowPublishedGraph $published=null,
	) {
	}//end __construct()
}//end class

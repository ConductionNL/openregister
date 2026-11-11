<?php

/**
 * OpenRegister flow node run domain
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

use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraphInterface;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;

/**
 * The domain services of the flow node run API.
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */
final class FlowNodeRunGraph {
	/**
	 * Bundle the services.
	 *
	 * @param FlowServiceInterface        $flows           Org-scoped flow lookup — the same non-oracle
	 * @param FlowPublishedGraphInterface $publishedGraphs Resolves the flow's PUBLISHED
	 * @param FlowLocatorInterface        $subjects        Resolves the live flow document (for
	 * @param FlowNodeRegistryInterface   $nodes           Resolves a
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
	 */
	public function __construct(
		public readonly FlowServiceInterface $flows,
		public readonly FlowPublishedGraphInterface $publishedGraphs,
		public readonly FlowLocatorInterface $subjects,
		public readonly FlowNodeRegistryInterface $nodes,
	) {
	}//end __construct()
}//end class

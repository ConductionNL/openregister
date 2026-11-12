<?php

/**
 * OpenRegister flow node types
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

use OCA\OpenRegister\Service\Flow\FlowNodePreflightInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;

/**
 * The flow-step types the flow API knows: the registry of step types and the
 * preflight that resolves which of them a document uses.
 *
 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
 */
final class FlowNodeTypes {
	/**
	 * Bundle the step-type services.
	 *
	 * @param FlowNodeRegistryInterface  $nodes     The registered flow-step types.
	 * @param FlowNodePreflightInterface $preflight Resolves a document's step types.
	 *
	 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
	 */
	public function __construct(
		public readonly FlowNodeRegistryInterface $nodes,
		public readonly FlowNodePreflightInterface $preflight,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister flow definitions
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

use OCA\OpenRegister\Service\Flow\FlowAccessInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;

/**
 * The domain services of the flow API.
 *
 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
 */
final class FlowDefinitions {
	/**
	 * Bundle the services.
	 *
	 * @param FlowServiceInterface                 $flows              Reads, writes and runs flow definitions.
	 * @param FlowAccessInterface         $access             Who may do what with a flow.
	 * @param FlowVersionServiceInterface $flowVersionService Reads, publishes, drafts and deprecates versions.
	 * @param FlowNodeRightsGuardInterface $nodeRights        Which steps need a right, and whether the caller holds it.
	 *
	 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
	 */
	public function __construct(
		public readonly FlowServiceInterface $flows,
		public readonly FlowAccessInterface $access,
		public readonly FlowVersionServiceInterface $flowVersionService,
		public readonly FlowNodeRightsGuardInterface $nodeRights,
	) {
	}//end __construct()
}//end class

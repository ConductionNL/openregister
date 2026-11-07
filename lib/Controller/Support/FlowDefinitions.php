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

use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowService;
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
	 * @param FlowService                 $flows              Reads, writes and runs flow definitions.
	 * @param FlowAccess                  $access             Who may do what with a flow.
	 * @param FlowVersionServiceInterface $flowVersionService Reads, publishes, drafts and deprecates versions.
	 *
	 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
	 */
	public function __construct(
		public readonly FlowService $flows,
		public readonly FlowAccess $access,
		public readonly FlowVersionServiceInterface $flowVersionService,
	) {
	}//end __construct()
}//end class

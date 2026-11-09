<?php

/**
 * OpenRegister flow catalogue
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

use OCA\OpenRegister\Db\FlowStateMapperInterface;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchangeInterface;
use OCA\OpenRegister\Service\Flow\EventCatalogServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowNodePreflightInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;

/**
 * Services of the flow API.
 *
 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
 */
final class FlowCatalogue {
	/**
	 * Bundle the services.
	 *
	 * @param FlowStateMapperInterface            $flowState    Reads state a flow keeps between runs.
	 * @param EventCatalogServiceInterface        $eventCatalog The flow trigger catalog.
	 * @param FlowNodeRegistryInterface           $nodes        The registered flow-step types.
	 * @param FlowNodePreflightInterface $preflight    Resolves a document's step types.
	 * @param FlowBpmnInterchangeInterface $bpmn       BPMN 2.0 export and import.
	 *
	 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
	 */
	public function __construct(
		public readonly FlowStateMapperInterface $flowState,
		public readonly EventCatalogServiceInterface $eventCatalog,
		public readonly FlowNodeRegistryInterface $nodes,
		public readonly FlowNodePreflightInterface $preflight,
		public readonly FlowBpmnInterchangeInterface $bpmn,
	) {
	}//end __construct()
}//end class

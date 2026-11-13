<?php

/**
 * The steps that find, create-or-link and read objects, and read their audit trail.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\FlowNodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\FlowNodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\AuditTrailNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\LinkObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNodeInterface;

/**
 * The steps that find, create-or-link and read objects, and read their audit trail.
 *
 * Listed in registration order.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final readonly class ObjectLookupFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param LinkObjectNodeInterface $linkObject The built-in "Create or link an object" node.
	 * @param ObjectReadNodeInterface $objectRead The built-in "Read objects" node.
	 * @param AuditTrailNodeInterface $auditTrail The built-in "Read audit trail" node.
	 */
	public function __construct(
		private LinkObjectNodeInterface $linkObject,
		private ObjectReadNodeInterface $objectRead,
		private AuditTrailNodeInterface $auditTrail,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->linkObject,
			$this->objectRead,
			$this->auditTrail,
		];

	}//end nodes()
}//end class

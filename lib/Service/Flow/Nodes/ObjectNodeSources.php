<?php

/**
 * The object store and the register and schema mappers an object node resolves its target with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * Object operations plus the mappers that resolve a step's register and schema.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final class ObjectNodeSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOperationsInterface $objects   Object operations, RBAC-enforcing.
	 * @param RegisterMapperInterface   $registers Resolves the configured register.
	 * @param SchemaMapperInterface     $schemas   Resolves the configured schema.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly RegisterMapperInterface $registers,
		public readonly SchemaMapperInterface $schemas,
	) {
	}//end __construct()
}//end class

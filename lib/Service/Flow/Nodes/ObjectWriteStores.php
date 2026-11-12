<?php

/**
 * The register stores the object-write step writes to and resolves its target against.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
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

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The register stores the object-write step writes to and resolves its target against.
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */
final class ObjectWriteStores {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objects   Saves the objects.
	 * @param RegisterMapperInterface   $registers Resolves the target register.
	 * @param SchemaMapperInterface     $schemas   Resolves the target schema.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly RegisterMapperInterface $registers,
		public readonly SchemaMapperInterface $schemas,
	) {
	}//end __construct()
}//end class

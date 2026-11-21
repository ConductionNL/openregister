<?php

/**
 * The flow tables FlowLocator reads.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowTriggerMapperInterface;
use OCA\OpenRegister\Db\FlowVersionMapperInterface;

/**
 * The flow definition, trigger index and version mappers, bundled for FlowLocator.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
final class FlowLocatorStores {

	/**
	 * Bundle the mappers.
	 *
	 * @param FlowMapperInterface        $mapper        Reads flow definitions.
	 * @param FlowTriggerMapperInterface $triggerMapper The node-derived trigger index.
	 * @param FlowVersionMapperInterface $versions      Answers whether a fallback-matched flow has a published version.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	public function __construct(
		public readonly FlowMapperInterface $mapper,
		public readonly FlowTriggerMapperInterface $triggerMapper,
		public readonly FlowVersionMapperInterface $versions,
	) {
	}//end __construct()
}//end class

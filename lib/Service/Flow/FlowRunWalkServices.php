<?php

/**
 * What a flow run is walked with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\IAppConfig;

/**
 * What a run is walked with: the engine, the step types, the instance-wide runtime
 * ceiling and the audit of each recorded hop.
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */
final readonly class FlowRunWalkServices {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowEngine                $engine    Walks the graph.
	 * @param FlowNodeRegistryInterface $registry  Resolves step types.
	 * @param IAppConfig|null           $appConfig Reads the instance-wide runtime ceiling; null applies the compiled-in default.
	 * @param FlowHopAudit|null         $hopAudit  Audits each hop recorded by the step history.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function __construct(
		public FlowEngine $engine,
		public FlowNodeRegistryInterface $registry,
		public ?IAppConfig $appConfig = null,
		public ?FlowHopAudit $hopAudit = null,
	) {
	}//end __construct()
}//end class

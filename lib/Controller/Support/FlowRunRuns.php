<?php

/**
 * OpenRegister flow run runs
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

use OCA\OpenRegister\Db\AuditFlowAttribution;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;

/**
 * Services of the flow run API.
 *
 * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
 */
final class FlowRunRuns {
	/**
	 * Bundle the services.
	 *
	 * @param FlowRunMapperInterface         $mapper      Reads runs.
	 * @param FlowRunServiceInterface        $runner      Retries, requeues and runs.
	 * @param FlowLocatorInterface           $resolvers   Resolves a flow id to its document.
	 * @param ?AuditFlowAttribution $auditTrails Reads the attribution stamped on
	 *
	 * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
	 */
	public function __construct(
		public readonly FlowRunMapperInterface $mapper,
		public readonly FlowRunServiceInterface $runner,
		public readonly FlowLocatorInterface $resolvers,
		public readonly ?AuditFlowAttribution $auditTrails = null,
	) {
	}//end __construct()
}//end class

<?php

/**
 * Finding a flow and running it: the flow locator, the run service and the log.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use Psr\Log\LoggerInterface;

/**
 * Finding a flow and running it: the flow locator, the run service and the log.
 *
 * Consumed by the trigger service, the run advancer and the sub-flow step,
 * which each resolve a flow (and its subject) and hand it to the run service.
 *
 * @spec openspec/specs/flow-runs/spec.md
 */
final class FlowExecution {
	/**
	 * Bundle the services.
	 *
	 * @param FlowLocator             $resolvers Resolves a flow, the flows wired to an event, and a subject.
	 * @param FlowRunServiceInterface $runner    Queues and executes runs.
	 * @param LoggerInterface         $logger    Records failures.
	 */
	public function __construct(
		public readonly FlowLocator $resolvers,
		public readonly FlowRunServiceInterface $runner,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class

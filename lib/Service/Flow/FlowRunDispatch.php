<?php

/**
 * OpenRegister Flow Run Dispatch
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

/**
 * How a flow is run: the queue and executor, the inline advancer and the run authorization.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */
final readonly class FlowRunDispatch {

	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowRunServiceInterface $runner Queues and executes runs.
	 * @param FlowRunAdvancer $advancer Advances a run inline for a synchronous caller.
	 * @param FlowRunAuthorization|null $runAuthorization Judges whether the caller may act on a run; absent, the guarded actions refuse, which scopes.
	 */
	public function __construct(
		public FlowRunServiceInterface $runner,
		public FlowRunAdvancer $advancer,
		public ?FlowRunAuthorization $runAuthorization = null,
	) {
	}//end __construct()
}//end class

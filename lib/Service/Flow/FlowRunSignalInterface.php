<?php

/**
 * The guarded signal seam: answer a suspended run as a named actor.
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
 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Exception\FlowSignalRefused;

/**
 * Delivers a signal to a suspended run, on behalf of a named actor, guarded.
 *
 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */
interface FlowRunSignalInterface {

	/**
	 * Answer a suspended run by uuid, guarded.
	 *
	 * @param string    $runUuid The run to answer.
	 * @param RunSignal $signal  What the signaller wants the run to know, who is answering (null or '' means
	 *                           anonymous) and the node the answer addresses, when known.
	 *
	 * @return FlowRun The parked run, due for the worker's next pass.
	 *
	 * @throws FlowSignalRefused With reason RUN_NOT_FOUND, NOT_ASSIGNEE or NOT_SUSPENDED.
	 */
	public function answerByUuid(string $runUuid, RunSignal $signal): FlowRun;

	/**
	 * Answer an already-resolved run, as a named actor, guarded.
	 *
	 * @param FlowRun     $run      The run to answer.
	 * @param array       $payload  What the signaller wants the run to know.
	 * @param string|null $actorUid Who is answering; null or '' means anonymous.
	 * @param string|null $nodeId   The node the answer addresses, when known.
	 *
	 * @return FlowRun The parked run, due for the worker's next pass.
	 *
	 * @throws FlowSignalRefused With reason NOT_ASSIGNEE or NOT_SUSPENDED.
	 */
	public function signalRunAs(FlowRun $run, array $payload, ?string $actorUid, ?string $nodeId = null): FlowRun;
}//end interface

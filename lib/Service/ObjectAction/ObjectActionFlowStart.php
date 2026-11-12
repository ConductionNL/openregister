<?php

/**
 * A flow run an action started: the run, its flow and whether it was advanced now.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\FlowRun;

/**
 * The started run of a flow or steps body.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
 */
final class ObjectActionFlowStart {

	/**
	 * Constructor.
	 *
	 * @param FlowRun $run      The run.
	 * @param string  $flowUuid The flow it runs.
	 * @param bool    $sync     Whether it was advanced now.
	 */
	public function __construct(
		public readonly FlowRun $run,
		public readonly string $flowUuid,
		public readonly bool $sync,
	) {
	}//end __construct()

	/**
	 * Whether the run has not finished: queued for later, or suspended while advanced.
	 *
	 * @return bool True when the run is pending.
	 */
	public function isPending(): bool {
		return $this->sync === false || (string)$this->run->getStatus() === FlowRun::STATUS_SUSPENDED;
	}//end isPending()

	/**
	 * Whether the run completed.
	 *
	 * @return bool True when its status is completed.
	 */
	public function isCompleted(): bool {
		return (string)$this->run->getStatus() === FlowRun::STATUS_COMPLETED;
	}//end isCompleted()

	/**
	 * The status a pending run answers: suspended when it was advanced, queued otherwise.
	 *
	 * @return string The status.
	 */
	public function pendingStatus(): string {
		if ($this->sync === true) {
			return FlowRun::STATUS_SUSPENDED;
		}

		return FlowRun::STATUS_QUEUED;
	}//end pendingStatus()

	/**
	 * The audit outcome of this run.
	 *
	 * @param string                                        $outcome The outcome.
	 * @param array<int, array{step: string, kind: string}> $changes What the run changed.
	 *
	 * @return ObjectActionRunOutcome The outcome, under this run's uuid.
	 */
	public function outcome(string $outcome, array $changes): ObjectActionRunOutcome {
		return new ObjectActionRunOutcome(run: (string)$this->run->getUuid(), changes: $changes, outcome: $outcome);
	}//end outcome()

	/**
	 * The 202 body of a pending run.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 *
	 * @return array<string, mixed> The body.
	 */
	public function pendingBody(ObjectActionDefinition $definition): array {
		return [
			'run' => (string)$this->run->getUuid(),
			'status' => $this->pendingStatus(),
			'action' => $definition->key,
			'kind' => $definition->kind,
			'links' => $this->links(),
		];
	}//end pendingBody()

	/**
	 * The 422 body of a run that ended without completing, naming its error.
	 *
	 * @param string $key The action key.
	 *
	 * @return array<string, mixed> The body.
	 */
	public function endedBody(string $key): array {
		$status = (string)$this->run->getStatus();
		$error  = (string)$this->run->getError();
		if ($error === '') {
			$error = sprintf('The run ended %s.', $status);
		}

		return ['error' => $error, 'run' => (string)$this->run->getUuid(), 'outcome' => $status, 'action' => $key, 'links' => $this->links()];
	}//end endedBody()

	/**
	 * The 200 body of a completed run.
	 *
	 * @param ObjectActionDefinition $definition The action.
	 * @param mixed                  $next       The `next` hint the flow earned.
	 *
	 * @return array<string, mixed> The body.
	 */
	public function completedBody(ObjectActionDefinition $definition, mixed $next): array {
		return [
			'run' => (string)$this->run->getUuid(),
			'outcome' => (string)$this->run->getStatus(),
			'action' => $definition->key,
			'kind' => $definition->kind,
			'next' => $next,
			'links' => $this->links(),
		];
	}//end completedBody()

	/**
	 * The links of the run.
	 *
	 * @return array{run: string} The links.
	 */
	public function links(): array {
		return ['run' => '/api/flow-runs/' . (string)$this->run->getUuid()];
	}//end links()
}//end class

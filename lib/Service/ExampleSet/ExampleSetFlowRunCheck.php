<?php

/**
 * OpenRegister Example Set Flow Run Check
 *
 * Tells why one declared run of a set's flow cannot be written: a field a set
 * may not write, a status that is neither finished nor waiting on a user-task
 * step, no object it ran on, or a date that does not parse.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use Throwable;

/**
 * Checks one declared run.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
 */
class ExampleSetFlowRunCheck {
	/**
	 * Why one declared run cannot be written, or ''.
	 *
	 * @param array<string, mixed> $run   The declared run.
	 * @param mixed                $nodes The flow's declared steps.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-flow-may-carry-finished-runs
	 */
	public function refusal(array $run, mixed $nodes): string {
		$refusal = $this->kindRefusal(run: $run, nodes: $nodes);
		if ($refusal === '') {
			$refusal = $this->subjectRefusal(run: $run);
		}

		if ($refusal === '') {
			$refusal = $this->dateRefusal(run: $run);
		}

		return $refusal;
	}//end refusal()

	/**
	 * Why a run is not a kind of run a set may write, or '': its fields first, then its status.
	 *
	 * @param array<string, mixed> $run   The declared run.
	 * @param mixed                $nodes The flow's declared steps.
	 *
	 * @return string
	 */
	private function kindRefusal(array $run, mixed $nodes): string {
		$refusal = $this->fieldRefusal(run: $run);
		if ($refusal === '') {
			$refusal = $this->statusRefusal(run: $run, nodes: $nodes);
		}

		return $refusal;
	}//end kindRefusal()

	/**
	 * Why a run sets a field a set may not write, or ''.
	 *
	 * @param array<string, mixed> $run The declared run.
	 *
	 * @return string
	 */
	private function fieldRefusal(array $run): string {
		$unknown = array_diff(array_keys($run), ExampleSetFlowRuns::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a run field a set may write';
		}

		return '';
	}//end fieldRefusal()

	/**
	 * Why a run's status cannot be written, or '': a run is finished, or waits on a step as waitRefusal() asks.
	 *
	 * @param array<string, mixed> $run   The declared run.
	 * @param mixed                $nodes The flow's declared steps.
	 *
	 * @return string
	 */
	private function statusRefusal(array $run, mixed $nodes): string {
		$status = ($run['status'] ?? null);
		if ($status === FlowRun::STATUS_SUSPENDED) {
			return $this->waitRefusal(run: $run, nodes: $nodes);
		}

		if (in_array($status, FlowRun::TERMINAL, true) === false) {
			return 'a run is finished (status is one of '.implode(', ', FlowRun::TERMINAL).') or waits on a step (suspended)';
		}

		return '';
	}//end statusRefusal()

	/**
	 * Why a run names no object it ran on, or ''.
	 *
	 * @param array<string, mixed> $run The declared run.
	 *
	 * @return string
	 */
	private function subjectRefusal(array $run): string {
		if (is_string($run['subject'] ?? null) === false || trim($run['subject']) === '') {
			return 'a run needs the object it ran on (subject)';
		}

		return '';
	}//end subjectRefusal()

	/**
	 * Why a run's created or updated is not a date, or ''.
	 *
	 * @param array<string, mixed> $run The declared run.
	 *
	 * @return string
	 */
	private function dateRefusal(array $run): string {
		foreach (['created', 'updated'] as $field) {
			try {
				new DateTime((string) ($run[$field] ?? 'now'));
			} catch (Throwable) {
				return 'run '.$field.' "'.(string) $run[$field].'" is not a date';
			}
		}

		return '';
	}//end dateRefusal()

	/**
	 * Why a waiting run cannot be written, or '': it needs a key and a user-task step of its flow.
	 *
	 * @param array<string, mixed> $run   The declared run.
	 * @param mixed                $nodes The flow's declared steps.
	 *
	 * @return string
	 */
	private function waitRefusal(array $run, mixed $nodes): string {
		if (is_string($run['key'] ?? null) === false || trim($run['key']) === '') {
			return 'a waiting run needs a key, so a task can name it';
		}

		$node = (string) ($run['node'] ?? '');
		if ($this->isUserTaskStep(nodes: $nodes, node: $node) === true) {
			return '';
		}

		return 'waiting run "'.$run['key'].'" waits on an '.ExampleSetFlowRuns::STEP_TYPE.' step of its flow (node), not "'.$node.'"';
	}//end waitRefusal()

	/**
	 * Whether a flow declares a step with this id of the user-task type.
	 *
	 * @param mixed  $nodes The flow's declared steps.
	 * @param string $node  The step id.
	 *
	 * @return bool
	 */
	private function isUserTaskStep(mixed $nodes, string $node): bool {
		foreach ((array) $nodes as $step) {
			$step = (array) $step;
			if (($step['id'] ?? null) === $node && ($step['type'] ?? null) === ExampleSetFlowRuns::STEP_TYPE) {
				return true;
			}
		}

		return false;
	}//end isUserTaskStep()
}//end class

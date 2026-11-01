<?php

/**
 * One hop of a flow walk: the transition, its step and the items it reads,
 * and the log entries that record how the hop ended.
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
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * A hop of a walk, from the moment its transition is chosen until it ends.
 */
class FlowWalkHop {

	/**
	 * When the hop started, as `microtime(true)` returns it.
	 *
	 * @var float
	 */
	public readonly float $startedAt;

	/**
	 * Constructor. The start moment is taken when the hop is made.
	 *
	 * @param FlowWalk          $walk       The walk the hop belongs to.
	 * @param object            $transition The transition being fired.
	 * @param array             $step       The step configuration for the transition.
	 * @param array<int, mixed> $itemsIn    The items on the transition's input places.
	 */
	public function __construct(
		public readonly FlowWalk $walk,
		public readonly object $transition,
		public readonly array $step,
		public readonly array $itemsIn,
	) {
		$this->startedAt = microtime(true);
	}//end __construct()

	/**
	 * The transition name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function name(): string {
		return (string)$this->transition->getName();
	}//end name()

	/**
	 * The step's catalogue id, carried onto every log entry so the run history
	 * can be queried BY NODE TYPE ("which node type fails") rather than only by run.
	 *
	 * @return string The step type, or an empty string.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function stepType(): string {
		return (string)($this->step['type'] ?? '');
	}//end stepType()

	/**
	 * The step's `onError` policy; `stop` when none is set.
	 *
	 * @return string The policy.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function errorPolicy(): string {
		return (string)($this->step['onError'] ?? FlowEngine::ON_ERROR_STOP);
	}//end errorPolicy()

	/**
	 * The run status a failed step ends the run with, when its policy ends it.
	 *
	 * `dead_letter` for that policy; `failed` for `stop`, the default, which
	 * also catches an unknown policy so a typo fails safe.
	 *
	 * 🔴 THE POLICY SAYS WHETHER TO END THE RUN; IT DOES NOT SAY THE RUN ENDED
	 * WELL. `onError: stop` used to return `stopped` with no `error`, which is
	 * byte-for-byte what a deliberate Stop node returns — so a run killed by a
	 * broken step and a run an author ended on purpose were the same row, and
	 * `SELECT ... WHERE status = 'stopped'` could not tell a healthy guard
	 * branch from a wreck. So a step failure ends the run as `failed`, with the
	 * step's message on the `error` column, in BOTH walks.
	 *
	 * @return string The terminal status.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function terminalStatus(): string {
		if ($this->errorPolicy() === FlowEngine::ON_ERROR_DEAD_LETTER) {
			return FlowEngine::STATUS_DEAD_LETTER;
		}

		return FlowEngine::STATUS_FAILED;
	}//end terminalStatus()

	/**
	 * The log entry of a pinned step: its stored output used verbatim, not executed.
	 *
	 * @return array<string, mixed> The log entry; the walk's items are the pinned ones.
	 *
	 * @spec openspec/changes/or-flow-pins/specs/flow-pins/spec.md
	 */
	public function pinnedEntry(): array {
		return [
			'transition' => $this->name(),
			'startedAt' => $this->moment(),
			'type' => $this->stepType(),
			'status' => 'pinned',
			'itemsIn' => count($this->itemsIn),
			'itemsOut' => count($this->walk->items),
			'durationMs' => 0,
		];
	}//end pinnedEntry()

	/**
	 * The log entry of a step that completed.
	 *
	 * Records what the step RECEIVED and RETURNED, not just how many: the
	 * question asked of a run that completed with the wrong answer is "what
	 * did it get, and what did it do with it", and no count can answer that.
	 *
	 * @return array<string, mixed> The log entry; the walk's items are the step's output.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function completedEntry(): array {
		$items = $this->walk->items;
		$entry = [
			'transition' => $this->name(),
			'startedAt' => $this->moment(),
			'type' => $this->stepType(),
			'status' => 'completed',
			'itemsIn' => count($this->itemsIn),
			'itemsOut' => count($items),
			'input' => self::sampleItems(items: $this->itemsIn),
			'output' => self::sampleItems(items: $items),
			'durationMs' => $this->durationMs(),
		];

		return $this->withStepReport(entry: $entry);
	}//end completedEntry()

	/**
	 * The log entry of a hop a Stop step or an oversight check stopped.
	 *
	 * @param FlowStop $stop The stop. Its check id is null for an author's Stop
	 *                       step and set when an oversight gate raised it.
	 *
	 * @return array<string, mixed> The log entry.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function stoppedEntry(FlowStop $stop): array {
		return [
			'transition' => $this->name(),
			'startedAt' => $this->moment(),
			'type' => $this->stepType(),
			'status' => 'stopped',
			'reason' => $stop->getMessage(),
			'checkId' => $stop->checkId(),
			'durationMs' => $this->durationMs(),
		];
	}//end stoppedEntry()

	/**
	 * The log entry of a hop whose step asked to wait.
	 *
	 * @param FlowSuspension $suspension The suspension.
	 *
	 * @return array<string, mixed> The log entry.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function suspendedEntry(FlowSuspension $suspension): array {
		return [
			'transition' => $this->name(),
			'startedAt' => $this->moment(),
			'status' => 'suspended',
			'reason' => $suspension->getMessage(),
		];
	}//end suspendedEntry()

	/**
	 * The log entry of a step that failed.
	 *
	 * A failing step's report is worth MORE than a completing one's: a send
	 * node that delivered to two recipients and failed on the third records
	 * exactly which sends went out before the throw.
	 *
	 * @param Throwable $error The failure.
	 *
	 * @return array<string, mixed> The log entry, holding the message as `error`.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function failedEntry(Throwable $error): array {
		$entry = [
			'transition' => $this->name(),
			'startedAt' => $this->moment(),
			'type' => $this->stepType(),
			'status' => 'failed',
			'error' => $error->getMessage(),
			'durationMs' => $this->durationMs(),
		];

		return $this->withStepReport(entry: $entry);
	}//end failedEntry()

	/**
	 * Add the hop's step report to a log entry, when the step wrote one.
	 *
	 * The report handle travels in the context and is TAKEN (not read), which
	 * is what scopes a report to one hop: a node's detail can never bleed onto a
	 * later step's log entry.
	 *
	 * @param array<string, mixed> $entry The log entry.
	 *
	 * @return array<string, mixed> The entry, with `report` when there is one.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	private function withStepReport(array $entry): array {
		$report = ($this->walk->context[FlowStepReport::CONTEXT_KEY] ?? null);
		if (($report instanceof FlowStepReport) === false) {
			return $entry;
		}

		$detail = $report->take();
		if ($detail !== []) {
			$entry['report'] = $detail;
		}

		return $entry;
	}//end withStepReport()

	/**
	 * Milliseconds since the hop started.
	 *
	 * @return int The duration.
	 */
	private function durationMs(): int {
		return (int)round((microtime(true) - $this->startedAt) * 1000);
	}//end durationMs()

	/**
	 * The hop's start as ISO 8601 with milliseconds, UTC, for the run log.
	 *
	 * @return string The moment, e.g. `2026-10-07T08:41:02.380+00:00`.
	 *
	 * @spec openspec/changes/flow-step-log-lines/specs/flow-step-log-lines/spec.md
	 */
	private function moment(): string {
		$moment = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->startedAt), new DateTimeZone('UTC'));
		if ($moment === false) {
			return gmdate('Y-m-d\TH:i:s+00:00', (int)$this->startedAt);
		}

		return $moment->format('Y-m-d\TH:i:s.vP');
	}//end moment()

	/**
	 * A bounded, honest sample of an item list, for the run log.
	 *
	 * BOUNDED, because a node returning ten thousand items would otherwise put
	 * them all in a log kept for months. HONEST, because the envelope always
	 * carries the true `count`, and `truncated` says whether `items` is all of
	 * them.
	 *
	 * @param array $items The item list.
	 *
	 * @return array{count: int, truncated: bool, items: array<int, mixed>} The sample.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	private static function sampleItems(array $items): array {
		$total = count($items);

		return [
			'count' => $total,
			'truncated' => ($total > FlowEngine::LOG_ITEM_SAMPLE),
			'items' => array_slice($items, 0, FlowEngine::LOG_ITEM_SAMPLE),
		];
	}//end sampleItems()
}//end class

<?php

/**
 * One firing of a FlowEngine walk, and the run-log entries it can end in.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * A transition being fired: its step, the items it reads and when it started.
 *
 * Both walks (the single in-memory stream and the per-stream walk) write the
 * same log entries; a subclass names the pass the hop belongs to.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
 */
abstract class FlowHop {

	/**
	 * When the hop started, as `microtime(true)` returns it.
	 *
	 * @var float
	 */
	public readonly float $startedAt;

	/**
	 * The transition being fired.
	 *
	 * @var object
	 */
	public readonly object $transition;

	/**
	 * The step configuration for the transition.
	 *
	 * @var array
	 */
	public readonly array $step;

	/**
	 * The items on the transition's input places.
	 *
	 * @var array<int, mixed>
	 */
	public readonly array $itemsIn;

	/**
	 * Constructor. The start moment is taken when the hop is made.
	 *
	 * @param FlowWalkPass|FlowStreamPass $owner The pass the hop belongs to.
	 * @param FlowHopPlan                 $plan  The transition, its step and its items.
	 */
	public function __construct(
		private readonly FlowWalkPass|FlowStreamPass $owner,
		FlowHopPlan $plan,
	) {
		$this->transition = $plan->transition;
		$this->step = $plan->step;
		$this->itemsIn = $plan->itemsIn;
		$this->startedAt = microtime(true);
	}//end __construct()

	/**
	 * The transition's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function name(): string {
		return (string)$this->transition->getName();
	}//end name()

	/**
	 * The step's catalogue type, carried onto every log entry so the run
	 * history can be queried by node type.
	 *
	 * @return string The type, or '' when the step has none.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function stepType(): string {
		return (string)($this->step['type'] ?? '');
	}//end stepType()

	/**
	 * The log entry of a step whose pinned output was used instead of running it.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function pinnedEntry(): array {
		return $this->head(status: 'pinned') + [
			'itemsIn' => count($this->itemsIn),
			'itemsOut' => count($this->owner->items),
			'durationMs' => 0,
		];
	}//end pinnedEntry()

	/**
	 * The log entry of a step that completed: what it received and returned,
	 * and its own report.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function completedEntry(): array {
		$items = $this->owner->items;
		$entry = $this->head(status: 'completed') + [
			'itemsIn' => count($this->itemsIn),
			'itemsOut' => count($items),
			'input' => $this->sample(items: $this->itemsIn),
			'output' => $this->sample(items: $items),
			'durationMs' => $this->elapsedMs(),
		];

		return $this->withReport(entry: $entry);
	}//end completedEntry()

	/**
	 * The log entry of a step that failed, with its own report: a step that
	 * failed half-way records what it did before the throw.
	 *
	 * @param Throwable $error The failure.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function failedEntry(Throwable $error): array {
		$entry = $this->head(status: 'failed') + [
			'error' => $error->getMessage(),
			'durationMs' => $this->elapsedMs(),
		];

		return $this->withReport(entry: $entry);
	}//end failedEntry()

	/**
	 * The log entry of a hop a Stop step or an oversight check stopped. The
	 * check id is null for an author's Stop step.
	 *
	 * @param FlowStop $stop The stop.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function stoppedEntry(FlowStop $stop): array {
		return $this->head(status: 'stopped') + [
			'reason' => $stop->getMessage(),
			'checkId' => $stop->checkId(),
			'durationMs' => $this->elapsedMs(),
		];
	}//end stoppedEntry()

	/**
	 * The log entry of a hop whose step asked to wait.
	 *
	 * @param FlowSuspension $suspension The suspension.
	 *
	 * @return array<string, mixed> The entry.
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
	 * The keys every entry but a suspension's starts with.
	 *
	 * @param string $status The entry's status.
	 *
	 * @return array<string, string> transition, startedAt, type and status.
	 */
	private function head(string $status): array {
		return [
			'transition' => $this->name(),
			'startedAt' => $this->moment(),
			'type' => $this->stepType(),
			'status' => $status,
		];
	}//end head()

	/**
	 * Milliseconds since the hop started.
	 *
	 * @return integer The duration.
	 */
	private function elapsedMs(): int {
		return (int)round((microtime(true) - $this->startedAt) * 1000);
	}//end elapsedMs()

	/**
	 * The hop's start as ISO 8601 with milliseconds, UTC, e.g.
	 * `2026-10-07T08:41:02.380+00:00`.
	 *
	 * @return string The moment.
	 */
	private function moment(): string {
		$moment = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->startedAt), new DateTimeZone('UTC'));
		if ($moment === false) {
			return gmdate('Y-m-d\TH:i:s+00:00', (int)$this->startedAt);
		}

		return $moment->format('Y-m-d\TH:i:s.vP');
	}//end moment()

	/**
	 * A bounded sample of an item list. It always carries the true `count`,
	 * and `truncated` says whether `items` is all of them.
	 *
	 * @param array $items The item list.
	 *
	 * @return array{count: int, truncated: bool, items: array<int, mixed>} The sample.
	 */
	private function sample(array $items): array {
		$total = count($items);

		return [
			'count' => $total,
			'truncated' => ($total > FlowEngine::LOG_ITEM_SAMPLE),
			'items' => array_slice($items, 0, FlowEngine::LOG_ITEM_SAMPLE),
		];
	}//end sample()

	/**
	 * Add the step's report to a log entry, when it wrote one. The report is
	 * taken, not read, so it never lands on a later step's entry.
	 *
	 * @param array<string, mixed> $entry The log entry.
	 *
	 * @return array<string, mixed> The entry, with `report` when there is one.
	 */
	private function withReport(array $entry): array {
		$report = ($this->owner->context[FlowStepReport::CONTEXT_KEY] ?? null);
		if (($report instanceof FlowStepReport) === false) {
			return $entry;
		}

		$detail = $report->take();
		if ($detail !== []) {
			$entry['report'] = $detail;
		}

		return $entry;
	}//end withReport()
}//end class

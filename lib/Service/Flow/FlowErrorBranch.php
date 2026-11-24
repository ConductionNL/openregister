<?php

/**
 * The items a branching step's failure sends on: failed ones tagged for the `error` output, succeeded ones on.
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
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTimeImmutable;
use Throwable;

/**
 * Builds the output of a failed step under `onError: "branch"` (design D-2).
 *
 * Every failed item carries `json.error = { message, step, attempt, at }` and
 * the output tag of the `error` exit, which the router resolves to the error
 * edge's place. A node that failed as a whole fails for all its input items;
 * one that isolates item failures ({@see FlowItemsFailed}) fails only for
 * the items it names, and its succeeded items keep going untagged.
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */
final class FlowErrorBranch {
	/**
	 * Constructor.
	 *
	 * @param string            $step    The failed step's id.
	 * @param int               $attempt The attempt that failed last.
	 * @param DateTimeImmutable $at      When it failed.
	 */
	public function __construct(
		private readonly string $step,
		private readonly int $attempt,
		private readonly DateTimeImmutable $at,
	) {
	}//end __construct()

	/**
	 * The items the step hands on after the failure.
	 *
	 * @param list<mixed> $itemsIn The step's input items.
	 * @param Throwable   $error   The failure.
	 *
	 * @return array{items: list<mixed>, failed: int} The items, and how many of them took the error output.
	 *
	 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
	 */
	public function itemsAfter(array $itemsIn, Throwable $error): array {
		if ($error instanceof FlowItemsFailed) {
			$failed = array_map(fn (array $pair): array => $this->failedItem(item: $pair['item'], error: $pair['error']), $error->failed);

			return ['items' => array_merge(array_values($error->succeeded), $failed), 'failed' => count($failed)];
		}

		if ($itemsIn === []) {
			$itemsIn = [[FlowItems::JSON => []]];
		}

		$failed = array_map(fn (mixed $item): array => $this->failedItem(item: $item, error: $error), array_values($itemsIn));

		return ['items' => $failed, 'failed' => count($failed)];
	}//end itemsAfter()

	/**
	 * One input item, tagged for the error output and carrying the error.
	 *
	 * @param mixed     $item  The input item.
	 * @param Throwable $error Its failure.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function failedItem(mixed $item, Throwable $error): array {
		$member = [FlowItems::JSON => ['value' => $item]];
		if (is_array($item) === true && array_key_exists(FlowItems::JSON, $item) === true) {
			$member = $item;
		} else if (is_array($item) === true) {
			$member = [FlowItems::JSON => $item];
		}

		$json = $member[FlowItems::JSON];
		if (is_array($json) === false) {
			$json = ['value' => $json];
		}

		$json['error'] = [
			'message' => $error->getMessage(),
			'step'    => $this->step,
			'attempt' => $this->attempt,
			'at'      => $this->at->format(DATE_ATOM),
		];
		$member[FlowItems::JSON]   = $json;
		$member[FlowItems::OUTPUT] = FlowErrorPolicy::ERROR_EXIT;

		return $member;
	}//end failedItem()
}//end class

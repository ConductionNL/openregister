<?php

/**
 * One pass of FlowEngine's single-stream walk: what the pass walks over, and
 * the log, items and per-place items it builds up as it goes.
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

use Symfony\Component\Workflow\Workflow;

/**
 * The per-pass state of FlowEngine's single in-memory stream walk.
 *
 * The flow, workflow, subject and dispatcher are fixed for the pass; the
 * context is set once before the walk. The log, the items in hand, the items
 * per place and the firing count change with every firing.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
 */
final class FlowWalkPass {

	/**
	 * The run log so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $log = [];

	/**
	 * The transitions fired so far, checked against the engine's ceiling.
	 *
	 * @var integer
	 */
	public int $fired = 0;

	/**
	 * Run-level metadata handed to every step.
	 *
	 * @var array<string, mixed>
	 */
	public array $context = [];

	/**
	 * The items in hand, returned with the result.
	 *
	 * @var array<int, mixed>
	 */
	public array $items = [];

	/**
	 * Items per place.
	 *
	 * @var array<string, array>
	 */
	public array $placeItems = [];

	/**
	 * Constructor.
	 *
	 * @param array              $flow       The flow document.
	 * @param Workflow           $workflow   The workflow built from it.
	 * @param object             $subject    The marking holder.
	 * @param FlowStepDispatcher $dispatcher Performs each step's side effect.
	 */
	public function __construct(
		public readonly array $flow,
		public readonly Workflow $workflow,
		public readonly object $subject,
		public readonly FlowStepDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * The run result envelope of the pass: status, log, context and items.
	 *
	 * @param string $status The run status.
	 *
	 * @return array{status: string, log: array, context: array, items: array} The envelope.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function result(string $status): array {
		return ['status' => $status, 'log' => $this->log, 'context' => $this->context, 'items' => $this->items];
	}//end result()
}//end class

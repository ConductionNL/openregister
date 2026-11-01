<?php

/**
 * One pass of the per-stream walk: what the pass walks over, and the log,
 * items and per-place items it builds up as it goes.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\Workflow;

/**
 * The per-pass state of FlowEngine's stream walk.
 *
 * The constructor arguments are fixed for the pass. The log, the items in hand
 * and the items per place change with every firing.
 */
final class FlowStreamPass {

	/**
	 * The run log so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $log = [];

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
	 * @param array                 $flow       The flow document.
	 * @param Workflow              $workflow   The Petri net over the run's marking store.
	 * @param MarkingStoreInterface $store      The marking store (synced from each commit).
	 * @param object                $subject    The subject holding the marking.
	 * @param FlowStepDispatcher    $dispatcher Performs each step's side effect.
	 * @param array                 $context    Run-level metadata handed to every step.
	 * @param FlowStreamWalk        $streams    The stream collaborator.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A value object carrying the pass's collaborators
	 */
	public function __construct(
		public readonly array $flow,
		public readonly Workflow $workflow,
		public readonly MarkingStoreInterface $store,
		public readonly object $subject,
		public readonly FlowStepDispatcher $dispatcher,
		public readonly array $context,
		public readonly FlowStreamWalk $streams,
	) {
	}//end __construct()
}//end class

<?php

/**
 * The running tally and inputs of one case-plan evaluation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * One evaluation of one plan: what it handles and how far it got.
 *
 * Mutable on purpose: the pass count must be known when a pass fails, and
 * the event is offered only to the pass it arrived in.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */
final class CaseCascadeRun {

	/**
	 * The event being handled; null after the first pass.
	 *
	 * @var string|null
	 */
	public ?string $event;

	/**
	 * The event's payload; empty after the first pass.
	 *
	 * @var array<string, mixed>
	 */
	public array $payload;

	/**
	 * Passes started.
	 *
	 * @var integer
	 */
	public int $passes = 0;

	/**
	 * Transitions (and new rows) made.
	 *
	 * @var integer
	 */
	public int $transitions = 0;

	/**
	 * Constructor.
	 *
	 * @param string               $objectUuid The anchoring object.
	 * @param string|null          $event      The event being handled, or null.
	 * @param array<string, mixed> $payload    The event's payload.
	 * @param string|null          $actor      The triggering identity, or null for the system.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function __construct(
		public readonly string $objectUuid,
		?string $event,
		array $payload,
		public readonly ?string $actor,
	) {
		$this->event = $event;
		$this->payload = $payload;
	}//end __construct()

	/**
	 * Only the event being handled admits an object-event on-part, and only
	 * in the pass it arrived in.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function consumeEvent(): void {
		$this->event = null;
		$this->payload = [];
	}//end consumeEvent()
}//end class

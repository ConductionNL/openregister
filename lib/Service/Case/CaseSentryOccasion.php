<?php

/**
 * OpenRegister CaseSentryOccasion
 *
 * What a case's sentries are evaluated on: the plan, the anchoring object,
 * and the event with its payload being handled.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The plan, the object and the event one sentry evaluation reads.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
 */
final class CaseSentryOccasion {

	/**
	 * Constructor.
	 *
	 * @param CasePlanTree         $tree    The plan.
	 * @param array<string, mixed> $object  The anchoring object's data.
	 * @param string|null          $event   The event being handled, or null.
	 * @param array<string, mixed> $payload The event's payload.
	 */
	public function __construct(
		public readonly CasePlanTree $tree,
		public readonly array $object,
		public readonly ?string $event = null,
		public readonly array $payload = [],
	) {
	}//end __construct()

	/**
	 * The occasion of one cascade pass.
	 *
	 * @param CasePassState $state The pass.
	 *
	 * @return self The occasion.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public static function fromPass(CasePassState $state): self {
		return new self(tree: $state->tree, object: $state->object, event: $state->event, payload: $state->payload);
	}//end fromPass()
}//end class

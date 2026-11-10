<?php

/**
 * OpenRegister CasePlanTrigger
 *
 * What makes a case plan re-evaluate: the anchoring object, and the event with its payload when an event did.
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The object whose plan re-evaluates, and the event being handled with its payload.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */
final class CasePlanTrigger {

	/**
	 * Constructor.
	 *
	 * @param string               $objectUuid The anchoring object.
	 * @param string|null          $event      The event being handled (an object event), or null.
	 * @param array<string, mixed> $payload    The event's payload.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly ?string $event = null,
		public readonly array $payload = [],
	) {
	}//end __construct()
}//end class

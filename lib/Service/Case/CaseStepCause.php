<?php

/**
 * Why one case-plan cascade transition happens.
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
 * The audit cause, cause reference, actor and reason that travel together
 * into a {@see CaseCascadeStep}.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
 */
final readonly class CaseStepCause {

	/**
	 * Constructor.
	 *
	 * @param string      $cause    The audit cause.
	 * @param string|null $causeRef What caused it.
	 * @param string|null $actor    Who, or null for the system.
	 * @param string|null $reason   Why, in words.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function __construct(
		public string $cause,
		public ?string $causeRef,
		public ?string $actor = null,
		public ?string $reason = null,
	) {
	}//end __construct()
}//end class

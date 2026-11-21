<?php

/**
 * OpenRegister CaseTimerArmRun
 *
 * One plan-item timer being armed: the item, the timer configuration handed to the timer service, and the arming identity.
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;

/**
 * The state of arming one plan-item timer; it never leaves CasePlanItemTimers.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
final class CaseTimerArmRun {

	/**
	 * Constructor.
	 *
	 * @param CaseItem             $item   The plan item.
	 * @param array<string, mixed> $config The timer configuration (subject, purpose, legal effect, timing, labels).
	 * @param string|null          $actor  The arming identity.
	 */
	public function __construct(
		public readonly CaseItem $item,
		public readonly array $config,
		public readonly ?string $actor,
	) {
	}//end __construct()

	/**
	 * The deadline purpose of the timer, `due` or `expiry`.
	 *
	 * @return string The purpose.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
	 */
	public function purpose(): string {
		return (string)$this->config['purpose'];
	}//end purpose()
}//end class

<?php

/**
 * OpenRegister CaseItemDeadline
 *
 * One deadline of a plan item: the item, whether it is the due or the expiry deadline, and when it falls.
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

use DateTimeInterface;
use OCA\OpenRegister\Db\CaseItem;

/**
 * The item, the purpose and the moment of one plan-item deadline.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */
final class CaseItemDeadline {

	/**
	 * Constructor.
	 *
	 * @param CaseItem          $item     The plan item.
	 * @param string            $purpose  `due` or `expiry` (FlowTimer::PURPOSE_*).
	 * @param DateTimeInterface $deadline When it falls.
	 */
	public function __construct(
		public readonly CaseItem $item,
		public readonly string $purpose,
		public readonly DateTimeInterface $deadline,
	) {
	}//end __construct()
}//end class

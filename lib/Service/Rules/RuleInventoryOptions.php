<?php

/**
 * OpenRegister rule inventory options
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTime;

/**
 * How a rule inventory is read: the idle window, the moment it is measured from and the scope filter.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleInventoryOptions {
	/**
	 * Constructor.
	 *
	 * @param integer              $idleDays How long a rule may go unevaluated before it is flagged.
	 * @param DateTime|null        $now      The moment to measure the idle window from; now when null.
	 * @param array<string, mixed> $scope    Property => value: only the rules with that scope, plus those without one.
	 */
	public function __construct(
		public readonly int $idleDays = RuleInventoryServiceInterface::DEFAULT_IDLE_DAYS,
		public readonly ?DateTime $now = null,
		public readonly array $scope = [],
	) {
	}//end __construct()
}//end class

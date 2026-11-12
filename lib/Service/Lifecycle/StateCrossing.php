<?php

/**
 * OpenRegister StateCrossing
 *
 * The two lifecycle states one save crosses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The state an object leaves and the state it enters (ADR-011 Rule 2).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class StateCrossing {

	/**
	 * Build the crossing.
	 *
	 * @param string|null $from The state being left, null on a create.
	 * @param string|null $to   The state being entered.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly ?string $from,
		public readonly ?string $to,
	) {
	}//end __construct()
}//end class

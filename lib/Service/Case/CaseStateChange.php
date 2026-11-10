<?php

/**
 * OpenRegister Case State Change
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
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

namespace OCA\OpenRegister\Service\Case;

/**
 * The state an item leaves ('' on creation) and the state it enters or is asked to enter.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
final readonly class CaseStateChange {

	/**
	 * The change.
	 *
	 * @param string $from The state before ('' on creation).
	 * @param string $to   The state after, or the one requested.
	 */
	public function __construct(
		public string $from,
		public string $to,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister UnresolvedTrialObject
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * A stored object a calculation trial named and could not resolve, absent or
 * not readable by the caller alike.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class UnresolvedTrialObject {
	/**
	 * Constructor.
	 *
	 * @param string $objectId The object id the caller named.
	 * @param string $reason   Why the lookup failed.
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class

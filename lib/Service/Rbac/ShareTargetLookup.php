<?php

/**
 * How a share target is looked up: whether a group id, and a user id, exists.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use Closure;

/**
 * The two existence checks a share list is validated against.
 *
 * Without a user check no user is known, so every user share is refused:
 * the fail-closed default.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
final class ShareTargetLookup {
	/**
	 * Name the checks.
	 *
	 * @param Closure      $groupExists Answers whether a group id exists.
	 * @param Closure|null $userExists  Answers whether a user id exists.
	 */
	public function __construct(
		public readonly Closure $groupExists,
		public readonly ?Closure $userExists = null,
	) {
	}//end __construct()
}//end class

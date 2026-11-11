<?php

/**
 * ViewerReachResolverInterface: the part of ViewerReachResolver that the record templates controller uses.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The methods of ViewerReachResolver that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ViewerReachResolverInterface {

	/**
	 * The signed-in caller's uid, or an empty string when nobody is signed in.
	 *
	 * An empty string rather than null because every call site turned null
	 * into exactly that, and eight copies of the same conversion is eight
	 * places for one of them to convert it differently.
	 *
	 * @return string The uid, or ''.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function currentUid(): string;

	/**
	 * How far one caller reaches: their groups, and whether they administer.
	 *
	 * An unreadable membership is NOT an authorization. It answers no groups
	 * and no administration, so the caller sees their own views and the public
	 * ones and nothing else, which is the fail-closed direction.
	 *
	 * @param string $userId The caller.
	 *
	 * @return ViewerReach The caller's reach.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function reachOf(string $userId): ViewerReach;
}//end interface

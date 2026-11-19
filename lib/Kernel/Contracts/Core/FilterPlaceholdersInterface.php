<?php
/**
 * FilterPlaceholdersInterface: the placeholders inside a filter, resolved, as the aggregation runner asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Resolves the placeholder strings (`$now`, `$startOfMonth-1`, `$currentUser`, ...) inside a filter map.
 *
 * The aggregation runner (Core) asks before it filters; Search keeps the resolver
 * and binds it to this contract in its own wiring.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
interface FilterPlaceholdersInterface {

	/**
	 * Recursively resolve placeholders inside an array.
	 *
	 * @param array<string, mixed> $values The array whose leaf values may contain placeholders.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function resolveArray(array $values): array;
}//end interface

<?php

/**
 * RenderPath
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Where a render is on its way down the relations: how deep, and which objects it already rendered on this path.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderPath {
	/**
	 * Constructor.
	 *
	 * @param int        $depth      The depth level for nested rendering
	 * @param array|null $visitedIds Uuids already rendered on this path
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly int $depth,
		public readonly ?array $visitedIds,
	) {
	}//end __construct()
}//end class

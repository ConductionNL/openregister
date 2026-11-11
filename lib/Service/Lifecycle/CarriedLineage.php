<?php

/**
 * CarriedLineage: the part of an auto-transition pass a background job carries.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * How many automatic moves a pass made for one object, and the states it visited.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class CarriedLineage {

	/**
	 * Constructor.
	 *
	 * @param int                $moves   How many automatic moves the pass had already made.
	 * @param array<int, string> $visited The states the object has occupied in this pass.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly int $moves,
		public readonly array $visited,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister ObjectSlugDecision
 *
 * One slug decision: the wanted slug, who holds what, and whether a clash is logged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Closure;

/**
 * What ObjectSlugHandler::decide() needs to resolve the slug a candidate becomes.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
 */
final class ObjectSlugDecision {

	/**
	 * Hold the decision's inputs.
	 *
	 * @param ObjectSlugWant $want     The wanted slug, its scope, the object and the path
	 *                                 (restore, move and seed always log a suffixed outcome).
	 * @param Closure        $holderOf fn(string $slug): ?string — uuid holding $slug, other than this object.
	 * @param bool           $log      Log a collision (false for a backfill dry run).
	 * @param string|null    $suffixed The suffixed slug to try, when the caller already computed it.
	 */
	public function __construct(
		public readonly ObjectSlugWant $want,
		public readonly Closure $holderOf,
		public readonly bool $log,
		public readonly ?string $suffixed=null,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister ObjectSlugWant
 *
 * One object asking for one slug in one register and schema (#4037).
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

/**
 * The question a slug decision answers, and what its log lines name.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */
final class ObjectSlugWant {

	/**
	 * Constructor.
	 *
	 * @param string          $slug       The wanted slug (or, for a frozen object, the slug that stays).
	 * @param ObjectSlugScope $scope      Where it must be unique.
	 * @param string          $objectUuid The object asking.
	 * @param string          $path       single | bulk | backfill | restore | move | seed (log context).
	 */
	public function __construct(
		public readonly string $slug,
		public readonly ObjectSlugScope $scope,
		public readonly string $objectUuid,
		public readonly string $path,
	) {
	}//end __construct()
}//end class

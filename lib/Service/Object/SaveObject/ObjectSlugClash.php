<?php

/**
 * OpenRegister ObjectSlugClash
 *
 * A wanted slug another live object already holds (#4037).
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
 * Who wanted the slug, who holds it, and the suffixed variant to try instead.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
 */
final class ObjectSlugClash {

	/**
	 * Constructor.
	 *
	 * @param ObjectSlugWant $want       The wanted slug, its scope, the object and the path.
	 * @param string         $holderUuid The live object holding the wanted slug.
	 * @param string|null    $suffixed   The suffixed slug to try; null when the schema has no suffix.
	 */
	public function __construct(
		public readonly ObjectSlugWant $want,
		public readonly string $holderUuid,
		public readonly string|null $suffixed,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister ObjectService query services: read, search and shaping
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;


/**
 * ObjectService query services: read, search and shaping.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectQueryServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectReadServices    $read    The read bundle.
	 * @param ObjectSearchServices  $search  The search bundle.
	 * @param ObjectShapingServices $shaping The shaping bundle.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectReadServices $read,
		public readonly ObjectSearchServices $search,
		public readonly ObjectShapingServices $shaping,
	) {
	}//end __construct()
}//end class

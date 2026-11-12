<?php

/**
 * OpenRegister ObjectService access services: the caller and authorisation
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
 * ObjectService access services: the caller and authorisation.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectAccessServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectCallerServices        $caller        The caller bundle.
	 * @param ObjectAuthorisationServices $authorisation The authorisation bundle.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectCallerServices $caller,
		public readonly ObjectAuthorisationServices $authorisation,
	) {
	}//end __construct()
}//end class

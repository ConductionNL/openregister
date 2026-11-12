<?php

/**
 * OpenRegister ObjectService platform services: mappers, storage and runtime
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
 * ObjectService platform services: mappers, storage and runtime.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectPlatformServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectMapperServices  $mappers The mapper bundle.
	 * @param ObjectStorageServices $storage The storage bundle.
	 * @param ObjectRuntimeServices $runtime The runtime bundle.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectMapperServices $mappers,
		public readonly ObjectStorageServices $storage,
		public readonly ObjectRuntimeServices $runtime,
	) {
	}//end __construct()
}//end class

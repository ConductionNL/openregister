<?php

/**
 * OpenRegister Mapping Runtime Loader
 *
 * Loader that provides the MappingRuntime to Twig's extension system.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Twig;

use OCA\OpenRegister\Db\MappingMapperInterface;
use OCA\OpenRegister\Service\MappingServiceInterface;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * MappingRuntimeLoader provides MappingRuntime instances to the Twig environment.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 */
class MappingRuntimeLoader implements RuntimeLoaderInterface {
	/**
	 * MappingRuntimeLoader constructor
	 *
	 * @param MappingServiceInterface $mappingService The mapping service
	 * @param MappingMapperInterface $mappingMapper The mapping mapper
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly MappingServiceInterface $mappingService,
		private readonly MappingMapperInterface $mappingMapper,
	) {
	}//end __construct()

	/**
	 * Load a Twig runtime by class name.
	 *
	 * @param string $class The runtime class to load
	 *
	 * @return MappingRuntime|null The runtime instance or null if not this class
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function load(string $class): ?MappingRuntime {
		if ($class === MappingRuntime::class) {
			return new MappingRuntime(
				mappingService: $this->mappingService,
				mappingMapper: $this->mappingMapper,
			);
		}

		return null;
	}//end load()
}//end class

<?php

/**
 * OpenRegister Mapping Runtime Loader
 *
 * Loader that provides the MappingRuntime to Twig's extension system.
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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

use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * MappingRuntimeLoader provides MappingRuntime instances to the Twig environment.
 *
 * @category Twig
 * @package  OCA\OpenRegister\Twig
 */
class MappingRuntimeLoader implements RuntimeLoaderInterface
{
    /**
     * MappingRuntimeLoader constructor
     *
     * @param MappingService $mappingService The mapping service
     * @param MappingMapper  $mappingMapper  The mapping mapper
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-28
=======
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function __construct(
        private readonly MappingService $mappingService,
        private readonly MappingMapper $mappingMapper,
    ) {
    }//end __construct()

    /**
     * Load a Twig runtime by class name.
     *
     * @param string $class The runtime class to load
     *
     * @return MappingRuntime|null The runtime instance or null if not this class
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-28
=======
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-28
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function load(string $class): ?MappingRuntime
    {
        if ($class === MappingRuntime::class) {
            return new MappingRuntime(
                mappingService: $this->mappingService,
                mappingMapper: $this->mappingMapper,
            );
        }

        return null;
    }//end load()
}//end class

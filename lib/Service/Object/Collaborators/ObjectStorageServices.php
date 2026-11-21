<?php

/**
 * OpenRegister ObjectService storage services: folder access, caches and object sources
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

use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFolderAccessInterface;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;

/**
 * ObjectService storage services: folder access, caches and object sources.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectStorageServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectFolderAccessInterface $fileService          The access check on an object's bound folder.
	 * @param CacheHandlerInterface       $cacheHandler         Service for entity and query caching.
	 * @param ObjectSourceRegistry        $objectSourceRegistry Registry of object-source providers (virtual schemas).
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly ObjectFolderAccessInterface $fileService,
		public readonly CacheHandlerInterface $cacheHandler,
		public readonly ObjectSourceRegistry $objectSourceRegistry,
	) {
	}//end __construct()
}//end class

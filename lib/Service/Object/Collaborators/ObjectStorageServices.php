<?php

/**
 * OpenRegister ObjectService storage services: files, caches, object sources and search trails
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

use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\SearchTrailService;

/**
 * ObjectService storage services: files, caches, object sources and search trails.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectStorageServices {
	/**
	 * Bundle the services.
	 *
	 * @param FileFolderServiceInterface $fileService          Service for file operations.
	 * @param CacheHandlerInterface      $cacheHandler         Service for entity and query caching.
	 * @param ObjectSourceRegistry       $objectSourceRegistry Registry of object-source providers (virtual schemas).
	 * @param SearchTrailService         $searchTrailService   Service for search trail operations.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly FileFolderServiceInterface $fileService,
		public readonly CacheHandlerInterface $cacheHandler,
		public readonly ObjectSourceRegistry $objectSourceRegistry,
		public readonly SearchTrailService $searchTrailService,
	) {
	}//end __construct()
}//end class

<?php

/**
 * The caches, sources and run context a single-object save consults.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use Psr\Container\ContainerInterface;

/**
 * The caches, sources and run context a single-object save consults.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectRuntime {
	/**
	 * Bundle the collaborators.
	 *
	 * @param CacheHandlerInterface $cacheHandler         Object entity and query cache.
	 * @param ?ObjectSourceRegistry $objectSourceRegistry Writable object-source provider registry.
	 * @param ?FlowRunContext       $runContext           The ambient flow-run stack, so the lock guard can tell which run is writing.
	 * @param ?ContainerInterface   $container            App container, consulted lazily for optional services.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly CacheHandlerInterface $cacheHandler,
		public readonly ?ObjectSourceRegistry $objectSourceRegistry = null,
		public readonly ?FlowRunContext $runContext = null,
		public readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()
}//end class

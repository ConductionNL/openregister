<?php

/**
 * OpenRegister tags controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The services of TagsController, bundled so its constructor stays small.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-4
 */
final class TagsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectService           $objectService  Object service instance
	 * @param FileServiceInterface    $fileService    File service instance for tag retrieval
	 * @param TaggingHandlerInterface $taggingHandler Tagging handler for object-level tags
	 */
	public function __construct(
		public readonly ObjectService $objectService,
		public readonly FileServiceInterface $fileService,
		public readonly TaggingHandlerInterface $taggingHandler,
	) {
	}//end __construct()
}//end class

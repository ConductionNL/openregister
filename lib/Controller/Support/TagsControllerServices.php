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

/**
 * The tag sources of the tags API: the file tag catalogue and the object tagging handler.
 */
final class TagsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FileServiceInterface    $fileService    File service for the system-wide tag list.
	 * @param TaggingHandlerInterface $taggingHandler Tagging handler for object-level tags.
	 */
	public function __construct(
		public readonly FileServiceInterface $fileService,
		public readonly TaggingHandlerInterface $taggingHandler,
	) {
	}//end __construct()
}//end class

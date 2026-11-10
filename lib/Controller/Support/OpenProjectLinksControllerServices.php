<?php

/**
 * OpenRegister open project links controller services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\OpenProjectLinkServiceInterface;

/**
 * The services of the open project links API.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
 */
final class OpenProjectLinksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param OpenProjectLinkServiceInterface $linkService   Backing service.
	 * @param ObjectOperationsInterface       $objectService OR object resolver.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function __construct(
		public readonly OpenProjectLinkServiceInterface $linkService,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister poll links controller services
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
use OCA\OpenRegister\Service\PollLinkServiceInterface;

/**
 * The services of the poll links API.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
 */
final class PollLinksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param PollLinkServiceInterface  $pollLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService   OR object resolver.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function __construct(
		public readonly PollLinkServiceInterface $pollLinkService,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class

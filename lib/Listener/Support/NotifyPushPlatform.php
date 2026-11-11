<?php

/**
 * OpenRegister notify push platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The logger, the container the push queue comes from and the app config the live-update listener reads its switch from.
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-4
 */
final readonly class NotifyPushPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param LoggerInterface    $logger    Logger.
	 * @param ContainerInterface $container Container the notify_push queue is resolved from.
	 * @param IAppConfig         $appConfig App config holding the live-update switch.
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-4
	 */
	public function __construct(
		public LoggerInterface $logger,
		public ContainerInterface $container,
		public IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class

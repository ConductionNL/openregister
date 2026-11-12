<?php

/**
 * How a notification reaches other apps and the outside.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\IServerContainer;

/**
 * The dispatcher's reach beyond its own channels: outbound HTTP (webhook),
 * the background job list (web-push dispatch), app services resolved at
 * runtime (parties, referenced addresses, outbound transports, expression
 * resolvers) and the installed-app lookup for the origin app's name.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class AppIntegrations {
	/**
	 * Constructor.
	 *
	 * @param IClientService   $httpClient HTTP client for the `webhook` channel.
	 * @param IServerContainer $serverContainer Resolves app services at runtime.
	 * @param ?IJobList        $jobList Job list used to enqueue the web-push dispatch job.
	 * @param ?IAppManager     $appManager Resolves the origin app's display name.
	 */
	public function __construct(
		public IClientService $httpClient,
		public IServerContainer $serverContainer,
		public ?IJobList $jobList = null,
		public ?IAppManager $appManager = null,
	) {
	}//end __construct()
}//end class

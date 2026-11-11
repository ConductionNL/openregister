<?php

/**
 * OpenRegister api token settings controller services
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

use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * The services of the api token settings API.
 *
 * @spec openspec/specs/production-observability/spec.md
 */
final class ApiTokenSettingsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ConnectionReporting  $reporting       App configuration and the connection reporter for saves and token tests.
	 * @param AppSettingsInterface $settingsService Settings service.
	 * @param IClientService       $clientService   HTTP client factory for the token probes.
	 * @param LoggerInterface      $logger          Logger.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function __construct(
		public readonly ConnectionReporting $reporting,
		public readonly AppSettingsInterface $settingsService,
		public readonly IClientService $clientService,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class

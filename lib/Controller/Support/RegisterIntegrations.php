<?php

/**
 * OpenRegister register integration services
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

use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Service\OasServiceInterface;
use Psr\Container\ContainerInterface;

/**
 * Configuration import and export, GitHub publishing, OpenAPI output and the DI container.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterIntegrations {
	/**
	 * Bundle the services.
	 *
	 * @param ConfigurationServiceInterface  $configurationService The configuration service.
	 * @param GitHubClientInterface $githubService        The GitHub client.
	 * @param OasServiceInterface   $oasService           The OpenAPI service.
	 * @param ContainerInterface    $container            The DI container.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function __construct(
		public readonly ConfigurationServiceInterface $configurationService,
		public readonly GitHubClientInterface $githubService,
		public readonly OasServiceInterface $oasService,
		public readonly ContainerInterface $container,
	) {
	}//end __construct()
}//end class

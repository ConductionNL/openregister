<?php

/**
 * Where the GitLab API lives and how to authenticate to it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCP\IAppConfig;
use OCP\IConfig;

/**
 * The GitLab API base URL and authentication headers, from app and system settings.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */
class GitLabApiSettings {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App-level settings: the API URL and the token.
	 * @param IConfig    $config    System-level settings: the fallback API URL.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * The API base URL; the app-level setting takes precedence over the system setting.
	 *
	 * @return string The base URL.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function apiBase(): string {
		$apiBase = $this->appConfig->getValueString('openregister', 'gitlab_api_url', '');
		if (empty($apiBase) === true) {
			$apiBase = $this->config->getSystemValue('gitlab_api_url', 'https://gitlab.com/api/v4');
		}

		return $apiBase;
	}//end apiBase()

	/**
	 * The authentication headers: the private token when one is configured.
	 *
	 * @return string[]
	 *
	 * @psalm-return array{'PRIVATE-TOKEN'?: string}
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function headers(): array {
		$headers = [];

		$token = $this->appConfig->getValueString('openregister', 'gitlab_api_token', '');
		if (empty($token) === false) {
			$headers['PRIVATE-TOKEN'] = $token;
		}

		return $headers;
	}//end headers()
}//end class

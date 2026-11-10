<?php

/**
 * GET requests against the configured GitLab API.
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

use GuzzleHttp\Exception\GuzzleException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;

/**
 * Sends authenticated GET requests to the GitLab API and decodes their JSON bodies.
 *
 * The base URL is resolved once, when the client is built.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */
class GitLabApiClient {
	/**
	 * The HTTP client for API requests.
	 *
	 * @var IClient
	 */
	private readonly IClient $client;

	/**
	 * The GitLab API base URL.
	 *
	 * @var string
	 */
	private readonly string $apiBase;

	/**
	 * Constructor.
	 *
	 * @param IClientService    $clientService Builds the HTTP client.
	 * @param GitLabApiSettings $settings      The API base URL and authentication headers.
	 */
	public function __construct(
		IClientService $clientService,
		private readonly GitLabApiSettings $settings,
	) {
		$this->client = $clientService->newClient();
		$this->apiBase = $this->settings->apiBase();
	}//end __construct()

	/**
	 * The GitLab API base URL.
	 *
	 * @return string The base URL.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function apiBase(): string {
		return $this->apiBase;
	}//end apiBase()

	/**
	 * GET a path of the API with the authentication headers and decode the JSON body.
	 *
	 * @param string               $path    The path after the API base.
	 * @param array<string, mixed> $options Request options; the headers are added last.
	 *
	 * @return mixed The decoded body.
	 *
	 * @throws GuzzleException If the request fails.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getJson(string $path, array $options = []): mixed {
		$response = $this->client->request('GET', $this->apiBase.$path, $options + ['headers' => $this->settings->headers()]);
		return json_decode($response->getBody(), true);
	}//end getJson()
}//end class

<?php

/**
 * OpenRegister API Token Settings Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ApiTokenProbe;
use OCA\OpenRegister\Controller\Support\ApiTokenSettingsControllerServices;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\Connection\ConnectionReporterInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for API token management.
 *
 * Handles:
 * - GitHub API tokens
 * - GitLab API tokens
 * - Token testing and validation
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class ApiTokenSettingsController extends Controller {
	/**
	 * The request field of each token and the config key it is saved under.
	 *
	 * @var array<string, string>
	 */
	private const TOKEN_CONFIG_KEYS = [
		'github_token' => 'github_api_token',
		'gitlab_token' => 'gitlab_api_token',
	];

	/**
	 * The display name of each connection key.
	 *
	 * @var array<string, string>
	 */
	private const SERVICE_LABELS = [
		'github' => 'GitHub',
		'gitlab' => 'GitLab',
	];

	/**
	 * The GitLab API used when none is saved or sent.
	 *
	 * @var string
	 */
	private const GITLAB_DEFAULT_API_URL = 'https://gitlab.com/api/v4';

	/**
	 * App configuration.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $config;

	/**
	 * Settings service.
	 *
	 * @var AppSettingsInterface
	 */
	private readonly AppSettingsInterface $settingsService;

	/**
	 * HTTP client factory for the token probes.
	 *
	 * @var IClientService
	 */
	private readonly IClientService $clientService;

	/**
	 * Tells integriq's connection registry about saves and token tests.
	 *
	 * @var ConnectionReporterInterface
	 */
	private readonly ConnectionReporterInterface $connectionReporter;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ApiTokenSettingsControllerServices $services The api token settings controller services.
	 */
	public function __construct(
		IRequest $request,
		ApiTokenSettingsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->config = $services->reporting->appConfig;
		$this->settingsService = $services->settingsService;
		$this->clientService = $services->clientService;
		$this->connectionReporter = $services->reporting->connectionReporter;
		$this->logger = $services->logger;
	}//end __construct()

	/**
	 * Get API tokens for GitHub and GitLab
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The API tokens
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{error?: string, github_token?: string, gitlab_token?: string,
	 *     gitlab_url?: string}, array<never, never>>
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getApiTokens(): JSONResponse {
		try {
			$githubToken = $this->config->getValueString('openregister', 'github_api_token', '');
			$gitlabToken = $this->config->getValueString('openregister', 'gitlab_api_token', '');
			$gitlabUrl = $this->config->getValueString('openregister', 'gitlab_api_url', '');
			$masked = ['github_token' => $this->masked(token: $githubToken), 'gitlab_token' => $this->masked(token: $gitlabToken)];

			return new JSONResponse(data: array_merge($masked, ['gitlab_url' => $gitlabUrl]));
		} catch (Exception $e) {
			return $this->storeFailure(action: 'retrieve', e: $e);
		}
	}//end getApiTokens()

	/**
	 * Mask a token for display (only the first and last few characters show).
	 *
	 * @param string $token The token, or '' when none is saved.
	 *
	 * @return string The masked token, or '' when none is saved.
	 */
	private function masked(string $token): string {
		if ($token === '') {
			return '';
		}

		return $this->settingsService->maskToken($token);
	}//end masked()

	/**
	 * Save API tokens for GitHub and GitLab
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with save result
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function saveApiTokens(): JSONResponse {
		try {
			$written = $this->saveTokenSettings(data: $this->request->getParams());
			// The GitHub and GitLab rows read these keys; integriq decides again.
			$this->connectionReporter->refreshFromSave(savedKeys: $written);

			return new JSONResponse(data: ['success' => true, 'message' => 'API tokens saved successfully']);
		} catch (Exception $e) {
			return $this->storeFailure(action: 'save', e: $e);
		}
	}//end saveApiTokens()

	/**
	 * Write the tokens and the GitLab URL the request carries, skipping masked tokens.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return list<string> The config keys written.
	 */
	private function saveTokenSettings(array $data): array {
		$written = [];
		foreach (self::TOKEN_CONFIG_KEYS as $field => $configKey) {
			if ($this->isNewToken(value: ($data[$field] ?? null)) === true) {
				$this->config->setValueString('openregister', $configKey, $data[$field]);
				$written[] = $configKey;
			}
		}

		return array_merge($written, $this->saveGitLabUrl(data: $data));
	}//end saveTokenSettings()

	/**
	 * Write the GitLab URL when the request carries one.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return list<string> The config keys written.
	 */
	private function saveGitLabUrl(array $data): array {
		if (($data['gitlab_url'] ?? null) === null) {
			return [];
		}

		$this->config->setValueString('openregister', 'gitlab_api_url', $data['gitlab_url']);
		return ['gitlab_api_url'];
	}//end saveGitLabUrl()

	/**
	 * Whether a sent token is a new value rather than absent or the masked one echoed back.
	 *
	 * @param mixed $value The token as sent.
	 *
	 * @return bool True when the token should be saved.
	 */
	private function isNewToken(mixed $value): bool {
		return $value !== null && str_contains($value, '***') === false;
	}//end isNewToken()

	/**
	 * The 500 answer for a token read or write that threw.
	 *
	 * @param string $action What failed: 'retrieve' or 'save'.
	 * @param Exception $e The exception.
	 *
	 * @return JSONResponse The failure.
	 */
	private function storeFailure(string $action, Exception $e): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Failed to ' . $action . ' API tokens: ' . $e->getMessage()],
			statusCode: 500
		);
	}//end storeFailure()

	/**
	 * Test GitHub API token
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Test result
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function testGitHubToken(): JSONResponse {
		$probe = new ApiTokenProbe(service: 'github', token: null, testsSavedToken: false);
		try {
			$probe = $this->gitHubProbe(data: $this->request->getParams());
			if (empty($probe->token) === true) {
				return $this->missingToken(probe: $probe);
			}

			return $this->gitHubTokenValid(probe: $probe, response: $this->requestGitHubUser(token: $probe->token));
		} catch (Throwable $e) {
			return $this->tokenTestFailed(probe: $probe, e: $e);
		}//end try
	}//end testGitHubToken()

	/**
	 * The GitHub token to test: the one sent, else the saved one.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return ApiTokenProbe The probe; only a test of the saved token says anything about the saved connection.
	 */
	private function gitHubProbe(array $data): ApiTokenProbe {
		$savedToken = $this->config->getValueString('openregister', 'github_api_token', '');
		$token = $data['token'] ?? $savedToken;

		return new ApiTokenProbe(
			service: 'github',
			token: $token,
			testsSavedToken: ($token !== '' && $token === $savedToken)
		);
	}//end gitHubProbe()

	/**
	 * Ask GitHub who the token belongs to.
	 *
	 * @param mixed $token The token to test.
	 *
	 * @return IResponse The GitHub answer.
	 */
	private function requestGitHubUser(mixed $token): mixed {
		return $this->clientService->newClient()->get(
			'https://api.github.com/user',
			[
				'headers' => [
					'Accept' => 'application/vnd.github+json',
					'Authorization' => 'Bearer ' . $token,
					'X-GitHub-Api-Version' => '2022-11-28',
				],
			]
		);
	}//end requestGitHubUser()

	/**
	 * Answer a GitHub token GitHub accepted, reporting it when it is the saved one.
	 *
	 * @param ApiTokenProbe $probe The probe.
	 * @param IResponse $response GitHub's answer.
	 *
	 * @return JSONResponse The account and scopes the token carries.
	 */
	private function gitHubTokenValid(ApiTokenProbe $probe, mixed $response): JSONResponse {
		$data = json_decode($response->getBody(), true);
		$this->reportSavedTokenValid(probe: $probe, detail: ($data['login'] ?? 'an unknown account') . '.');

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'GitHub token is valid',
				'username' => $data['login'] ?? 'Unknown',
				'scopes' => $response->getHeader('X-OAuth-Scopes'),
			]
		);
	}//end gitHubTokenValid()

	/**
	 * Test GitLab API token
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Test result
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function testGitLabToken(): JSONResponse {
		$probe = new ApiTokenProbe(service: 'gitlab', token: null, testsSavedToken: false);
		try {
			$probe = $this->gitLabProbe(data: $this->request->getParams());
			if (empty($probe->token) === true) {
				return $this->missingToken(probe: $probe);
			}

			$probe = $this->onGitLabBaseUrl(probe: $probe);
			return $this->gitLabTokenValid(probe: $probe, response: $this->requestGitLabUser(probe: $probe));
		} catch (Throwable $e) {
			return $this->tokenTestFailed(probe: $probe, e: $e);
		}//end try
	}//end testGitLabToken()

	/**
	 * The GitLab token and API URL to test: the ones sent, else the saved ones.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 *
	 * @return ApiTokenProbe The probe; only a test of the saved token against the saved URL says anything about the saved connection.
	 */
	private function gitLabProbe(array $data): ApiTokenProbe {
		$savedToken = $this->config->getValueString('openregister', 'gitlab_api_token', '');
		$token = $data['token'] ?? $savedToken;
		$savedApiUrl = $this->config->getValueString('openregister', 'gitlab_api_url', self::GITLAB_DEFAULT_API_URL);
		$apiUrl = $data['url'] ?? $savedApiUrl;

		return new ApiTokenProbe(
			service: 'gitlab',
			token: $token,
			testsSavedToken: ($token !== '' && $token === $savedToken && $apiUrl === $savedApiUrl),
			apiUrl: $apiUrl
		);
	}//end gitLabProbe()

	/**
	 * The probe aimed at its API URL without trailing slashes, gitlab.com's when that leaves nothing.
	 *
	 * @param ApiTokenProbe $probe The probe with the API URL as sent or saved.
	 *
	 * @return ApiTokenProbe The probe with the base URL to call.
	 */
	private function onGitLabBaseUrl(ApiTokenProbe $probe): ApiTokenProbe {
		$apiUrl = rtrim($probe->apiUrl, '/');
		if (empty($apiUrl) === true) {
			$apiUrl = self::GITLAB_DEFAULT_API_URL;
		}

		return new ApiTokenProbe(
			service: $probe->service,
			token: $probe->token,
			testsSavedToken: $probe->testsSavedToken,
			apiUrl: $apiUrl
		);
	}//end onGitLabBaseUrl()

	/**
	 * Ask GitLab who the token belongs to.
	 *
	 * @param ApiTokenProbe $probe The probe, aimed at the base URL to call.
	 *
	 * @return IResponse The GitLab answer.
	 */
	private function requestGitLabUser(ApiTokenProbe $probe): mixed {
		return $this->clientService->newClient()->get(
			$probe->apiUrl . '/user',
			['headers' => ['PRIVATE-TOKEN' => $probe->token]]
		);
	}//end requestGitLabUser()

	/**
	 * Answer a GitLab token GitLab accepted, reporting it when it is the saved one.
	 *
	 * @param ApiTokenProbe $probe The probe, aimed at the base URL called.
	 * @param IResponse $response GitLab's answer.
	 *
	 * @return JSONResponse The account and instance the token works on.
	 */
	private function gitLabTokenValid(ApiTokenProbe $probe, mixed $response): JSONResponse {
		$data = json_decode($response->getBody(), true);
		$detail = ($data['username'] ?? 'an unknown account') . ' on ' . $probe->apiUrl . '.';
		$this->reportSavedTokenValid(probe: $probe, detail: $detail);

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'GitLab token is valid',
				'username' => $data['username'] ?? 'Unknown',
				'instance' => $probe->apiUrl,
			]
		);
	}//end gitLabTokenValid()

	/**
	 * The 400 answer for a token test with no token to test.
	 *
	 * @param ApiTokenProbe $probe The probe.
	 *
	 * @return JSONResponse The rejection.
	 */
	private function missingToken(ApiTokenProbe $probe): JSONResponse {
		return new JSONResponse(
			data: ['success' => false, 'message' => 'No ' . self::SERVICE_LABELS[$probe->service] . ' token provided'],
			statusCode: 400
		);
	}//end missingToken()

	/**
	 * Tell the connection registry the saved token works, when the probe tested the saved one.
	 *
	 * @param ApiTokenProbe $probe The probe.
	 * @param string $detail Who the token belongs to, ending the sentence.
	 *
	 * @return void
	 */
	private function reportSavedTokenValid(ApiTokenProbe $probe, string $detail): void {
		if ($probe->testsSavedToken === true) {
			$this->connectionReporter->report(
				key: $probe->service,
				status: 'configured',
				message: 'The saved token is valid for ' . $detail
			);
		}
	}//end reportSavedTokenValid()

	/**
	 * The 400 answer for a token test that threw; a refused saved token is also reported.
	 *
	 * @param ApiTokenProbe $probe The probe.
	 * @param Throwable $e What went wrong.
	 *
	 * @return JSONResponse The failure.
	 */
	private function tokenTestFailed(ApiTokenProbe $probe, Throwable $e): JSONResponse {
		$label = self::SERVICE_LABELS[$probe->service];
		if ($probe->testsSavedToken === true) {
			$this->connectionReporter->report(
				key: $probe->service,
				status: 'error',
				message: $label . ' refused the saved token: ' . $e->getMessage()
			);
		}

		return new JSONResponse(
			data: ['success' => false, 'message' => $label . ' token test failed: ' . $e->getMessage()],
			statusCode: 400
		);
	}//end tokenTestFailed()
}//end class

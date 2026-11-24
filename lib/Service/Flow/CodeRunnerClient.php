<?php

/**
 * CodeRunnerClient: reaches the flow-code-runner ExApp through AppAPI.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\App\IAppManager;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs authored code in the `flow-code-runner` ExApp.
 *
 * In the shape of AnonymisationBackendService: AppAPI's PublicFunctions is
 * resolved lazily, so OpenRegister carries no hard dependency on AppAPI, and
 * ExApps reject unsigned requests, so a plain HTTP call would not reach it.
 * The health answer is cached for a minute, because the node catalogue asks
 * on every palette load.
 *
 * NO AUTHORED CODE RUNS HERE. This class sends the source to a separate
 * container and reads back items; there is no evaluation path in PHP.
 *
 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
 */
final class CodeRunnerClient implements CodeRunnerClientInterface {

	/**
	 * The AppAPI app id.
	 */
	private const APP_API = 'app_api';

	/**
	 * AppAPI's public entry point, named as a string so it is optional.
	 */
	private const PUBLIC_FUNCTIONS = 'OCA\\AppAPI\\PublicFunctions';

	/**
	 * Cache key of the health answer.
	 */
	private const HEALTH_KEY = 'health';

	/**
	 * How long a health answer is trusted, in seconds.
	 */
	private const HEALTH_TTL = 60;

	/**
	 * The health answer cache, or null when no cache backend is available.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager   Whether AppAPI and the runner are enabled.
	 * @param ContainerInterface $container    Resolves AppAPI's PublicFunctions when it is installed.
	 * @param ICacheFactory      $cacheFactory For the cached health answer.
	 * @param LoggerInterface    $logger       For runner failures.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		try {
			$this->cache = $cacheFactory->createDistributed('openregister_code_runner');
		} catch (Throwable $e) {
			$this->logger->warning('[CodeRunnerClient] cache backend unavailable: ' . $e->getMessage());
			$this->cache = null;
		}

	}//end __construct()

	/**
	 * Whether the runner is installed, enabled and answers its health check.
	 *
	 * @return bool Whether code steps can run.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-without-the-runner-the-code-step-is-hidden-and-refused
	 */
	public function isAvailable(): bool {
		$cached = $this->cache?->get(self::HEALTH_KEY);
		if ($cached === '1' || $cached === '0') {
			return $cached === '1';
		}

		$healthy = $this->probe();
		$flag = '0';
		if ($healthy === true) {
			$flag = '1';
		}

		$this->cache?->set(self::HEALTH_KEY, $flag, self::HEALTH_TTL);

		return $healthy;

	}//end isAvailable()

	/**
	 * Run one call in the runner.
	 *
	 * @param CodeRun $run The source, mode, items and limits.
	 *
	 * @return CodeRunAnswer The items the code returned, with the runner's log lines.
	 *
	 * @throws CodeRunFailedException When the runner is absent, refuses, or the code fails.
	 *
	 * @spec openspec/changes/flow-code-step-in-a-sidecar/specs/flow-engine/spec.md#requirement-a-code-step-runs-authored-javascript-outside-nextcloud
	 */
	public function run(CodeRun $run): CodeRunAnswer {
		$response = $this->request(route: '/run', method: 'POST', params: $run->toRequest());
		if ($response === null) {
			throw CodeRunFailedException::withLogs(
				message: sprintf('The code step needs the %s ExApp, which is not installed, not enabled or not answering.', self::APP_ID)
			);
		}

		return $this->answerOf(response: $response);

	}//end run()

	/**
	 * Ask the runner's health route.
	 *
	 * @return bool Whether it answered 2xx.
	 */
	private function probe(): bool {
		$response = $this->request(route: '/health', method: 'GET', params: []);
		if ($response === null) {
			return false;
		}

		$status = $response->getStatusCode();

		return $status >= 200 && $status < 300;

	}//end probe()

	/**
	 * Send one signed request to the runner, or null when it cannot be reached.
	 *
	 * @param string               $route  The runner route.
	 * @param string               $method The HTTP method.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return IResponse|null The response, or null when AppAPI, the runner or the request is not there.
	 */
	private function request(string $route, string $method, array $params): ?IResponse {
		if ($this->appManager->isEnabledForUser(self::APP_API) === false
			|| $this->appManager->isEnabledForUser(self::APP_ID) === false
			|| class_exists(self::PUBLIC_FUNCTIONS) === false
		) {
			return null;
		}

		try {
			$publicFunctions = $this->container->get(self::PUBLIC_FUNCTIONS);
			$response = $publicFunctions->exAppRequest(self::APP_ID, $route, null, $method, $params);
		} catch (Throwable $e) {
			$this->logger->error('[CodeRunnerClient] request to ' . self::APP_ID . $route . ' failed: ' . $e->getMessage());
			return null;
		}

		if (($response instanceof IResponse) === false) {
			// AppAPI answers an array only to signal an error.
			$error = 'unknown';
			if (is_array($response) === true) {
				$error = (string)($response['error'] ?? 'unknown');
			}

			$this->logger->error('[CodeRunnerClient] ' . self::APP_ID . $route . ' error: ' . $error);
			return null;
		}

		return $response;

	}//end request()

	/**
	 * Read the runner's answer to `POST /run`.
	 *
	 * @param IResponse $response The runner's response.
	 *
	 * @return CodeRunAnswer The items and log lines.
	 *
	 * @throws CodeRunFailedException When the code failed, or the answer is not one the runner gives.
	 */
	private function answerOf(IResponse $response): CodeRunAnswer {
		$status = $response->getStatusCode();
		$body = $response->getBody();
		$data = null;
		if (is_string($body) === true) {
			$data = json_decode($body, true);
		}

		if (is_array($data) === false) {
			throw CodeRunFailedException::withLogs(message: sprintf('The code runner answered HTTP %d without a readable body.', $status));
		}

		$logs = array_values(array_filter((array)($data['logs'] ?? []), 'is_scalar'));
		if (isset($data['error']) === true) {
			throw CodeRunFailedException::withLogs(message: sprintf('The code step failed: %s', (string)$data['error']), logs: $logs);
		}

		if ($status < 200 || $status >= 300 || is_array($data['items'] ?? null) === false || array_is_list($data['items']) === false) {
			throw CodeRunFailedException::withLogs(message: sprintf('The code runner answered HTTP %d without a list of items.', $status), logs: $logs);
		}

		foreach ($data['items'] as $item) {
			if (is_array($item) === false) {
				throw CodeRunFailedException::withLogs(message: 'The code returned an item that is not an object.', logs: $logs);
			}
		}

		return new CodeRunAnswer(items: $data['items'], logs: array_map('strval', $logs), durationMs: (int)($data['durationMs'] ?? 0));

	}//end answerOf()
}//end class

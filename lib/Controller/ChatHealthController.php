<?php

/**
 * OpenRegister Chat Health Controller
 *
 * Lightweight health probe for the AI chat backend. Allows the
 * nextcloud-vue AI companion widget to detect at mount time whether
 * the chat backend is configured and reachable — without requiring a
 * Nextcloud session (PublicPage).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\ChatFamilyControllerInterface;
use OCA\OpenRegister\Controller\Support\ChatHealthControllerServices;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * ChatHealthController
 *
 * Provides GET /api/chat/health — an unauthenticated endpoint that the
 * nextcloud-vue widget probes once at mount time to decide whether to
 * render the AI companion button.
 *
 * Returns HTTP 200 + {status:"ok",capabilities:["chat","stream"]} when at
 * least one LLM provider is configured; HTTP 503 + {status:"no_provider"}
 * otherwise.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 */
class ChatHealthController extends Controller implements ChatFamilyControllerInterface {

	/**
	 * Settings service for LLM configuration lookup.
	 *
	 * @var AppSettingsInterface
	 */
	private readonly AppSettingsInterface $settingsService;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request  HTTP request object
	 * @param ChatHealthControllerServices $services The controller's collaborators.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		ChatHealthControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->settingsService = $services->settingsService;
		$this->logger = $services->logger;
	}//end __construct()

	/**
	 * Health probe for the AI chat backend
	 *
	 * Returns 200 when a chat provider is configured, 503 otherwise.
	 * Annotated as PublicPage so the widget can probe without authentication.
	 *
	 * A health probe is polled by monitoring on a schedule, so the rate-limit
	 * ceiling is generous — it exists to stop an unauthenticated caller turning a
	 * liveness endpoint into a load generator, not to police the monitor.
	 *
	 * @PublicPage
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse 200 or 503 JSON response
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function health(): JSONResponse {
		try {
			return $this->providerHealth();
		} catch (\Throwable $e) {
			return $this->configError(exception: $e);
		}
	}//end health()

	/**
	 * The probe answer from the configured chat provider.
	 *
	 * @return JSONResponse 200 with the capabilities, or 503 `no_provider` when none is configured
	 */
	private function providerHealth(): JSONResponse {
		$llmConfig = $this->settingsService->getLLMSettingsOnly();
		$chatProvider = $llmConfig['chatProvider'] ?? null;

		if (empty($chatProvider) === true) {
			return new JSONResponse(
				data: ['status' => 'no_provider'],
				statusCode: 503
			);
		}

		return new JSONResponse(
			data: [
				'status' => 'ok',
				'capabilities' => ['chat', 'stream'],
			],
			statusCode: 200
		);
	}//end providerHealth()

	/**
	 * The probe answer when the LLM settings could not be read.
	 *
	 * Distinguish "configuration service failed" from "no provider
	 * configured". Returning 'no_provider' for both made it impossible to tell
	 * a fresh instance (genuinely unconfigured) from a broken config service
	 * (deserialisation, DB connectivity etc.). Operators need that signal; the
	 * widget can still treat any non-200 as "no AI" without branching.
	 *
	 * @param \Throwable $exception Why the settings could not be read
	 *
	 * @return JSONResponse 503 `config_error`
	 */
	private function configError(\Throwable $exception): JSONResponse {
		$this->logger->warning(
			message: '[ChatHealthController] Health probe failed reading LLM settings',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'exception' => $exception,
				'error' => $exception->getMessage(),
			]
		);
		return new JSONResponse(
			data: ['status' => 'config_error'],
			statusCode: 503
		);
	}//end configError()
}//end class

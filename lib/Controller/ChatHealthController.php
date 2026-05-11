<?php

/**
 * OpenRegister Chat Health Controller
 *
 * Lightweight health probe for the AI chat backend. Allows the
 * nextcloud-vue AI companion widget to detect at mount time whether
 * the chat backend is configured and reachable — without requiring a
 * Nextcloud session (PublicPage).
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
 * @spec openspec/changes/ai-chat-companion-orchestrator/specs/chat-ai/spec.md#health-probe-endpoint-get-apichathealth
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

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
class ChatHealthController extends Controller
{

    /**
     * Settings service for LLM configuration lookup.
     *
     * @var SettingsService
     */
    private readonly SettingsService $settingsService;

    /**
     * Constructor
     *
     * @param string          $appName         Application name
     * @param IRequest        $request         HTTP request object
     * @param SettingsService $settingsService Settings service for LLM config
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        SettingsService $settingsService
    ) {
        parent::__construct(appName: $appName, request: $request);
        $this->settingsService = $settingsService;
    }//end __construct()

    /**
     * Health probe for the AI chat backend
     *
     * Returns 200 when a chat provider is configured, 503 otherwise.
     * Annotated as PublicPage so the widget can probe without authentication.
     *
     * @PublicPage
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse 200 or 503 JSON response
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function health(): JSONResponse
    {
        try {
            $llmConfig    = $this->settingsService->getLLMSettingsOnly();
            $chatProvider = $llmConfig['chatProvider'] ?? null;

            if (empty($chatProvider) === true) {
                return new JSONResponse(
                    data: ['status' => 'no_provider'],
                    statusCode: 503
                );
            }

            return new JSONResponse(
                data: [
                    'status'       => 'ok',
                    'capabilities' => ['chat', 'stream'],
                ],
                statusCode: 200
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['status' => 'no_provider'],
                statusCode: 503
            );
        }//end try
    }//end health()
}//end class

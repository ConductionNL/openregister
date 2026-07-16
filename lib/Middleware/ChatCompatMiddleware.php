<?php

/**
 * OpenRegister Chat Compat Middleware
 *
 * Implements the or-chat-proxy-deprecation compat window (hermiq
 * agent-core migration, hydra ADR-034 amendment, SPECTR-NEXTCLOUD-PLAN.md
 * §7.4 step 6): OR's chat/agents/conversations API keeps working exactly as
 * today, but every response now carries deprecation metadata pointing at
 * hermiq as the successor, and an operator MAY opt into an optional
 * proxy-to-hermiq mode via the `openregister.chat.proxyTo` appconfig value.
 *
 * Both legs are strictly additive/opt-in:
 * - Deprecation headers apply unconditionally to every chat-family
 *   controller response — this changes no behaviour, only adds headers.
 * - The proxy leg is OFF by default (`chat.proxyTo` empty). When an operator
 *   sets it to 'hermiq', requests are forwarded server-side to hermiq's
 *   mirrored routes; any failure (hermiq not installed, unreachable, or a
 *   transport error) falls back to serving the request locally exactly as
 *   before, with a logged warning — it never surfaces as an error response.
 *
 * Deleting OR's own chat engine (ChatService, the Chat/* controllers, the
 * underlying tables) is explicitly OUT OF SCOPE here — see design.md's
 * "future removal change" note.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Controller\AgentsController;
use OCA\OpenRegister\Controller\ChatController;
use OCA\OpenRegister\Controller\ChatHealthController;
use OCA\OpenRegister\Controller\ChatStreamController;
use OCA\OpenRegister\Controller\ConversationController;
use OCA\OpenRegister\Middleware\Exception\ChatProxiedResponseException;
use OCA\OpenRegister\Service\Chat\ChatProxyHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Deprecation-header decorator + optional hermiq proxy short-circuit for
 * OpenRegister's chat/agents/conversations controllers.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 */
class ChatCompatMiddleware extends Middleware
{

    /**
     * Controllers this middleware decorates/may proxy. Membership is checked
     * via `instanceof` (not `get_class()`) so PHPUnit mocks — which subclass
     * the real controller — match correctly.
     *
     * @var array<int, class-string>
     */
    private const CHAT_FAMILY_CONTROLLERS = [
        ChatController::class,
        ChatStreamController::class,
        ChatHealthController::class,
        ConversationController::class,
        AgentsController::class,
    ];

    /**
     * Methods on a chat-family controller that render the SPA page shell
     * rather than an API response (e.g. `AgentsController::page()`). Never
     * proxied — the shell's static assets still ship with OpenRegister
     * during the compat window; only the `chatAppId` flip (separate,
     * nextcloud-vue-side work) changes which backend the SPA talks to.
     *
     * @var array<int, string>
     */
    private const PAGE_SHELL_METHODS = ['page'];

    /**
     * Controllers proxied via a redirect rather than a body relay — see
     * ChatProxyHandler::buildRedirectResponse()'s docblock for why.
     *
     * @var array<int, class-string>
     */
    private const STREAMING_CONTROLLERS = [
        ChatStreamController::class,
    ];

    /**
     * `Deprecation` response header value (RFC 8594-style — an HTTP-date
     * marking when the deprecation posture took effect).
     *
     * @var string
     */
    private const DEPRECATION_DATE = 'Mon, 06 Jul 2026 00:00:00 GMT';

    /**
     * `Sunset` response header value (RFC 8594) — the earliest the
     * *following* removal change may ship. At least one full release cycle
     * out; the actual removal is a separate, not-yet-specified change (see
     * design.md).
     *
     * @var string
     */
    private const SUNSET_DATE = 'Wed, 06 Jan 2027 00:00:00 GMT';

    /**
     * `Link: <...>; rel="successor-version"` target.
     *
     * @var string
     */
    private const SUCCESSOR_LINK_PATH = '/apps/hermiq/api/chat';

    /**
     * Constructor.
     *
     * @param IRequest         $request      The incoming request.
     * @param ChatProxyHandler $proxyHandler Resolves config, rewrites paths, performs the outbound calls.
     */
    public function __construct(
        private readonly IRequest $request,
        private readonly ChatProxyHandler $proxyHandler
    ) {
    }//end __construct()

    /**
     * Attempt the optional hermiq proxy short-circuit.
     *
     * Throws `ChatProxiedResponseException` (carrying the built response)
     * when the proxy is configured, hermiq is installed, and the forward
     * (or, for the streaming controller, the reachability-gated redirect)
     * succeeds. In every other case this returns normally and the local
     * controller method runs exactly as it does today.
     *
     * @param mixed  $controller The controller instance.
     * @param string $methodName The method name being called.
     *
     * @return void
     *
     * @throws ChatProxiedResponseException When the request has been served by the proxy.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeController($controller, $methodName): void
    {
        $hermiqPath = $this->resolveHermiqPath(controller: $controller, methodName: $methodName);
        if ($hermiqPath === null) {
            return;
        }

        $response = $this->attemptProxy(controller: $controller, hermiqPath: $hermiqPath);
        if ($response === null) {
            // Unreachable / transport failure — fall back to local serving.
            // Do NOT throw; returning normally lets the real controller
            // method run exactly as it would with the proxy off.
            return;
        }

        throw new ChatProxiedResponseException(response: $response);
    }//end beforeController()

    /**
     * Resolve the guards that decide whether the proxy is even eligible for
     * this call, and — if so — the rewritten hermiq path.
     *
     * @param mixed  $controller The controller instance.
     * @param string $methodName The method name being called.
     *
     * @return string|null The rewritten `/apps/hermiq/...` path, or null when any guard fails.
     */
    private function resolveHermiqPath($controller, $methodName): ?string
    {
        if ($this->isChatFamilyController(controller: $controller) === false) {
            return null;
        }

        if (in_array($methodName, self::PAGE_SHELL_METHODS, true) === true) {
            return null;
        }

        if ($this->proxyHandler->isProxyConfigured() === false) {
            return null;
        }

        if ($this->proxyHandler->isHermiqInstalled() === false) {
            return null;
        }

        $pathInfo = $this->request->getPathInfo();
        if ($pathInfo === false) {
            return null;
        }

        return $this->proxyHandler->rewritePathForHermiq(orPathInfo: $pathInfo);
    }//end resolveHermiqPath()

    /**
     * Perform the actual proxy call — a reachability-gated redirect for the
     * streaming controller, a JSON forward for every other chat-family
     * controller.
     *
     * @param mixed  $controller The controller instance.
     * @param string $hermiqPath The rewritten `/apps/hermiq/...` path.
     *
     * @return Response|null The proxied response, or null on any failure (fall back to local serving).
     */
    private function attemptProxy($controller, string $hermiqPath): ?Response
    {
        if ($this->isStreamingController(controller: $controller) === true) {
            return $this->attemptStreamRedirect(hermiqPath: $hermiqPath);
        }

        return $this->proxyHandler->forwardJsonRequest(request: $this->request, hermiqPath: $hermiqPath);
    }//end attemptProxy()

    /**
     * Handle the proxy short-circuit exception, returning its carried
     * response. Any other exception is re-thrown (default Middleware
     * behaviour) so normal error handling proceeds unaffected.
     *
     * @param mixed     $controller The controller instance.
     * @param string    $methodName The method name being called.
     * @param Exception $exception  The thrown exception.
     *
     * @return Response The proxied response.
     *
     * @throws Exception The passed-in exception when it is not ours to handle.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterException($controller, $methodName, Exception $exception): Response
    {
        if ($exception instanceof ChatProxiedResponseException) {
            return $exception->getResponse();
        }

        throw $exception;
    }//end afterException()

    /**
     * Decorate every chat-family controller response — proxied or local —
     * with the compat-window deprecation headers.
     *
     * @param mixed    $controller The controller instance.
     * @param string   $methodName The method name that was called.
     * @param Response $response   The response to decorate.
     *
     * @return Response The (possibly decorated) response.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterController($controller, $methodName, Response $response): Response
    {
        if ($this->isChatFamilyController(controller: $controller) === false) {
            return $response;
        }

        $response->addHeader('Deprecation', self::DEPRECATION_DATE);
        $response->addHeader('Sunset', self::SUNSET_DATE);
        $response->addHeader('Link', '<'.self::SUCCESSOR_LINK_PATH.'>; rel="successor-version"');

        return $response;
    }//end afterController()

    /**
     * Attempt the reachability-gated redirect for the streaming controller.
     *
     * @param string $hermiqPath The rewritten `/apps/hermiq/...` path.
     *
     * @return Response|null The redirect response, or null when hermiq did not answer the probe.
     */
    private function attemptStreamRedirect(string $hermiqPath): ?Response
    {
        if ($this->proxyHandler->probeReachable() === false) {
            return null;
        }

        return $this->proxyHandler->buildRedirectResponse(request: $this->request, hermiqPath: $hermiqPath);
    }//end attemptStreamRedirect()

    /**
     * Whether the given controller is one of the chat/agents/conversations
     * family this middleware decorates.
     *
     * @param mixed $controller The controller instance.
     *
     * @return bool True when `$controller` is (or extends/mocks) one of CHAT_FAMILY_CONTROLLERS.
     */
    private function isChatFamilyController($controller): bool
    {
        if (($controller instanceof Controller) === false) {
            return false;
        }

        foreach (self::CHAT_FAMILY_CONTROLLERS as $class) {
            if ($controller instanceof $class) {
                return true;
            }
        }

        return false;
    }//end isChatFamilyController()

    /**
     * Whether the given controller is the SSE streaming controller (proxied
     * via redirect rather than a body relay).
     *
     * @param mixed $controller The controller instance.
     *
     * @return bool True when `$controller` is (or extends/mocks) ChatStreamController.
     */
    private function isStreamingController($controller): bool
    {
        foreach (self::STREAMING_CONTROLLERS as $class) {
            if ($controller instanceof $class) {
                return true;
            }
        }

        return false;
    }//end isStreamingController()
}//end class

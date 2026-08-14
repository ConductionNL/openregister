<?php

/**
 * OpenRegister Chat Proxy Handler
 *
 * Implements the "proxy-to-hermiq" leg of the or-chat-proxy-deprecation
 * compat window: this handler forwards chat/agents/conversations API calls
 * to hermiq's mirrored routes server-side, and falls back to local serving
 * whenever hermiq is not installed, not reachable, or the forward call
 * fails at the transport level. ON by default since
 * or-chat-engine-decommission (hermiq is the engine's canonical home per
 * hydra ADR-034 amendment, SPECTR-NEXTCLOUD-PLAN.md §7.4); an operator
 * opts out by setting the `openregister.chat.proxyTo` appconfig value to
 * anything other than `hermiq` (e.g. `off`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
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

namespace OCA\OpenRegister\Service\Chat;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves proxy configuration, rewrites OR paths onto their hermiq
 * mirror, and performs the actual outbound forward/redirect/reachability
 * calls used by ChatCompatMiddleware.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 */
class ChatProxyHandler {

	/**
	 * App id whose config holds the proxy target.
	 *
	 * @var string
	 */
	private const CONFIG_APP = 'openregister';

	/**
	 * Appconfig key. Since or-chat-engine-decommission an UNSET value means
	 * "proxy to hermiq" (hermiq is the agent engine's canonical home per
	 * hydra ADR-034 Amendment 2026-07-05). An operator opts out — restoring
	 * local-engine answering — by setting any other explicit value, e.g.
	 * `occ config:app:set openregister chat.proxyTo --value=off`.
	 *
	 * @var string
	 */
	private const CONFIG_KEY_PROXY_TO = 'chat.proxyTo';

	/**
	 * The only currently-supported proxy target.
	 *
	 * @var string
	 */
	private const HERMIQ_APP_ID = 'hermiq';

	/**
	 * OpenRegister's own path prefix, as returned by IRequest::getPathInfo()
	 * for every route this middleware guards.
	 *
	 * @var string
	 */
	private const OR_PATH_PREFIX = '/apps/openregister/';

	/**
	 * Hermiq's mirrored path prefix (route-for-route mirror per
	 * hermiq/openspec/changes/agent-engine-schemas/design.md Appendix C).
	 *
	 * @var string
	 */
	private const HERMIQ_PATH_PREFIX = '/apps/hermiq/';

	/**
	 * Hermiq's chat health probe — used as the cheap reachability check
	 * before redirecting the SSE streaming endpoint (a redirect can't itself
	 * detect an unreachable target the way a forwarded call can).
	 *
	 * @var string
	 */
	private const HERMIQ_HEALTH_PATH = '/apps/hermiq/api/chat/health';

	/**
	 * Outbound forward call timeout, in seconds. Kept short — this is a
	 * same-instance, same-host loopback call, not a call to a third-party
	 * service.
	 *
	 * @var int
	 */
	private const FORWARD_TIMEOUT_SECONDS = 8;

	/**
	 * Reachability probe timeout, in seconds. Shorter than the forward
	 * timeout because this is a pure liveness check, not a real request.
	 *
	 * @var int
	 */
	private const PROBE_TIMEOUT_SECONDS = 3;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the `chat.proxyTo` appconfig value.
	 * @param IAppManager $appManager Checks whether hermiq is installed/enabled before ever attempting a call.
	 * @param IURLGenerator $urlGenerator Builds absolute same-instance URLs for the forward/redirect targets.
	 * @param IClientService $clientService NC's outbound HTTP client factory.
	 * @param LoggerInterface $logger Logs fallback reasons (never throws past this class).
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the hermiq proxy is active. Defaults to active when the
	 * appconfig value is unset (or-chat-engine-decommission); an operator
	 * opts out with any explicit value other than 'hermiq' (e.g. 'off').
	 *
	 * @return bool True when `chat.proxyTo` is unset or set to 'hermiq'.
	 */
	public function isProxyConfigured(): bool {
		return $this->appConfig->getValueString(self::CONFIG_APP, self::CONFIG_KEY_PROXY_TO, self::HERMIQ_APP_ID) === self::HERMIQ_APP_ID;
	}//end isProxyConfigured()

	/**
	 * Cheap, no-network check that hermiq is installed and enabled on this
	 * instance. Always checked before any outbound call — an uninstalled
	 * hermiq should never generate an HTTP attempt at all.
	 *
	 * @return bool True when hermiq is enabled instance-wide.
	 */
	public function isHermiqInstalled(): bool {
		return $this->appManager->isInstalled(self::HERMIQ_APP_ID);
	}//end isHermiqInstalled()

	/**
	 * Rewrite an OpenRegister request path onto its hermiq mirror.
	 *
	 * @param string $orPathInfo The incoming request's `IRequest::getPathInfo()` value.
	 *
	 * @return string|null The rewritten `/apps/hermiq/...` path, or null when
	 *                     `$orPathInfo` does not start with the expected OR prefix
	 *                     (defensive — should not happen for the routes this
	 *                     middleware guards).
	 */
	public function rewritePathForHermiq(string $orPathInfo): ?string {
		if (str_starts_with($orPathInfo, self::OR_PATH_PREFIX) === false) {
			return null;
		}

		return self::HERMIQ_PATH_PREFIX . substr($orPathInfo, strlen(self::OR_PATH_PREFIX));
	}//end rewritePathForHermiq()

	/**
	 * Forward a JSON API request to hermiq and rebuild the response
	 * verbatim (status code + body + Content-Type).
	 *
	 * Returns null on any transport-level failure — the caller MUST treat
	 * that as "fall back to local serving", never as an error response to
	 * the client. A non-2xx *upstream* status is NOT a transport failure and
	 * is passed through as-is (`http_errors` disabled).
	 *
	 * @param IRequest $request The original incoming request.
	 * @param string $hermiqPath The rewritten `/apps/hermiq/...` path (see rewritePathForHermiq()).
	 *
	 * @return Response|null The rebuilt response, or null on transport failure.
	 */
	public function forwardJsonRequest(IRequest $request, string $hermiqPath): ?Response {
		$url = $this->buildAbsoluteUrl(
			hermiqPath: $hermiqPath,
			queryString: $this->extractQueryString(request: $request)
		);
		$method = strtoupper($request->getMethod());

		$options = [
			'headers' => $this->buildForwardHeaders(request: $request),
			'http_errors' => false,
			'timeout' => self::FORWARD_TIMEOUT_SECONDS,
		];

		if (in_array($method, ['GET', 'DELETE'], true) === false) {
			$options['body'] = (string)json_encode($this->extractForwardableParams(request: $request));
		}

		try {
			$client = $this->clientService->newClient();
			$upstream = $client->request($method, $url, $options);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ChatProxyHandler] hermiq forward failed — falling back to local serving',
				[
					'url' => $url,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}

		$body = $upstream->getBody();
		if (is_resource($body) === true) {
			$body = stream_get_contents($body);
		}

		// DataDisplayResponse is the plain "raw bytes, no processing" NC
		// response type. It defaults to a `Content-Disposition: inline;
		// filename=""` header (meant for file-display use cases) which we
		// strip immediately — a proxied JSON API response should carry the
		// exact same headers the local endpoint would have, not a
		// download-oriented one it never had before.
		//
		// Response's generic S template is bound to the literal union of
		// Http::STATUS_* constants; a real upstream HTTP response's status
		// code is always one of those values at runtime, but
		// IClientService's IResponse::getStatusCode() returns a plain int,
		// which can't be statically narrowed to the literal union here.
		// @phpstan-ignore-next-line Runtime status code is always valid.
		$response = new DataDisplayResponse((string)$body, $upstream->getStatusCode());
		// The addHeader() docblock documents "null will delete it" as valid
		// behaviour, but its declared parameter type is plain `string` — a
		// pre-existing NC stub inaccuracy, not a bug here.
		// @phpstan-ignore-next-line Null is documented as valid here.
		$response->addHeader('Content-Disposition', null);

		$contentType = $upstream->getHeader('Content-Type');
		if ($contentType !== '') {
			$response->addHeader('Content-Type', $contentType);
		}

		return $response;
	}//end forwardJsonRequest()

	/**
	 * Cheap reachability probe used before redirecting the SSE streaming
	 * endpoint. Unlike forwardJsonRequest(), a 308 redirect can't itself
	 * detect an unreachable target — the browser would follow it straight
	 * into a connection error — so this probe stands in for that check.
	 *
	 * @return bool True when hermiq's chat health endpoint responds without a 5xx / transport error.
	 */
	public function probeReachable(): bool {
		$url = $this->urlGenerator->getAbsoluteURL(self::HERMIQ_HEALTH_PATH);

		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$url,
				[
					'timeout' => self::PROBE_TIMEOUT_SECONDS,
					'http_errors' => false,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ChatProxyHandler] hermiq reachability probe failed — falling back to local serving',
				[
					'url' => $url,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}

		return $response->getStatusCode() < 500;
	}//end probeReachable()

	/**
	 * Build a 308 Permanent Redirect response to hermiq's mirrored path.
	 *
	 * A 308 (unlike 301/302/303) is required to preserve request method and
	 * body on redirect (RFC 7538) — the streaming endpoint is an
	 * authenticated POST, and a same-origin redirect lets the browser's own
	 * `fetch()`-based SSE reader follow it and stream directly from hermiq,
	 * without OpenRegister needing to relay the event-stream bytes itself.
	 *
	 * @param IRequest $request The original incoming request (for its query string).
	 * @param string $hermiqPath The rewritten `/apps/hermiq/...` path.
	 *
	 * @return Response The 308 redirect response.
	 */
	public function buildRedirectResponse(IRequest $request, string $hermiqPath): Response {
		$url = $this->buildAbsoluteUrl(
			hermiqPath: $hermiqPath,
			queryString: $this->extractQueryString(request: $request)
		);

		// Response's generic S template is bound to
		// OCP\AppFramework\Http::STATUS_* — which does not define a 308
		// constant at all (NC has never needed Permanent Redirect
		// internally). 308 is a valid RFC 7538 status the base Response
		// class handles correctly at runtime; this is a gap in NC's own
		// constant catalogue, not an invalid status.
		// @phpstan-ignore-next-line 308 is valid RFC 7538, just uncatalogued.
		return new Response(status: 308, headers: ['Location' => $url]);
	}//end buildRedirectResponse()

	/**
	 * Extract the `?...` query string (including the leading `?`) from the
	 * request's full URI, or '' when there is none.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return string The query string, or ''.
	 */
	private function extractQueryString(IRequest $request): string {
		$uri = $request->getRequestUri();
		$pos = strpos($uri, '?');
		if ($pos === false) {
			return '';
		}

		return substr($uri, $pos);
	}//end extractQueryString()

	/**
	 * Build the absolute same-instance URL for a hermiq path + query string.
	 *
	 * @param string $hermiqPath The rewritten `/apps/hermiq/...` path.
	 * @param string $queryString The `?...` query string (or '').
	 *
	 * @return string The absolute URL.
	 */
	private function buildAbsoluteUrl(string $hermiqPath, string $queryString): string {
		return $this->urlGenerator->getAbsoluteURL($hermiqPath) . $queryString;
	}//end buildAbsoluteUrl()

	/**
	 * Build the headers forwarded on the outbound call. Only the session
	 * cookie is carried through (same-instance auth flowthrough, hydra
	 * ADR-034 Decision 7 — "session cookie unchanged") plus a JSON
	 * Accept/Content-Type pair; nothing else from the original request is
	 * forwarded verbatim.
	 *
	 * @param IRequest $request The original incoming request.
	 *
	 * @return array<string, string> The headers to send upstream.
	 */
	private function buildForwardHeaders(IRequest $request): array {
		$headers = [
			'Accept' => 'application/json',
			'Content-Type' => 'application/json',
		];

		$cookie = $request->getHeader('Cookie');
		if ($cookie !== '') {
			$headers['Cookie'] = $cookie;
		}

		return $headers;
	}//end buildForwardHeaders()

	/**
	 * Build the JSON body forwarded on the outbound call from the original
	 * request's merged GET/POST/route parameters, stripping NC-internal
	 * keys that must never be replayed against another app (CSRF token,
	 * NC's own `_route` bookkeeping key).
	 *
	 * @param IRequest $request The original incoming request.
	 *
	 * @return array<string, mixed> The filtered parameters.
	 */
	private function extractForwardableParams(IRequest $request): array {
		$filtered = [];
		foreach ($request->getParams() as $key => $value) {
			if (is_string($key) === true && (str_starts_with($key, '_') === true || $key === 'requesttoken')) {
				continue;
			}

			$filtered[$key] = $value;
		}

		return $filtered;
	}//end extractForwardableParams()
}//end class

<?php

/**
 * ChatProxyHandlerInterface: ChatProxyHandler as the chat compatibility middleware reaches it.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Chat;

use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * ChatProxyHandler, typed for callers outside the Service component.
 *
 * Its outside caller, the chat compatibility middleware, calls buildRedirectResponse(),
 * forwardJsonRequest(), isHermiqInstalled(), isProxyConfigured(), probeReachable() and
 * rewritePathForHermiq(), so those are the whole contract. Binding it in ContractBindings
 * lets that caller resolve ChatProxyHandler without naming the class.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
interface ChatProxyHandlerInterface {
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
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function buildRedirectResponse(IRequest $request, string $hermiqPath): Response;

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
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function forwardJsonRequest(IRequest $request, string $hermiqPath): ?Response;

	/**
	 * Cheap, no-network check that hermiq is installed and enabled on this
	 * instance. Always checked before any outbound call — an uninstalled
	 * hermiq should never generate an HTTP attempt at all.
	 *
	 * @return bool True when hermiq is enabled instance-wide.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function isHermiqInstalled(): bool;

	/**
	 * Whether the hermiq proxy is active. Defaults to active when the
	 * appconfig value is unset (or-chat-engine-decommission); an operator
	 * opts out with any explicit value other than 'hermiq' (e.g. 'off').
	 *
	 * @return bool True when `chat.proxyTo` is unset or set to 'hermiq'.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function isProxyConfigured(): bool;

	/**
	 * Cheap reachability probe used before redirecting the SSE streaming
	 * endpoint. Unlike forwardJsonRequest(), a 308 redirect can't itself
	 * detect an unreachable target — the browser would follow it straight
	 * into a connection error — so this probe stands in for that check.
	 *
	 * @return bool True when hermiq's chat health endpoint responds without a 5xx / transport error.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function probeReachable(): bool;

	/**
	 * Rewrite an OpenRegister request path onto its hermiq mirror.
	 *
	 * @param string $orPathInfo The incoming request's `IRequest::getPathInfo()` value.
	 *
	 * @return string|null The rewritten `/apps/hermiq/...` path, or null when
	 *                     `$orPathInfo` does not start with the expected OR prefix
	 *                     (defensive — should not happen for the routes this
	 *                     middleware guards).
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function rewritePathForHermiq(string $orPathInfo): ?string;
}//end interface

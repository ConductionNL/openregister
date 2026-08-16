<?php

/**
 * OpenRegister Chat Proxied Response Exception
 *
 * Internal control-flow exception used by ChatCompatMiddleware to short-circuit
 * a chat/agents controller call when the request has already been served by
 * the hermiq compat proxy (`openregister.chat.proxyTo=hermiq`). Carries the
 * pre-built Response so `ChatCompatMiddleware::afterException()` can hand it
 * straight back to the AppFramework dispatcher without ever invoking the
 * local controller method's body.
 *
 * This is deliberately thrown from `beforeController()` rather than handled
 * inline: the AppFramework `Dispatcher` always calls every middleware's
 * `afterController()` after resolving a response — whether that response came
 * from the controller method itself or from `afterException()` — so routing
 * the short-circuit through this exception keeps the compat-window
 * deprecation headers (`Deprecation`/`Sunset`/`Link`) applying uniformly to
 * both the locally-served and the proxied response, from one place.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-chat-proxy-deprecation/design.md#the-short-circuit-is-an-exception-not-an-inline-return
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware\Exception;

use Exception;
use OCP\AppFramework\Http\Response;
use Throwable;

/**
 * Carries a pre-built Response through the AppFramework exception path.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Exception
 */
class ChatProxiedResponseException extends Exception {

	/**
	 * The response to hand back verbatim once caught by
	 * ChatCompatMiddleware::afterException().
	 *
	 * @var Response
	 */
	private readonly Response $response;

	/**
	 * Constructor.
	 *
	 * @param Response $response The response to hand back verbatim.
	 * @param Throwable|null $previous Optional previous throwable (unused today, kept for the standard chain).
	 *
	 * @return void
	 */
	public function __construct(Response $response, ?Throwable $previous = null) {
		parent::__construct(message: 'Chat request served by the hermiq compat proxy', code: 0, previous: $previous);
		$this->response = $response;
	}//end __construct()

	/**
	 * Get the pre-built response.
	 *
	 * @return Response The response to return to the client.
	 */
	public function getResponse(): Response {
		return $this->response;
	}//end getResponse()
}//end class

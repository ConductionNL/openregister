<?php

/**
 * InboundCall: the call the request-screening middlewares read.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The inbound call a screening middleware decides on: the request, the session
 * behind it, the annotations of the route it reaches, and the log the verdict
 * is written to.
 *
 * A constructor aggregate (ADR-011 Rule 4), autowired by the container. It holds
 * no guard: the rate limiter, the origin allowlist and the organisation scope
 * stay direct constructor parameters of the middlewares that consult them.
 *
 * @spec openspec/architecture/adr-011-parameter-objects-for-unit-interfacing.md
 */
final class InboundCall {
	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request     The incoming request.
	 * @param IUserSession               $userSession The session the request runs in.
	 * @param IControllerMethodReflector $reflector   Reads the annotations of the dispatched route.
	 * @param LoggerInterface            $logger      Where a middleware logs its verdicts.
	 */
	public function __construct(
		public readonly IRequest $request,
		public readonly IUserSession $userSession,
		public readonly IControllerMethodReflector $reflector,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class

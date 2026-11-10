<?php

/**
 * Keeps the people out of the `@self` an anonymous caller is answered with.
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
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Service\Rbac\AnonymousEnvelopeInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IUserSession;

/**
 * Filters at the response, the one point every read path passes, and after every rule has run.
 *
 * Rendered rows also serve property rules and the apps' own checks inside the
 * request, so the rendering itself keeps the owner. Registered globally because
 * other apps answer public requests with OpenRegister's records too.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class AnonymousEnvelopeMiddleware extends Middleware {

	/**
	 * Constructor.
	 *
	 * @param IUserSession      $userSession The session that says who is asking.
	 * @param AnonymousEnvelopeInterface $envelope    The filter for an anonymous caller's answer.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly AnonymousEnvelopeInterface $envelope,
	) {
	}//end __construct()

	/**
	 * Redact a JSON answer to a caller without a session; leave every other answer alone.
	 *
	 * @param Controller $controller The controller that answered.
	 * @param string     $methodName The controller method that answered.
	 * @param Response   $response   The answer.
	 *
	 * @return Response The same answer.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is the Middleware contract.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if ($this->userSession->getUser() !== null) {
			return $response;
		}

		if ($response instanceof JSONResponse === true || $response instanceof DataResponse === true) {
			$response->setData($this->envelope->redact(data: $response->getData()));
		}

		return $response;
	}//end afterController()
}//end class

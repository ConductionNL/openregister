<?php

/**
 * CaseTokenController — anonymous public resolve endpoint for the
 * "track your case" token links minted through the Shares integration
 * provider.
 *
 * Single endpoint:
 *   GET /api/public/case-tokens/{token}
 *
 * The endpoint is `#[PublicPage]` (anonymous) by design: a citizen
 * follows a link with no Nextcloud account. Security comes from two
 * layers, NOT from authentication:
 *   1. the token is a 256-bit non-guessable handle, and
 *   2. the object is rendered through the canonical OR read path with
 *      `_rbac: true` — so only fields the PUBLIC group may read are
 *      returned (the same `publicatiedatum<=$now` + public-group
 *      predicate that governs anonymous `ObjectsController::show()`).
 *
 * Any unresolved token (unknown / revoked / expired) and any RBAC-denied
 * or missing object resolve to a uniform 404 so the endpoint is not an
 * enumeration oracle (ADR-005, fail-closed).
 *
 * Additive: this is a brand-new route + controller. No existing endpoint
 * signature changes.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\CaseTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * Public case-token resolve controller.
 */
class CaseTokenController extends Controller {

	/**
	 * Brute-force throttler action for rejected case tokens.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'openregister_case_token';

	/**
	 * Constructor.
	 *
	 * @param string $appName App name (injected by NC).
	 * @param IRequest $request Current request.
	 * @param CaseTokenService $tokenService Case-token mint/resolve/revoke service.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private CaseTokenService $tokenService,
		private IThrottler $throttler,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/public/case-tokens/{token}
	 *
	 * Resolves a public case token to an RBAC-scoped, public-safe view of
	 * the referenced object. Fails closed (404) on any unresolved token.
	 *
	 * @param string $token The opaque public token.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @return JSONResponse The public-safe object view, or 404.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function resolve(string $token): JSONResponse {
		$resolved = $this->tokenService->resolve($token);
		if ($resolved === null) {
			// The uniform 404 below is the right answer and stays. But a
			// uniform response is not a throttle: it hides WHICH failure
			// occurred, it does nothing about how FAST they can be attempted.
			try {
				$this->throttler->registerAttempt(
					action: self::THROTTLE_ACTION,
					ip: $this->request->getRemoteAddress()
				);
			} catch (\Throwable $throttlerFailure) {
				$this->logger->warning(
					'CaseTokenController: registerAttempt failed: ' . $throttlerFailure->getMessage()
				);
			}

			// Uniform 404 — never distinguish "unknown" from
			// "revoked / expired / forbidden". No enumeration oracle.
			return new JSONResponse(
				['message' => 'Not Found'],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($resolved);
	}//end resolve()
}//end class

<?php

/**
 * The standard discovery paths, `security.txt` first.
 *
 * WHY `security.txt` FIRST. The NCSC responsible-disclosure expectation is a
 * BIO question with a one-file answer: a researcher who finds something in a
 * gemeente's register needs somewhere to send it, and if there is nowhere they
 * either publish it or drop it. Neither is the outcome anybody wants, and the
 * whole control is one text file at a path everybody already knows to try.
 *
 * 🔴 SERVED UNDER THE APP'S OWN PREFIX, NOT AT THE SERVER ROOT. An app cannot
 * claim `/.well-known/security.txt` for the whole Nextcloud instance, and it
 * should not: the instance may host other apps, and the contact for the
 * platform is the administrator's to publish, not this app's to seize. What
 * this ships is the content and the route; pointing the server root at it is
 * one rewrite rule, which the response itself names so nobody has to go
 * looking for the instruction.
 *
 * 🔑 EMPTY UNTIL ADMINISTERED, AND HONEST ABOUT IT. An unconfigured instance
 * answers 404 rather than a file with a placeholder address in it. A
 * `security.txt` naming security@example.com is worse than none: it reads as a
 * working disclosure channel and swallows the report.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Serves the well-known discovery paths with administered content.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass Registered through appinfo/routes.php.
 */
class WellKnownController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param SecurityTxtBuilder $securityTxt Builds the administered security contact file.
	 * @param IURLGenerator $urlGenerator Names this instance's own base URL.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SecurityTxtBuilder $securityTxt,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The responsible-disclosure contact, as RFC 9116 `security.txt`.
	 *
	 * @return DataDisplayResponse|JSONResponse The file, or a 404 when nothing is administered.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 *
	 * @contract tests/Unit/Controller/WellKnownControllerTest.php
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function securityTxt(): DataDisplayResponse|JSONResponse {
		$body = $this->securityTxt->build();
		if ($body === null) {
			return new JSONResponse(
				data: [
					'error' => 'No responsible disclosure contact is administered on this instance.',
				],
				statusCode: Http::STATUS_NOT_FOUND,
			);
		}

		$response = new DataDisplayResponse(
			data: $body,
			statusCode: Http::STATUS_OK,
			headers: ['Content-Type' => 'text/plain; charset=utf-8'],
		);
		$response->cacheFor(3600);

		return $response;

	}//end securityTxt()

	/**
	 * What this instance publishes at its well-known paths.
	 *
	 * The index exists because a researcher or an integrator reaching an app's
	 * own prefix should not have to guess which of the standard paths it
	 * answers. It also names the rewrite an administrator needs to make the
	 * server root serve these, so the instruction is where somebody looking at
	 * the problem will be.
	 *
	 * @return JSONResponse The index.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 *
	 * @contract tests/Unit/Controller/WellKnownControllerTest.php
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$base = rtrim($this->urlGenerator->getAbsoluteURL('/apps/openregister/.well-known'), '/');

		return new JSONResponse(
			data: [
				'paths' => [
					'security.txt' => ($base . '/security.txt'),
				],
				'servedAt' => $base,
				'note' => 'Point the server root at these to answer the standard paths: '
					. 'a rewrite from /.well-known/security.txt to '
					. $base . '/security.txt.',
			]
		);

	}//end index()
}//end class

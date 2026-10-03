<?php

/**
 * The page a person with an access link lands on.
 *
 * The link used to point at `/api/public/links/{anchor}`, which answers JSON: a
 * recipient without an account opened it and saw raw data. This controller
 * serves a public page for the same anchor instead; the page reads the same
 * holder endpoints (open, comment, upload), so every access decision, the
 * password check, the throttling and the audit of every use stay in
 * {@see AccessLinkController}. The page itself reveals nothing: it renders for
 * any anchor, and an unknown, revoked or expired anchor shows the same "this
 * link does not open anything" the API's uniform 404 leads to.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Serves the holder's page for one access link.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
class AccessLinkPageController extends Controller {

	/**
	 * The template the page renders.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'accessLink';

	/**
	 * Constructor.
	 *
	 * @param string        $appName      The app name.
	 * @param IRequest      $request      The request.
	 * @param IInitialState $initialState Hands the anchor to the page script.
	 * @param IL10N         $l10n         Translates the page title.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IInitialState $initialState,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /links/{anchor}
	 *
	 * @param string $anchor The random anchor from the URL.
	 *
	 * @return PublicTemplateResponse The page.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $anchor): PublicTemplateResponse {
		$this->initialState->provideInitialState('accessLinkAnchor', $anchor);

		$response = new PublicTemplateResponse($this->appName, self::TEMPLATE, []);
		$response->setHeaderTitle($this->l10n->t('Shared with you'));
		$response->setFooterVisible(false);

		return $response;
	}//end show()
}//end class

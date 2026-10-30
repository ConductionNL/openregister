<?php

/**
 * Resolves a selectielijst URL to its code and name for a page.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Archival\SelectielijstReferenceResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * GET /api/archival/selectielijst/resolve?url=
 *
 * The published selectielijst is public reference data, so any signed-in
 * user may resolve a URL; the resolver only fetches allowed selectielijst hosts.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
class SelectielijstReferenceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                         $appName  The app name.
	 * @param IRequest                       $request  The request.
	 * @param SelectielijstReferenceResolver $resolver Reads the row behind a URL.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SelectielijstReferenceResolver $resolver,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The code and name behind a selectielijst URL.
	 *
	 * @return JSONResponse `{url, code, name}`; 400 for a URL that is not a selectielijst row, 404 when it cannot be read.
	 *
	 * @no-admin-idor-exempt Takes no object id: it reads public reference data behind a URL.
	 *   SelectielijstReferenceResolver::isResolvable() admits only the allowed selectielijst
	 *   hosts, and the answer holds nothing the caller could not read there themselves.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	#[NoAdminRequired]
	public function resolve(): JSONResponse {
		$url = trim((string) $this->request->getParam('url', ''));
		if ($this->resolver->isResolvable(url: $url) === false) {
			return new JSONResponse(data: ['error' => 'Not a selectielijst URL this server resolves'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$resolved = $this->resolver->resolve(url: $url);
		if ($resolved === null) {
			return new JSONResponse(data: ['error' => 'The selectielijst row could not be read'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $resolved, statusCode: Http::STATUS_OK);
	}//end resolve()
}//end class

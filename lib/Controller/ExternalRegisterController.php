<?php

/**
 * The external-register leaf's read: one record of a sourced schema by key,
 * with the reason when there is none.
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
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Integration\ExternalRegisterLookupInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * GET /api/external-register/{register}/{schema}/{key}[?refresh=1]
 *
 * Answers `{state, adminActionable, record, fetchedAt, source, cacheSeconds}`.
 * Every state is a 200: a register that is down or not configured is an
 * explained empty state on the page, never an error. Only a schema the
 * caller may not read, or one that does not exist, is a 404, and the two are
 * the same 404.
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */
class ExternalRegisterController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                        $request The request.
	 * @param ExternalRegisterLookupInterface $lookup  Reads the record and names its state.
	 */
	public function __construct(
		IRequest $request,
		private readonly ExternalRegisterLookupInterface $lookup,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The record of a sourced schema by key.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The sourced schema id or slug.
	 * @param string $key      The key.
	 *
	 * @return JSONResponse The answer; 404 when the schema cannot be read.
	 *
	 * @no-admin-idor-exempt Reads no stored object by id: ExternalRegisterLookup asks the caller's
	 *   read right on the sourced schema first and answers the same 404 for a refusal as for a
	 *   schema that does not exist.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md#requirement-an-external-register-record-renders-on-another-object
	 */
	#[NoAdminRequired]
	public function show(string $register, string $schema, string $key): JSONResponse {
		$refresh = in_array((string)$this->request->getParam('refresh', ''), ['1', 'true'], true);
		$answer  = $this->lookup->read(register: $register, schema: $schema, key: $key, refresh: $refresh);
		if ($answer === null) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$response = new JSONResponse(data: $answer, statusCode: Http::STATUS_OK);
		$response->addHeader('Cache-Control', 'private, max-age=' . (int)$answer['cacheSeconds']);

		return $response;
	}//end show()
}//end class

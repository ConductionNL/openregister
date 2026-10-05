<?php

/**
 * Admin routes for per-organisation halts.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin only (no NoAdminRequired). An app that lets an organisation's owner
 * halt its own work calls OrganisationHaltService with its own guard.
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */
class OrganisationHaltController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName The app name.
	 * @param IRequest                $request The request.
	 * @param OrganisationHaltService $halts   The halts.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OrganisationHaltService $halts,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The engaged halts of one organisation.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return JSONResponse The halts.
	 *
	 * @NoCSRFRequired
	 * @auth admin-only halts name incidents and the people who engaged them, which only an administrator reads here
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function index(string $uuid): JSONResponse {
		return new JSONResponse(data: ['results' => $this->halts->list(organisation: $uuid)]);
	}//end index()

	/**
	 * Engage a halt for one organisation, as the signed-in administrator.
	 *
	 * @param string      $uuid           The organisation uuid.
	 * @param string      $app            The app whose work stops.
	 * @param string      $reason         Why.
	 * @param string|null $nodeTypePrefix The node types it stops; empty means "<app>.".
	 *
	 * @return JSONResponse 201 with the halt; 400 with the reason it was refused.
	 *
	 * @auth admin-only engaging a halt stops an organisation's work, so only an administrator does it here, with the CSRF check on
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function create(string $uuid, string $app='', string $reason='', ?string $nodeTypePrefix=null): JSONResponse {
		try {
			$halt = $this->halts->engage(organisation: $uuid, app: $app, reason: $reason, nodeTypePrefix: $nodeTypePrefix);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $halt, statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Release a halt, as the signed-in administrator.
	 *
	 * @param string $id The halt id.
	 *
	 * @return JSONResponse 200, or 404 when no such halt is engaged.
	 *
	 * @auth admin-only releasing a halt lets halted work run again, so only an administrator does it here, with the CSRF check on
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function destroy(string $id): JSONResponse {
		if ($this->halts->release(id: $id) === false) {
			return new JSONResponse(data: ['error' => 'No engaged halt ' . $id], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: ['released' => $id]);
	}//end destroy()
}//end class

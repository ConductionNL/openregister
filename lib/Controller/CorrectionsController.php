<?php

/**
 * Class CorrectionsController
 *
 * Correcting a recorded value, as its own act rather than as an update.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\CorrectionRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\CorrectionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Class CorrectionsController
 *
 * One verb, deliberately not folded into ObjectsController. A correction is a
 * different act from an update, and putting it beside `patch` is the first
 * step towards it becoming one.
 *
 * @psalm-suppress UnusedClass
 */
class CorrectionsController extends Controller {

	/**
	 * Constructor for the CorrectionsController.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param CorrectionService $corrections The correction act.
	 * @param IUserSession $userSession The user session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CorrectionService $corrections,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Correct one or more values on a record.
	 *
	 * Body: `reason`, the sentence saying why the recorded value is wrong, and
	 * `values`, the properties to correct mapped to their corrected values.
	 * Both are required, and the refusal for each says which one is missing.
	 *
	 * The corrected entry is readable straight back off the object's audit
	 * trail filtered to `action=correction`, which is how an auditor separates
	 * corrections from updates without reading every diff.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The id or uuid of the object to correct.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The corrected record and the entry that recorded it.
	 *
	 * @contract tests/Unit/Controller/CorrectionsControllerTest.php
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function correct(string $register, string $schema, string $id): JSONResponse {
		// `#[NoAdminRequired]` means "not only admins", never "no account".
		// The right itself is checked in the service, so the two doors to a
		// correction cannot disagree about who may open one.
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: 401);
		}

		$values = $this->request->getParam('values');
		if (is_array($values) === false) {
			return new JSONResponse(
				data: ['error' => "Send the corrected values under 'values'."],
				statusCode: 400
			);
		}

		try {
			$result = $this->corrections->correct(
				register: $register,
				schema: $schema,
				id: $id,
				values: $values,
				reason: $this->request->getParam('reason')
			);

			return new JSONResponse(
				data: [
					'object' => $result['object']->jsonSerialize(),
					'audit' => $result['audit']?->jsonSerialize(),
				]
			);
		} catch (CorrectionRefusedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 403);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (\Throwable $e) {
			// SEC-CTRL-7: never leak internal exception detail on a 500.
			return new JSONResponse(data: ['error' => 'Internal server error'], statusCode: 500);
		}//end try
	}//end correct()
}//end class

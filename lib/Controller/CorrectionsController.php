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
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\CorrectionsControllerServices;
use OCA\OpenRegister\Exception\CorrectionRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\CorrectionServiceInterface;
use OCA\OpenRegister\Service\Object\CorrectionTarget;
use OCA\OpenRegister\Service\Object\CorrectionTargetsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

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
	 * The correction act.
	 *
	 * @var CorrectionServiceInterface
	 */
	private readonly CorrectionServiceInterface $corrections;

	/**
	 * Names the record a correction is made on.
	 *
	 * @var CorrectionTargetsInterface
	 */
	private readonly CorrectionTargetsInterface $targets;

	/**
	 * The user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param CorrectionsControllerServices $services The corrections controller services.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		IRequest $request,
		CorrectionsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->corrections = $services->corrections;
		$this->targets = $services->targets;
		$this->userSession = $services->userSession;
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
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function correct(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->correctionOf(target: $this->targets->forRecord(ref: $ref));
	}//end correct()

	/**
	 * Correct the named record with the body's values, after the caller and body checks.
	 *
	 * @param CorrectionTarget $target The record the route names.
	 *
	 * @return JSONResponse The corrected record and its entry, or the refusal.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function correctionOf(CorrectionTarget $target): JSONResponse {
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

		return $this->applyCorrection(target: $target, values: $values);
	}//end correctionOf()

	/**
	 * Apply a correction and answer with the record and its audit entry.
	 *
	 * @param CorrectionTarget     $target The record the route names.
	 * @param array<string, mixed> $values The corrected values.
	 *
	 * @return JSONResponse The corrected record and its entry, or the refusal.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function applyCorrection(CorrectionTarget $target, array $values): JSONResponse {
		try {
			$result = $this->corrections->correct(target: $target, values: $values, reason: $this->request->getParam('reason'));

			return new JSONResponse(
				data: [
					'object' => $result['object']->jsonSerialize(),
					'audit' => $result['audit']?->jsonSerialize(),
				]
			);
		} catch (Throwable $e) {
			return $this->correctionFailure(error: $e);
		}
	}//end applyCorrection()

	/**
	 * The answer to a failed correction: the refusal under its status, anything else an opaque 500.
	 *
	 * @param Throwable $error The failure.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function correctionFailure(Throwable $error): JSONResponse {
		if ($error instanceof CorrectionRefusedException) {
			return new JSONResponse(data: ['error' => $error->getMessage()], statusCode: 400);
		}

		if ($error instanceof NotAuthorizedException) {
			return new JSONResponse(data: ['error' => $error->getMessage()], statusCode: 403);
		}

		if ($error instanceof DoesNotExistException) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		}

		// SEC-CTRL-7: never leak internal exception detail on a 500.
		return new JSONResponse(data: ['error' => 'Internal server error'], statusCode: 500);
	}//end correctionFailure()
}//end class

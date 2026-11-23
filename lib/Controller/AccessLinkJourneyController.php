<?php

/**
 * Access links: change the linked record through its journey, without an account.
 *
 * The holder of a link that declares `edit` reads the journey prefilled with
 * the record's current values for the fields the journey declares, and submits
 * changes to those fields. The answers follow AccessLinkController: one 404
 * for an unknown, revoked, switched-off or expired link and for a link whose
 * journey no longer fits its record, 401 for a missing or wrong password, and
 * 403 for a live link that does not declare `edit`.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\LinkJourneyGate;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\Form\LinkUpdateRun;
use OCA\OpenRegister\Service\Sharing\AccessLinkServiceInterface;
use OCA\OpenRegister\Service\Sharing\LinkAct;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET and POST /api/public/links/{anchor}/journey.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */
class AccessLinkJourneyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request Current request.
	 * @param LinkJourneyGate $gate    Admits an `edit` link and records each use.
	 * @param LinkUpdateRun   $runs    Starts and submits the update run.
	 * @param LoggerInterface $logger  Logs a failed save.
	 */
	public function __construct(
		IRequest $request,
		private readonly LinkJourneyGate $gate,
		private readonly LinkUpdateRun $runs,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/public/links/{anchor}/journey
	 *
	 * The journey an `edit` link opens, prefilled with its record's declared fields.
	 *
	 * @param string $anchor The random anchor from the URL.
	 *
	 * @return JSONResponse 200 with `{link, run}`, 401, 403 or 404.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-3
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: AccessLinkServiceInterface::THROTTLE_ACTION)]
	public function show(string $anchor): JSONResponse {
		$act = $this->gate->admit(anchor: $anchor, request: $this->request);
		if ($act instanceof LinkAct === false) {
			return $act;
		}

		try {
			$run = $this->runs->start(link: $act->link, object: $act->object);
		} catch (FormSubmitRefusedException $refused) {
			return $this->refused(refused: $refused);
		}

		$this->gate->record(
			act: $act,
			action: AccessLinkServiceInterface::ACT_READ,
			context: ['journey' => $run['journey'], 'ipAddress' => $this->request->getRemoteAddress()]
		);

		return new JSONResponse(['link' => $act->link->publicDescriptor(), 'run' => $run]);
	}//end show()

	/**
	 * POST /api/public/links/{anchor}/journey
	 *
	 * Change the record's declared fields with `{answers: {field: value}}`. The
	 * use is recorded on the record's audit trail with the link as the actor
	 * and the changed properties named.
	 *
	 * @param string $anchor The random anchor from the URL.
	 *
	 * @return JSONResponse 200 with `{journey, object, changed, answers}`, 400, 401, 403, 404 or 422 with findings.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-3
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: AccessLinkServiceInterface::THROTTLE_ACTION)]
	public function submit(string $anchor): JSONResponse {
		$act = $this->gate->admit(anchor: $anchor, request: $this->request);
		if ($act instanceof LinkAct === false) {
			return $act;
		}

		$answers = $this->request->getParam('answers', []);
		if (is_array($answers) === false) {
			return new JSONResponse(['message' => 'The answers must be an object of field values.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$outcome = $this->runs->submit(link: $act->link, object: $act->object, answers: $answers);
		} catch (FormSubmitRefusedException $refused) {
			return $this->refused(refused: $refused);
		} catch (Throwable $failure) {
			$this->logger->warning('[AccessLinkJourneyController] A link change failed: ' . $failure->getMessage());

			return new JSONResponse(['message' => 'The change could not be saved.'], Http::STATUS_BAD_REQUEST);
		}

		$this->gate->record(
			act: new LinkAct(link: $act->link, object: $outcome['saved']),
			action: AccessLinkServiceInterface::ACT_EDIT,
			context: ['journey' => $outcome['journey'], 'changed' => $outcome['changed'], 'ipAddress' => $this->request->getRemoteAddress()]
		);
		unset($outcome['saved']);

		return new JSONResponse($outcome);
	}//end submit()

	/**
	 * A refusal of the run: the one counted 404 for a journey that does not fit, else its status, message and findings.
	 *
	 * @param FormSubmitRefusedException $refused The refusal.
	 *
	 * @return JSONResponse The answer.
	 */
	private function refused(FormSubmitRefusedException $refused): JSONResponse {
		if ($refused->getStatus() === Http::STATUS_NOT_FOUND) {
			return $this->gate->rejected(request: $this->request);
		}

		return new JSONResponse(['message' => $refused->getMessage(), 'findings' => $refused->getFindings()], $refused->getStatus());
	}//end refused()
}//end class

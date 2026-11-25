<?php

/**
 * The public door a survey invitation link opens: read the form, submit the answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\ObjectAccessLinksInterface;
use OCA\OpenRegister\Service\Survey\SurveyAnswering;
use OCA\OpenRegister\Service\Survey\SurveyAnswerRefusedException;
use OCA\OpenRegister\Service\Survey\SurveyAnswerVisit;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Answering without an account, over the invitation's access link.
 *
 * A link that opens nothing answers 404 and counts against the caller's
 * brute-force budget, exactly as the access link door does: anchors are not to
 * be guessed. A live link to an invitation that can no longer be answered
 * answers 410 with the reason (expired, already answered, never sent), because
 * the respondent who follows it is owed an explanation and the link proved they
 * hold it.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */
class SurveyAnswerController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request   The request.
	 * @param SurveyAnswering $answering The respondent's side of an invitation.
	 */
	public function __construct(
		IRequest $request,
		private readonly SurveyAnswering $answering,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The survey and its questions, for the holder of the invitation link.
	 *
	 * @param string $anchor The link's anchor.
	 *
	 * @return JSONResponse 200 with the form, 404 for a link that opens no invitation, 410 with the reason.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: ObjectAccessLinksInterface::THROTTLE_ACTION)]
	public function show(string $anchor): JSONResponse {
		$visit = $this->answering->open(anchor: $anchor);
		if ($visit instanceof SurveyAnswerVisit === false) {
			return self::notFound();
		}

		try {
			$form = $this->answering->form(visit: $visit);
		} catch (SurveyAnswerRefusedException $refused) {
			return self::refusal(refused: $refused);
		}

		$this->answering->recordUse(
			visit: $visit,
			act: ObjectAccessLinksInterface::ACT_READ,
			ipAddress: $this->request->getRemoteAddress()
		);

		return new JSONResponse(data: $form);
	}//end show()

	/**
	 * Accept the answers of the holder of the invitation link.
	 *
	 * @param string $anchor The link's anchor.
	 *
	 * @return JSONResponse 201 with the answer set id, 400 naming a missing answer, 404, or 410 with the reason.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-003-the-answers-are-an-object-linked-to-the-subject
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[BruteForceProtection(action: ObjectAccessLinksInterface::THROTTLE_ACTION)]
	public function submit(string $anchor): JSONResponse {
		$visit = $this->answering->open(anchor: $anchor);
		if ($visit instanceof SurveyAnswerVisit === false) {
			return self::notFound();
		}

		$answers = $this->request->getParam('answers', []);
		if (is_array($answers) === false) {
			return new JSONResponse(data: ['error' => 'The answers must be a list.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$answerSet = $this->answering->answer(visit: $visit, answers: $answers);
		} catch (SurveyAnswerRefusedException $refused) {
			return self::refusal(refused: $refused);
		}

		$this->answering->recordUse(
			visit: $visit,
			act: SurveyAnswering::ACT_ANSWERED,
			ipAddress: $this->request->getRemoteAddress()
		);

		return new JSONResponse(data: ['answerSet' => $answerSet['id']], statusCode: Http::STATUS_CREATED);
	}//end submit()

	/**
	 * The 404 for a link that opens no invitation, counted against the caller.
	 *
	 * @return JSONResponse The response.
	 */
	private static function notFound(): JSONResponse {
		$response = new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		$response->throttle(['action' => ObjectAccessLinksInterface::THROTTLE_ACTION]);

		return $response;
	}//end notFound()

	/**
	 * The refusal, with its status and reason.
	 *
	 * @param SurveyAnswerRefusedException $refused The refusal.
	 *
	 * @return JSONResponse The response.
	 */
	private static function refusal(SurveyAnswerRefusedException $refused): JSONResponse {
		return new JSONResponse(data: ['error' => $refused->getMessage()], statusCode: $refused->status);
	}//end refusal()
}//end class

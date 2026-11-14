<?php

/**
 * JourneyRunController: start, answer and save a journey run over HTTP.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormSubmitter;
use OCA\OpenRegister\Service\Form\JourneyRunService;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfacesInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The journey run API under `/api/journeys/{journeyId}/runs`.
 *
 * Start, answer, save and resume are public, for journeys declaring
 * `access: anonymous`, rate-limited per ADR-082 and brute-force protected: an
 * unknown journey or run registers an attempt. An anonymous run is submitted
 * with no subject and no asserted owner, so its writes stamp no ownership. A
 * run saved part-way is a draft: a signed-in filer's own, or an anonymous
 * one owned by the hash of its resume token.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-anonymous-submission-must-be-throttled-and-must-stamp-no-ownership
 */
class JourneyRunController extends Controller {

	/**
	 * Wire the controller.
	 *
	 * @param string            $appName     The app id.
	 * @param IRequest          $request     The request.
	 * @param JourneyRunService $runs        Drives the run.
	 * @param IUserSession      $userSession The signed-in subject, when there is one.
	 * @param IThrottler        $throttler   Counts attempts on unknown journeys.
	 * @param LoggerInterface   $logger      Logs a throttler failure.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly JourneyRunService $runs,
		private readonly IUserSession $userSession,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Start a run: the first step and the fields it asks for.
	 *
	 * @param string $journeyId The journey object's uuid.
	 *
	 * @return JSONResponse 200 `{ journey, step, fields }`; 401, 403 or 404 with `{ message, findings }`.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-anonymous-submission-must-be-throttled-and-must-stamp-no-ownership
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: ThrottledSurfacesInterface::JOURNEY_RUN)]
	public function start(string $journeyId): JSONResponse {
		return $this->answered(
			call: fn (): array => $this->runs->start(journeyId: $journeyId, submitter: $this->submitter()),
			status: 200
		);
	}//end start()

	/**
	 * Answer a step: `{ step, answers, _draft? }`; a writing step commits its writes, all or none.
	 *
	 * The `Idempotency-Key` header is passed to the commit.
	 *
	 * @param string $journeyId The journey object's uuid.
	 *
	 * @return JSONResponse 200 `{ journey, step, next, fields, committed }`; 401, 403, 404, 409, 422 or 503 with `{ message, findings }`.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: ThrottledSurfacesInterface::JOURNEY_RUN)]
	public function answer(string $journeyId): JSONResponse {
		$key = trim($this->request->getHeader('Idempotency-Key'));
		if ($key === '') {
			$key = null;
		}

		return $this->answered(
			call: fn (): array => $this->runs->answer(
				journeyId: $journeyId,
				stepId: $this->stepParam(),
				answers: $this->answersParam(),
				submitter: $this->submitter(),
				idempotencyKey: $key
			),
			status: 200
		);
	}//end answer()

	/**
	 * Save a run part-way: `{ step, answers, _draft? }`, kept as a draft.
	 *
	 * A signed-in filer's draft is theirs. An anonymous run's first save
	 * answers a single-purpose resume token once; a later save and a resume
	 * present it in the `X-Journey-Run-Token` header. Public, rate-limited and
	 * brute-force protected like start and answer.
	 *
	 * @param string $journeyId The journey object's uuid.
	 *
	 * @return JSONResponse 200 `{ id, status, token? }`; 401, 403, 404, 422 or 503 with `{ message, findings }`.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 120, period: 60)]
	#[BruteForceProtection(action: ThrottledSurfacesInterface::JOURNEY_RUN)]
	public function save(string $journeyId): JSONResponse {
		return $this->answered(
			call: fn (): array => $this->runs->save(
				journeyId: $journeyId,
				stepId: $this->stepParam(),
				answers: $this->answersParam(),
				submitter: $this->submitter(),
				token: $this->tokenHeader()
			),
			status: 200
		);
	}//end save()

	/**
	 * Resume a saved run: `?run=<id>` optional, the anonymous run's token in `X-Journey-Run-Token`.
	 *
	 * A token for another run, an unknown run, a missing token and an unknown
	 * journey answer the same 404 and each registers an attempt.
	 *
	 * @param string $journeyId The journey object's uuid.
	 *
	 * @return JSONResponse 200 `{ journey, run, step, fields, answers, updatedAt }`; 401, 403 or 404 with `{ message, findings }`.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-run-must-be-resumable-without-becoming-an-oracle
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[UserRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: ThrottledSurfacesInterface::JOURNEY_RUN)]
	public function resume(string $journeyId): JSONResponse {
		$runId = $this->request->getParam('run', '');
		if (is_string($runId) === false || trim($runId) === '') {
			$runId = null;
		}

		return $this->answered(
			call: fn (): array => $this->runs->resume(
				journeyId: $journeyId,
				runId: $runId,
				token: $this->tokenHeader(),
				submitter: new FormSubmitter(subject: $this->userSession->getUser())
			),
			status: 200
		);
	}//end resume()

	/**
	 * The anonymous run's resume token from the `X-Journey-Run-Token` header.
	 *
	 * A header, not a query parameter, so the token stays out of access logs.
	 *
	 * @return string|null The token; null when absent.
	 */
	private function tokenHeader(): ?string {
		$token = trim($this->request->getHeader('X-Journey-Run-Token'));
		if ($token === '') {
			return null;
		}

		return $token;
	}//end tokenHeader()

	/**
	 * Run a call; a refusal becomes its body and status, an unknown journey also an attempt.
	 *
	 * @param callable $call   The service call.
	 * @param int      $status The success status.
	 *
	 * @return JSONResponse The answer.
	 */
	private function answered(callable $call, int $status): JSONResponse {
		try {
			return new JSONResponse(data: $call(), statusCode: $status);
		} catch (FormSubmitRefusedException $refused) {
			if ($refused->getStatus() === 404) {
				$this->registerAttempt();
			}

			return new JSONResponse(data: $refused->toBody(), statusCode: $refused->getStatus());
		}
	}//end answered()

	/**
	 * Count an attempt on the journey-run surface; a throttler failure is logged, not raised.
	 *
	 * @return void
	 */
	private function registerAttempt(): void {
		try {
			$this->throttler->registerAttempt(ThrottledSurfacesInterface::JOURNEY_RUN, $this->request->getRemoteAddress());
		} catch (Throwable $throttlerFailure) {
			$this->logger->warning(
				message: '[JourneyRunController] registerAttempt failed',
				context: ['exception' => $throttlerFailure->getMessage()]
			);
		}
	}//end registerAttempt()

	/**
	 * The submitter: the session user or nobody, never an asserted owner.
	 *
	 * @return FormSubmitter The submitter.
	 */
	private function submitter(): FormSubmitter {
		$draftId = trim((string)$this->request->getParam('_draft', ''));
		if ($draftId === '') {
			$draftId = null;
		}

		return new FormSubmitter(subject: $this->userSession->getUser(), draftId: $draftId);
	}//end submitter()

	/**
	 * The step answered.
	 *
	 * @return string The step id; '' when absent.
	 */
	private function stepParam(): string {
		$step = $this->request->getParam('step', '');
		if (is_string($step) === false) {
			return '';
		}

		return $step;
	}//end stepParam()

	/**
	 * The answers, keyed by field name.
	 *
	 * @return array<string, mixed> The answers; empty when absent or not an object.
	 */
	private function answersParam(): array {
		$answers = $this->request->getParam('answers', []);
		if (is_array($answers) === false) {
			return [];
		}

		return $answers;
	}//end answersParam()
}//end class

<?php

/**
 * OpenRegister Form Submit Refusals
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Exception\DraftAlreadyLeftException;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The refusals a submit answers with: 422 payload, 403 not allowed, 404 draft gone, 503 unreachable.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormSubmitRefusals {

	/**
	 * Constructor.
	 *
	 * @param FormPayloadFindings $findings Turns a refusal into per-property findings.
	 * @param IL10N               $l10n     Translates each refusal.
	 * @param LoggerInterface     $logger   Records an unreachable destination.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function __construct(
		private FormPayloadFindings $findings,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The refusal a failed write answers with.
	 *
	 * @param Throwable            $exception The save's exception.
	 * @param array<string, mixed> $plan      The write that failed.
	 *
	 * @return FormSubmitRefusedException 422 for the payload, 403 for permission, 503 otherwise.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function refusalFor(Throwable $exception, array $plan): FormSubmitRefusedException {
		if ($exception instanceof FormSubmitRefusedException) {
			return $exception;
		}

		if ($exception instanceof DraftAlreadyLeftException) {
			return $this->draftGone();
		}

		if ($this->findings->isPayloadRefusal(exception: $exception) === true) {
			return $this->payloadRefused(findings: $this->findings->fromThrowable(exception: $exception, write: $this->writeLabelOf(plan: $plan)));
		}

		if ($exception instanceof NotAuthorizedException) {
			return $this->notAllowed();
		}

		return $this->unreachable(exception: $exception, plan: $plan);
	}//end refusalFor()

	/**
	 * The 422 refusal of a payload, with its findings.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return FormSubmitRefusedException 422.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function payloadRefused(array $findings): FormSubmitRefusedException {
		return new FormSubmitRefusedException(
			message: $this->l10n->t('The form was not accepted. Please check the answers marked below.'),
			status: 422,
			findings: $findings
		);
	}//end payloadRefused()

	/**
	 * The 403 refusal of a subject that may not write the destination.
	 *
	 * @return FormSubmitRefusedException 403.
	 */
	private function notAllowed(): FormSubmitRefusedException {
		return new FormSubmitRefusedException(
			message: $this->l10n->t('You may not send this form.'),
			status: 403,
			findings: [['property' => '', 'code' => 'not-allowed', 'message' => $this->l10n->t('You may not send this form.')]]
		);
	}//end notAllowed()

	/**
	 * The 503 refusal of a destination that could not be reached, logged.
	 *
	 * Q3: refuse with "try again later"; the answers stay in the form. A queue
	 * would hand the resident a receipt for something that may never arrive,
	 * which is the defect decision 179 removes.
	 *
	 * @param Throwable            $exception The save's exception.
	 * @param array<string, mixed> $plan      The write that failed.
	 *
	 * @return FormSubmitRefusedException 503.
	 */
	private function unreachable(Throwable $exception, array $plan): FormSubmitRefusedException {
		$this->logger->error(
			message: '[FormSubmitService] A submit could not reach its destination',
			context: ['schema' => $plan['schema']->getSlug(), 'exception' => $exception->getMessage()]
		);

		return new FormSubmitRefusedException(
			message: $this->l10n->t('Your form could not be sent right now. Your answers are kept; please try again later.'),
			status: 503
		);
	}//end unreachable()

	/**
	 * The refusal for a draft that does not exist, is not the owner's, or already left draft.
	 *
	 * @return FormSubmitRefusedException 404.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function draftGone(): FormSubmitRefusedException {
		return new FormSubmitRefusedException(message: $this->l10n->t('This draft does not exist.'), status: 404);
	}//end draftGone()

	/**
	 * The name a finding of this write carries: its `as` when the submit names its writes.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return string|null The write's name, or null.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function writeLabelOf(array $plan): ?string {
		if ($plan['named'] === true) {
			return $plan['as'];
		}

		return null;
	}//end writeLabelOf()
}//end class

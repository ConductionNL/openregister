<?php

/**
 * A form submit, upload or validation that was refused, with its findings.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use InvalidArgumentException;

/**
 * Carries the HTTP status the caller answers with, and the findings in the validator's shape.
 *
 * 422: the payload or the form was refused, nothing was created.
 * 403: the subject may not create the destination.
 * 404: the form or its destination does not exist.
 * 503: the destination could not be reached; the resident tries again later (Q3).
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
class FormSubmitRefusedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string                           $message  The sentence the caller shows.
	 * @param int                              $status   The HTTP status, 4xx or 5xx.
	 * @param array<int, array<string, mixed>> $findings The findings, `{ field?, property, code, message }`.
	 *
	 * @throws InvalidArgumentException When the status would read as success.
	 */
	public function __construct(
		string $message,
		private readonly int $status = 422,
		private readonly array $findings = [],
	) {
		if ($status < 400 || $status > 599) {
			throw new InvalidArgumentException('A refusal answers with a 4xx or 5xx status, not ' . $status . '.');
		}

		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status.
	 *
	 * @return int The status.
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * The findings.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 */
	public function getFindings(): array {
		return $this->findings;
	}//end getFindings()

	/**
	 * The response body: `{ message, findings }`.
	 *
	 * @return array{message: string, findings: array<int, array<string, mixed>>} The body.
	 */
	public function toBody(): array {
		return [
			'message' => $this->getMessage(),
			'findings' => $this->findings,
		];
	}//end toBody()
}//end class

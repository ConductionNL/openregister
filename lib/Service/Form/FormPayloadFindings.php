<?php

/**
 * Turns a refused payload into findings in the validator's shape, one per property.
 *
 * A resident needs to see, per field, why the destination refused what they
 * filled in (ADR-117 decision 4). The save path speaks in four dialects: an
 * opis error tree, a message-only ValidationException, a CustomValidationException
 * map and a HookStoppedException list. This class speaks them all and answers
 * in one: `{ property, code, message, write? }`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Exception\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\ValidationResult;
use Throwable;

/**
 * The four save-path dialects, answered in one.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
class FormPayloadFindings {

	/**
	 * The findings of an opis validation result; empty when valid.
	 *
	 * @param ValidationResult $result The result.
	 * @param string|null      $write  The write the payload belongs to, when several are written.
	 *
	 * @return array<int, array{property: string, code: string, message: string, write?: string}> The findings.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function fromResult(ValidationResult $result, ?string $write = null): array {
		$error = $result->error();
		if ($result->isValid() === true || $error === null) {
			return [];
		}

		return $this->fromError(error: $error, write: $write);
	}//end fromResult()

	/**
	 * The findings of a save-path exception.
	 *
	 * @param Throwable   $exception The exception.
	 * @param string|null $write     The write it came from, when several are written.
	 *
	 * @return array<int, array{property: string, code: string, message: string, write?: string}> The findings.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function fromThrowable(Throwable $exception, ?string $write = null): array {
		if ($exception instanceof ValidationException && $exception->getErrors() !== null) {
			return $this->fromError(error: $exception->getErrors(), write: $write);
		}

		if ($exception instanceof CustomValidationException) {
			return $this->fromMap(errors: $exception->getErrors(), code: $this->customCode(exception: $exception), write: $write);
		}

		if ($exception instanceof HookStoppedException) {
			return $this->fromMap(errors: $exception->getErrors(), code: 'refused', write: $write);
		}

		$code = 'invalid';
		if ($exception instanceof DuplicateBlockedException || $exception instanceof ObjectExistsException) {
			$code = 'duplicate';
		}

		return [$this->finding(property: '', code: $code, message: $exception->getMessage(), write: $write)];
	}//end fromThrowable()

	/**
	 * Whether a throwable refuses the payload (answer 422) rather than the moment (answer 503).
	 *
	 * @param Throwable $exception The exception.
	 *
	 * @return bool True for a refusal of what was filled in.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-writing-several-objects-must-commit-all-or-none
	 */
	public function isPayloadRefusal(Throwable $exception): bool {
		return $exception instanceof ValidationException
			|| $exception instanceof CustomValidationException
			|| $exception instanceof HookStoppedException
			|| $exception instanceof DuplicateBlockedException
			|| $exception instanceof ObjectExistsException;
	}//end isPayloadRefusal()

	/**
	 * The leaf errors of an opis error tree, one finding each.
	 *
	 * @param ValidationError $error The root error.
	 * @param string|null     $write The write, when several are written.
	 *
	 * @return array<int, array{property: string, code: string, message: string, write?: string}> The findings.
	 */
	private function fromError(ValidationError $error, ?string $write): array {
		$subErrors = $error->subErrors();
		if ($subErrors !== []) {
			$findings = [];
			foreach ($subErrors as $subError) {
				array_push($findings, ...$this->fromError(error: $subError, write: $write));
			}

			return $findings;
		}

		$formatter = new ErrorFormatter();
		$message = $formatter->formatErrorMessage($error);
		$path = implode('.', array_map('strval', $error->data()->fullPath()));
		if ($error->keyword() !== 'required') {
			return [$this->finding(property: $path, code: $error->keyword(), message: $message, write: $write)];
		}

		$findings = [];
		foreach ((array)($error->args()['missing'] ?? []) as $missing) {
			$property = ltrim($path . '.' . (string)$missing, '.');
			$findings[] = $this->finding(property: $property, code: 'required', message: $message, write: $write);
		}

		return $findings;
	}//end fromError()

	/**
	 * Findings from a `property => message` map or a `{property, message}` list.
	 *
	 * @param array<int|string, mixed> $errors The errors.
	 * @param string                   $code   The code every entry gets.
	 * @param string|null              $write  The write, when several are written.
	 *
	 * @return array<int, array{property: string, code: string, message: string, write?: string}> The findings.
	 */
	private function fromMap(array $errors, string $code, ?string $write): array {
		$findings = [];
		foreach ($errors as $key => $entry) {
			$property = '';
			if (is_string($key) === true) {
				$property = $key;
			}

			$message = $entry;
			if (is_array($entry) === true) {
				$property = (string)($entry['property'] ?? $entry['field'] ?? $property);
				$message = ($entry['message'] ?? $entry['error'] ?? json_encode($entry));
			}

			$findings[] = $this->finding(property: $property, code: $code, message: (string)$message, write: $write);
		}

		return $findings;
	}//end fromMap()

	/**
	 * The code for a custom validation refusal: uniqueness, or invalid.
	 *
	 * @param CustomValidationException $exception The exception.
	 *
	 * @return string The code.
	 */
	private function customCode(CustomValidationException $exception): string {
		if (str_contains(strtolower($exception->getMessage()), 'not unique') === true) {
			return 'unique';
		}

		return 'invalid';
	}//end customCode()

	/**
	 * One finding, `write` present only when named.
	 *
	 * @param string      $property The property path.
	 * @param string      $code     The code.
	 * @param string      $message  The message.
	 * @param string|null $write    The write.
	 *
	 * @return array{property: string, code: string, message: string, write?: string} The finding.
	 */
	private function finding(string $property, string $code, string $message, ?string $write): array {
		$finding = ['property' => $property, 'code' => $code, 'message' => $message];
		if ($write !== null) {
			$finding['write'] = $write;
		}

		return $finding;
	}//end finding()
}//end class

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

		return $this->tagged(findings: $this->fromError(error: $error), write: $write);
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
		return $this->tagged(findings: $this->throwableFindings(exception: $exception), write: $write);
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
	 * The findings of a save-path exception, untagged.
	 *
	 * @param Throwable $exception The exception.
	 *
	 * @return array<int, array{property: string, code: string, message: string}> The findings.
	 */
	private function throwableFindings(Throwable $exception): array {
		if ($exception instanceof ValidationException && $exception->getErrors() !== null) {
			return $this->fromError(error: $exception->getErrors());
		}

		if ($exception instanceof CustomValidationException) {
			return $this->fromMap(errors: $exception->getErrors(), code: $this->customCode(exception: $exception));
		}

		if ($exception instanceof HookStoppedException) {
			return $this->fromMap(errors: $exception->getErrors(), code: 'refused');
		}

		return [['property' => '', 'code' => $this->genericCode(exception: $exception), 'message' => $exception->getMessage()]];
	}//end throwableFindings()

	/**
	 * The code for any other save-path exception: duplicate, or invalid.
	 *
	 * @param Throwable $exception The exception.
	 *
	 * @return string The code.
	 */
	private function genericCode(Throwable $exception): string {
		if ($exception instanceof DuplicateBlockedException || $exception instanceof ObjectExistsException) {
			return 'duplicate';
		}

		return 'invalid';
	}//end genericCode()

	/**
	 * The leaf errors of an opis error tree, one finding each.
	 *
	 * @param ValidationError $error The root error.
	 *
	 * @return array<int, array{property: string, code: string, message: string}> The findings.
	 */
	private function fromError(ValidationError $error): array {
		$subErrors = $error->subErrors();
		if ($subErrors === []) {
			return $this->fromLeaf(error: $error);
		}

		$findings = [];
		foreach ($subErrors as $subError) {
			array_push($findings, ...$this->fromError(error: $subError));
		}

		return $findings;
	}//end fromError()

	/**
	 * The findings of one leaf error: one per missing property for `required`, else one.
	 *
	 * @param ValidationError $error The leaf error.
	 *
	 * @return array<int, array{property: string, code: string, message: string}> The findings.
	 */
	private function fromLeaf(ValidationError $error): array {
		$message = (new ErrorFormatter())->formatErrorMessage($error);
		$path = implode('.', array_map('strval', $error->data()->fullPath()));
		if ($error->keyword() !== 'required') {
			return [['property' => $path, 'code' => $error->keyword(), 'message' => $message]];
		}

		$findings = [];
		foreach ((array) ($error->args()['missing'] ?? []) as $missing) {
			$findings[] = ['property' => ltrim($path.'.'.(string) $missing, '.'), 'code' => 'required', 'message' => $message];
		}

		return $findings;
	}//end fromLeaf()

	/**
	 * Findings from a `property => message` map or a `{property, message}` list.
	 *
	 * @param array<int|string, mixed> $errors The errors.
	 * @param string                   $code   The code every entry gets.
	 *
	 * @return array<int, array{property: string, code: string, message: string}> The findings.
	 */
	private function fromMap(array $errors, string $code): array {
		$findings = [];
		foreach ($errors as $key => $entry) {
			$named = $this->namedEntry(key: $key, entry: $entry);
			$findings[] = ['property' => $named['property'], 'code' => $code, 'message' => $named['message']];
		}

		return $findings;
	}//end fromMap()

	/**
	 * The property and message one map entry names.
	 *
	 * A string key names the property; an array entry may override it with
	 * `property`/`field` and carries its message in `message`/`error`.
	 *
	 * @param int|string $key   The map key.
	 * @param mixed      $entry The entry: a message, or a `{property, message}` map.
	 *
	 * @return array{property: string, message: string} The named entry.
	 */
	private function namedEntry(int|string $key, mixed $entry): array {
		$property = '';
		if (is_string($key) === true) {
			$property = $key;
		}

		if (is_array($entry) === false) {
			return ['property' => $property, 'message' => (string) $entry];
		}

		return ['property' => (string) ($entry['property'] ?? $entry['field'] ?? $property), 'message' => $this->entryMessage(entry: $entry)];
	}//end namedEntry()

	/**
	 * The message an array entry carries, or the entry itself as JSON.
	 *
	 * @param array<int|string, mixed> $entry The entry.
	 *
	 * @return string The message.
	 */
	private function entryMessage(array $entry): string {
		return (string) ($entry['message'] ?? $entry['error'] ?? json_encode($entry));
	}//end entryMessage()

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
	 * Name the write on every finding, when one is named.
	 *
	 * @param array<int, array{property: string, code: string, message: string}> $findings The findings.
	 * @param string|null                                                        $write    The write.
	 *
	 * @return array<int, array{property: string, code: string, message: string, write?: string}> The findings.
	 */
	private function tagged(array $findings, ?string $write): array {
		if ($write === null) {
			return $findings;
		}

		return array_map(static fn (array $finding): array => $finding + ['write' => $write], $findings);
	}//end tagged()
}//end class

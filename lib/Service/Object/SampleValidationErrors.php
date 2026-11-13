<?php

/**
 * SampleValidationErrors: the refusals of a save's pre-write checks, per property.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use Opis\JsonSchema\Errors\ErrorFormatter;

/**
 * Turns the exception a save's pre-write checks throw into one entry per
 * refused property, naming the rule.
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */
final class SampleValidationErrors {

	/**
	 * The start of the message a unique-field refusal carries.
	 *
	 * @var string
	 */
	private const UNIQUE_MESSAGE = 'Fields are not unique';

	/**
	 * The refusals a schema validation or a write rule threw.
	 *
	 * A schema refusal carries the validator's error tree and is split per
	 * JSON pointer; a write rule (bounds, groups, geometry, read-only, lens)
	 * carries a message alone.
	 *
	 * @param ValidationException $exception The refusal.
	 *
	 * @return array<int, array{property: string|null, message: string, rule: string}>
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public static function fromValidation(ValidationException $exception): array {
		$tree = $exception->getErrors();
		if ($tree === null) {
			return [['property' => null, 'message' => $exception->getMessage(), 'rule' => 'write-rule']];
		}

		$errors = [];
		foreach ((new ErrorFormatter())->format($tree) as $pointer => $messages) {
			foreach ((array) $messages as $message) {
				$errors[] = ['property' => self::property(pointer: (string) $pointer), 'message' => (string) $message, 'rule' => 'schema'];
			}
		}

		if ($errors === []) {
			return [['property' => null, 'message' => $exception->getMessage(), 'rule' => 'schema']];
		}

		return $errors;
	}//end fromValidation()

	/**
	 * The refusals a unique-field or extended-field-type check threw, keyed by property.
	 *
	 * @param CustomValidationException $exception The refusal.
	 *
	 * @return array<int, array{property: string|null, message: string, rule: string}>
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public static function fromCustom(CustomValidationException $exception): array {
		$rule = 'field-type';
		if (str_starts_with($exception->getMessage(), self::UNIQUE_MESSAGE) === true) {
			$rule = 'unique';
		}

		$errors = [];
		foreach ($exception->getErrors() as $property => $messages) {
			foreach ((array) $messages as $message) {
				$errors[] = ['property' => (string) $property, 'message' => (string) $message, 'rule' => $rule];
			}
		}

		if ($errors === []) {
			return [['property' => null, 'message' => $exception->getMessage(), 'rule' => $rule]];
		}

		return $errors;
	}//end fromCustom()

	/**
	 * The property a JSON pointer names: the pointer without its leading slash, null for the root.
	 *
	 * @param string $pointer The pointer, e.g. `/gemeente` or `/adres/postcode`.
	 *
	 * @return string|null
	 */
	private static function property(string $pointer): ?string {
		$property = ltrim($pointer, '/');
		if ($property === '') {
			return null;
		}

		return $property;
	}//end property()
}//end class

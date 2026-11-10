<?php

/**
 * OpenRegister Form Write References
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

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCP\IL10N;

/**
 * Resolves the `{ "$write": "<as>" }` values that let one write of a submit point at an earlier one.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final readonly class FormWriteReferences {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the refusal for a reference to no earlier write.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function __construct(
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Replace `{ "$write": "<as>" }` values with the placeholder id, while validating before any write.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The object.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function withPlaceholders(array $object): array {
		foreach ($object as $property => $value) {
			if ($this->writeReferenceOf(value: $value) !== null) {
				$object[$property] = FormSubmitService::PENDING_ID;
			}
		}

		return $object;
	}//end withPlaceholders()

	/**
	 * Replace `{ "$write": "<as>" }` values with the id of that earlier write of this submit.
	 *
	 * @param array<string, mixed>  $object The object.
	 * @param array<string, string> $ids    Ids of earlier writes, by `as`.
	 *
	 * @return array<string, mixed> The object.
	 *
	 * @throws FormSubmitRefusedException 422 when a reference names no earlier write.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function withEarlierIds(array $object, array $ids): array {
		foreach ($object as $property => $value) {
			$name = $this->writeReferenceOf(value: $value);
			if ($name === null) {
				continue;
			}

			if (isset($ids[$name]) === false) {
				$this->refuseUnknown(name: $name, property: (string)$property);
			}

			$object[$property] = $ids[$name];
		}

		return $object;
	}//end withEarlierIds()

	/**
	 * The write a `{ "$write": "<as>" }` value names, or null for any other value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The write's `as`.
	 */
	private function writeReferenceOf(mixed $value): ?string {
		if (is_array($value) === false || count($value) !== 1 || is_string($value['$write'] ?? null) === false) {
			return null;
		}

		return $value['$write'];
	}//end writeReferenceOf()

	/**
	 * Refuse a reference to a write this submit has not made.
	 *
	 * @param string $name     The write referenced.
	 * @param string $property The property holding the reference.
	 *
	 * @return never
	 *
	 * @throws FormSubmitRefusedException Always, 422.
	 */
	private function refuseUnknown(string $name, string $property): never {
		$message = $this->l10n->t('Write "%1$s" is referenced before it is made.', [$name]);
		throw new FormSubmitRefusedException(
			message: $message,
			status: 422,
			findings: [['property' => $property, 'code' => 'write-unknown', 'message' => $message]]
		);
	}//end refuseUnknown()
}//end class

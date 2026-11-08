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
	 * Replace `{ "$write": "<as>" }` values with that write's id (or a placeholder while validating).
	 *
	 * @param array<string, mixed>  $object  The object.
	 * @param array<string, string> $ids     Ids of earlier writes, by `as`.
	 * @param bool                  $pending True while validating, before any write.
	 *
	 * @return array<string, mixed> The object.
	 *
	 * @throws FormSubmitRefusedException 422 when a reference names no earlier write.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function resolveWriteReferences(array $object, array $ids, bool $pending): array {
		foreach ($object as $property => $value) {
			$name = $this->writeReferenceOf(value: $value);
			if ($name === null) {
				continue;
			}

			$object[$property] = FormSubmitService::PENDING_ID;
			if ($pending === false) {
				$object[$property] = $this->earlierId(ids: $ids, name: $name, property: (string)$property);
			}
		}

		return $object;
	}//end resolveWriteReferences()

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
	 * The id of an earlier write of this submit.
	 *
	 * @param array<string, string> $ids      Ids of earlier writes, by `as`.
	 * @param string                $name     The write referenced.
	 * @param string                $property The property holding the reference.
	 *
	 * @return string The id.
	 *
	 * @throws FormSubmitRefusedException 422 when the reference names no earlier write.
	 */
	private function earlierId(array $ids, string $name, string $property): string {
		if (isset($ids[$name]) === false) {
			$message = $this->l10n->t('Write "%1$s" is referenced before it is made.', [$name]);
			throw new FormSubmitRefusedException(
				message: $message,
				status: 422,
				findings: [['property' => $property, 'code' => 'write-unknown', 'message' => $message]]
			);
		}

		return $ids[$name];
	}//end earlierId()
}//end class

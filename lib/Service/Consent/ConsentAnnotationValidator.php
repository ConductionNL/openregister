<?php

/**
 * OpenRegister ConsentAnnotationValidator
 *
 * Schema-save validation for the per-property `x-openregister-consent`
 * annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/consent-evidence-envelope/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consent;

/**
 * Validates the shape of a per-property `x-openregister-consent` annotation.
 *
 * A schema author declares a property as consent-shaped by annotating it
 * directly (mirroring the per-property style `x-openregister-calculations`
 * already supports via `PropertyCalculations::fromProperties()`):
 *
 * ```json
 * "beeldmateriaalConsent": {
 *   "type": "array",
 *   "x-openregister-consent": {
 *     "purpose": "beeldmateriaal-gebruik",
 *     "subjectProperty": "learnerRef"
 *   }
 * }
 * ```
 *
 * `purpose` is mandatory (a fixed string identifying what is being
 * consented to). `subjectProperty`, when present, names another property
 * on the same object holding the data subject's identifier; when absent
 * the data subject is the acting user. The annotated property MUST be
 * declared `type: array` — see design.md "Decisions" for why the shape is
 * an append-only list of acts rather than one mutable record.
 */
final class ConsentAnnotationValidator {

	/**
	 * Validate every `x-openregister-consent` annotation declared on a schema's properties.
	 *
	 * @param array<string, mixed> $schema Full schema (must include `properties`).
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	public function validate(array $schema): array {
		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		$errors = [];
		foreach ($properties as $name => $definition) {
			if (is_array($definition) === false || isset($definition['x-openregister-consent']) === false) {
				continue;
			}

			$errors = array_merge($errors, $this->validateProperty(name: (string)$name, definition: $definition));
		}

		return $errors;

	}//end validate()

	/**
	 * Validate one property's `x-openregister-consent` annotation.
	 *
	 * @param string $name The property name (for error messages).
	 * @param array<string, mixed> $definition The property's schema definition.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateProperty(string $name, array $definition): array {
		$errors = [];

		$type = ($definition['type'] ?? null);
		if ($type !== 'array') {
			$typeLabel = 'unknown';
			if (is_string($type) === true) {
				$typeLabel = $type;
			}

			$errors[] = [
				'code' => 'consent-not-array',
				'message' => sprintf(
					'Property "%s" declares x-openregister-consent but is type "%s"; it must be type "array".',
					$name,
					$typeLabel
				),
			];
		}

		$annotation = $definition['x-openregister-consent'];
		if (is_array($annotation) === false) {
			$errors[] = [
				'code' => 'consent-malformed',
				'message' => sprintf('Property "%s" x-openregister-consent must be an object.', $name),
			];

			return $errors;
		}

		$purpose = ($annotation['purpose'] ?? null);
		if (is_string($purpose) === false || $purpose === '') {
			$errors[] = [
				'code' => 'consent-missing-purpose',
				'message' => sprintf('Property "%s" x-openregister-consent must declare a non-empty "purpose".', $name),
			];
		}

		$subjectProperty = ($annotation['subjectProperty'] ?? null);
		if ($subjectProperty !== null && is_string($subjectProperty) === false) {
			$errors[] = [
				'code' => 'consent-bad-subject-property',
				'message' => sprintf('Property "%s" x-openregister-consent.subjectProperty must be a string when present.', $name),
			];
		}

		return $errors;

	}//end validateProperty()
}//end class

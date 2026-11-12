<?php

/**
 * OpenRegister MergeAnnotationValidator
 *
 * Validates the shape of an `x-openregister-merge` schema annotation,
 * returning a list of `{code, message}` errors. Mirrors the contract of
 * {@see \OCA\OpenRegister\Service\Survivorship\SurvivorshipAnnotationValidator}:
 * an empty array means valid; the caller (SchemaMapper) degrades any error to
 * a non-fatal warning so a malformed merge block never aborts a schema
 * import — the schema still stores objects, merges simply fall back to the
 * documented defaults.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

use OCA\OpenRegister\Service\Survivorship\MdmAnnotationChecks;

/**
 * Shape validator for the `x-openregister-merge` annotation.
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#1.2
 */
class MergeAnnotationValidator implements MergeAnnotationValidatorInterface {

	/**
	 * The optional fields that must be strings when present.
	 *
	 * @var array<int, string>
	 */
	private const STRING_FIELDS = [
		'sourceLinkField',
		'entityType',
		'statusField',
		'survivorStatus',
		'mergedStatus',
	];

	/**
	 * Validate the `x-openregister-merge` annotation in a schema shape.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-merge`.
	 *
	 * @return array<int, array{code: string, message: string}> Errors; empty when valid or absent.
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#1.2
	 */
	public function validate(array $schema): array {
		$annotation = ($schema['x-openregister-merge'] ?? null);
		$shapeErrors = (new MdmAnnotationChecks(annotation: 'merge'))->shapeErrors(annotation: $annotation);
		if ($shapeErrors !== null) {
			return $shapeErrors;
		}

		return $this->annotationErrors(annotation: $annotation);
	}//end validate()

	/**
	 * The errors of an annotation that is an object, in check order.
	 *
	 * @param array<string, mixed> $annotation Merge annotation.
	 *
	 * @return array<int, array{code: string, message: string}> Errors; empty when valid.
	 */
	private function annotationErrors(array $annotation): array {
		$errors = $this->validateReversalWindow(annotation: $annotation);
		$errors = array_merge($errors, $this->validateStringFields(annotation: $annotation));
		$errors = array_merge($errors, $this->validateSourceLink(annotation: $annotation));

		return $errors;
	}//end annotationErrors()

	/**
	 * Validate an optional `sourceLink` block. A `reverseFk` mode requires
	 * `sourceSchema` and `referenceField`; anything else is accepted (embedded
	 * mode). Errors are non-fatal (surfaced as warnings by the caller).
	 *
	 * @param array<string, mixed> $annotation Merge annotation.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#1.1
	 */
	private function validateSourceLink(array $annotation): array {
		return (new MdmAnnotationChecks(annotation: 'merge'))->errors(sourceLink: ($annotation['sourceLink'] ?? null));
	}//end validateSourceLink()

	/**
	 * `reversalWindowDays`, when present, must be a positive integer.
	 *
	 * @param array<string, mixed> $annotation The merge annotation.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty when valid).
	 */
	private function validateReversalWindow(array $annotation): array {
		if (array_key_exists('reversalWindowDays', $annotation) === false) {
			return [];
		}

		$window = $annotation['reversalWindowDays'];
		if (is_int($window) === false || $window <= 0) {
			return [
				[
					'code' => 'merge.invalid-reversal-window',
					'message' => 'x-openregister-merge "reversalWindowDays" must be a positive integer.',
				],
			];
		}

		return [];
	}//end validateReversalWindow()

	/**
	 * Validate the optional string-valued fields, when present.
	 *
	 * @param array<string, mixed> $annotation Merge annotation.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#1.2
	 */
	private function validateStringFields(array $annotation): array {
		$errors = [];
		foreach (self::STRING_FIELDS as $field) {
			if (array_key_exists($field, $annotation) === true && is_string($annotation[$field]) === false) {
				$errors[] = [
					'code' => 'merge.field-not-string',
					'message' => sprintf('x-openregister-merge "%s" must be a string.', $field),
				];
			}
		}

		return $errors;
	}//end validateStringFields()
}//end class

<?php

/**
 * OpenRegister SchemaCatalogue
 *
 * The vocabularies and checks of a schema's classification and catalogue block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Formats\EmailFormat;

/**
 * Closed vocabularies (D-2), the save-path check, and the anonymous view.
 *
 * The classification is descriptive metadata: nothing here or anywhere reads
 * it as an access decision (openregister ADR-006).
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md
 */
final class SchemaCatalogue {

	/**
	 * The classifications, the four values of the Objects API in English kebab case.
	 */
	public const CLASSIFICATIONS = ['open', 'internal', 'confidential', 'strictly-confidential'];

	/**
	 * The update frequencies.
	 */
	public const UPDATE_FREQUENCIES = ['realtime', 'daily', 'weekly', 'monthly', 'yearly', 'irregular'];

	/**
	 * Why a schema body's classification or catalogue block is refused, or null when it is fine.
	 *
	 * A key that is absent or null is not checked: absent means unchanged on
	 * an update and unset on a create.
	 *
	 * @param array<string, mixed> $data The schema body.
	 *
	 * @return array{field: string, message: string}|null The refusal, naming the field.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md#requirement-a-schema-carries-a-data-classification
	 */
	public static function violation(array $data): ?array {
		$classification = ($data['classification'] ?? null);
		if ($classification !== null && self::isClassification(value: $classification) === false) {
			return self::refusal(field: 'classification', message: 'classification must be one of: ' . implode(', ', self::CLASSIFICATIONS) . '.');
		}

		$catalogue = ($data['catalogue'] ?? null);
		if ($catalogue === null) {
			return null;
		}

		if (is_array($catalogue) === false || ($catalogue !== [] && array_is_list($catalogue) === true)) {
			return self::refusal(field: 'catalogue', message: 'catalogue must be an object.');
		}

		return self::catalogueViolation(catalogue: $catalogue);
	}//end violation()

	/**
	 * Whether a value is one of the classifications.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md#requirement-schemas-can-be-listed-by-classification
	 */
	public static function isClassification(mixed $value): bool {
		return is_string($value) === true && in_array($value, self::CLASSIFICATIONS, true) === true;
	}//end isClassification()

	/**
	 * A serialised schema as an anonymous caller may see it: without the catalogue's contact person.
	 *
	 * @param array<string, mixed> $schema The serialised schema.
	 *
	 * @return array<string, mixed> The schema.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md#requirement-a-schema-carries-catalogue-metadata
	 */
	public static function withoutContact(array $schema): array {
		if (is_array($schema['catalogue'] ?? null) === true) {
			unset($schema['catalogue']['contact']);
		}

		return $schema;
	}//end withoutContact()

	/**
	 * The checks inside the catalogue block.
	 *
	 * @param array<string, mixed> $catalogue The block.
	 *
	 * @return array{field: string, message: string}|null The refusal.
	 */
	private static function catalogueViolation(array $catalogue): ?array {
		foreach (['maintainer', 'contact'] as $part) {
			$value = ($catalogue[$part] ?? null);
			if ($value !== null && (is_array($value) === false || ($value !== [] && array_is_list($value) === true))) {
				return self::refusal(field: 'catalogue.' . $part, message: 'catalogue.' . $part . ' must be an object.');
			}
		}

		$frequency = ($catalogue['updateFrequency'] ?? null);
		if ($frequency !== null && (is_string($frequency) === false || in_array($frequency, self::UPDATE_FREQUENCIES, true) === false)) {
			return self::refusal(
				field: 'catalogue.updateFrequency',
				message: 'catalogue.updateFrequency must be one of: ' . implode(', ', self::UPDATE_FREQUENCIES) . '.'
			);
		}

		$email = ($catalogue['contact']['email'] ?? null);
		if ($email !== null && (is_string($email) === false || EmailFormat::isValid(value: $email) === false)) {
			return self::refusal(field: 'catalogue.contact.email', message: 'catalogue.contact.email must be an e-mail address.');
		}

		return null;
	}//end catalogueViolation()

	/**
	 * A refusal naming its field.
	 *
	 * @param string $field   The field.
	 * @param string $message The message.
	 *
	 * @return array{field: string, message: string}
	 */
	private static function refusal(string $field, string $message): array {
		return ['field' => $field, 'message' => $message];
	}//end refusal()
}//end class

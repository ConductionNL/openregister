<?php

/**
 * ProcessingAnnotationReader — the one reader of the `x-openregister-processing` dialect.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

/**
 * Normalises a schema's or register's processing annotation.
 *
 * The read log and data subject discovery both ask which field names the data
 * subject; reading the dialect in one place keeps them from disagreeing.
 *
 * @spec openspec/changes/dsar-finds-records-by-declared-subject-id/specs/gdpr-data-subject-rights/spec.md
 */
final class ProcessingAnnotationReader {

	/**
	 * The configuration key of the dialect.
	 *
	 * @var string
	 */
	public const ANNOTATION_KEY = 'x-openregister-processing';

	/**
	 * The legacy shorthand key: a single attribution reference.
	 *
	 * @var string
	 */
	public const LEGACY_ANNOTATION_KEY = 'x-openregister-processing-activity';

	/**
	 * Normalise an annotation block from either the dialect or the legacy key.
	 *
	 * @param mixed $config The raw schema or register configuration.
	 *
	 * @return array{logReads: bool, attribution: array<array-key, mixed>, subjectIdFields: array<array-key, mixed>}|null
	 *         The annotation, or null when there is none.
	 *
	 * @spec openspec/changes/dsar-finds-records-by-declared-subject-id/specs/gdpr-data-subject-rights/spec.md
	 */
	public function read(mixed $config): ?array {
		if (is_array($config) === false) {
			return null;
		}

		$dialect = ($config[self::ANNOTATION_KEY] ?? null);
		if (is_array($dialect) === true) {
			return [
				'logReads' => (($dialect['logReads'] ?? false) === true),
				'attribution' => self::asArray(value: ($dialect['attribution'] ?? null)),
				'subjectIdFields' => self::asArray(value: ($dialect['subjectIdFields'] ?? null)),
			];
		}

		return self::legacyAnnotation(legacy: ($config[self::LEGACY_ANNOTATION_KEY] ?? null));
	}//end read()

	/**
	 * The fields a configuration declares as the data subject's id, by id type.
	 *
	 * Only non-empty string type => field pairs are kept; with a type, only
	 * the fields declared under that type.
	 *
	 * @param mixed       $config The raw schema configuration.
	 * @param string|null $type   The id type to keep, or null for every type.
	 *
	 * @return array<string, string> The field per id type.
	 *
	 * @spec openspec/changes/dsar-finds-records-by-declared-subject-id/specs/gdpr-data-subject-rights/spec.md
	 */
	public function subjectIdFields(mixed $config, ?string $type = null): array {
		$fields = [];
		foreach (($this->read(config: $config)['subjectIdFields'] ?? []) as $idType => $field) {
			if (is_string($idType) === false || $idType === '' || is_string($field) === false || $field === '') {
				continue;
			}

			if ($type !== null && $type !== '' && $idType !== $type) {
				continue;
			}

			$fields[$idType] = $field;
		}

		return $fields;
	}//end subjectIdFields()

	/**
	 * The legacy shorthand: a single attribution reference, reads off; null when absent.
	 *
	 * @param mixed $legacy The legacy annotation value.
	 *
	 * @return array{logReads: false, attribution: array{default: string}, subjectIdFields: array{}}|null
	 */
	private static function legacyAnnotation(mixed $legacy): ?array {
		if (is_string($legacy) === false || $legacy === '') {
			return null;
		}

		return [
			'logReads' => false,
			'attribution' => ['default' => $legacy],
			'subjectIdFields' => [],
		];
	}//end legacyAnnotation()

	/**
	 * Coerce a value to an array, returning an empty array when it is not one.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return array<array-key, mixed>
	 */
	private static function asArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end asArray()
}//end class

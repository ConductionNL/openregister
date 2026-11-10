<?php

/**
 * PropertySemanticReferenceValidator — validate the `referenceSemanticType`
 * marker on schema property definitions (ADR-048).
 *
 * Schema properties may declare `referenceSemanticType: <absolute-IRI>` to
 * indicate they reference a cross-app object by canonical semantic type
 * (e.g. `https://schema.org/Organization`), resolved across all installed
 * schemas by {@see \OCA\OpenRegister\Service\SemanticTypeResolver}. An
 * optional `referenceSemanticApp` string is a provider hint only.
 *
 * This validator enforces the write-time contract:
 *   - `referenceSemanticType`, when present, is a non-empty absolute IRI
 *     (reusing {@see JsonLdContextService::isAbsoluteIri()});
 *   - `referenceSemanticApp`, when present, is a string.
 *
 * It is independent of `referenceType` (an integration id) and of `$ref`
 * (a concrete schema). Backwards-compat: properties without
 * `referenceSemanticType` validate exactly as before; the marker is opt-in.
 *
 * WIRING: this validator is intentionally standalone, mirroring
 * {@see \OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator}
 * which is registered in DI but not yet invoked from the schema-save path.
 * To enforce it on write, call {@see validateAll()} with a schema's
 * `properties` map from the schema create/update path (SchemaService /
 * SchemasController::create + update) alongside the existing
 * PropertyReferenceTypeValidator, and surface any thrown
 * InvalidArgumentException as a 400 validation error.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use InvalidArgumentException;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;

/**
 * Validate the optional `referenceSemanticType` marker on schema properties.
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 */
class PropertySemanticReferenceValidator {
	use PropertyReferenceValidationTrait;

	/**
	 * Constructor.
	 *
	 * @param JsonLdContextService $jsonLd IRI validation helper (isAbsoluteIri).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly JsonLdContextService $jsonLd,
	) {
	}//end __construct()

	/**
	 * Validate a single property definition.
	 *
	 * Properties without a `referenceSemanticType` key pass through.
	 * Properties with one MUST carry a non-empty absolute IRI; an optional
	 * `referenceSemanticApp` MUST be a string.
	 *
	 * @param array<string,mixed> $property Schema property definition.
	 * @param string|null $propertyName Optional property name for error messages.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When referenceSemanticType is not a
	 *                                  well-formed absolute IRI, or the app
	 *                                  hint is non-string.
	 *
	 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
	 *   (Requirement: A property can reference a semantic type)
	 */
	public function validate(array $property, ?string $propertyName = null): void {
		$value = ($property['referenceSemanticType'] ?? null);
		if ($value === null) {
			return;
		}

		$problem = ($this->semanticTypeProblem(value: $value) ?? $this->appHintProblem(appHint: ($property['referenceSemanticApp'] ?? null)));
		$this->refuse(propertyName: $propertyName, problem: $problem);
	}//end validate()

	/**
	 * What is wrong with a non-null `referenceSemanticType`, if anything.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string|null The problem, or null when it is an absolute IRI.
	 */
	private function semanticTypeProblem(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return 'referenceSemanticType must be a non-empty string';
		}

		if ($this->jsonLd->isAbsoluteIri(value: $value) === false) {
			return sprintf("referenceSemanticType must be an absolute IRI, got '%s'", $value);
		}

		return null;
	}//end semanticTypeProblem()

	/**
	 * What is wrong with the optional `referenceSemanticApp` provider hint, if anything.
	 *
	 * @param mixed $appHint The declared hint (null when absent).
	 *
	 * @return string|null The problem, or null when absent or a string.
	 */
	private function appHintProblem(mixed $appHint): ?string {
		if ($appHint !== null && is_string($appHint) === false) {
			return 'referenceSemanticApp must be a string when present';
		}

		return null;
	}//end appHintProblem()
}//end class

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
class PropertySemanticReferenceValidator
{
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
     * @param array<string,mixed> $property     Schema property definition.
     * @param string|null         $propertyName Optional property name for error messages.
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
    public function validate(array $property, ?string $propertyName=null): void
    {
        if (array_key_exists('referenceSemanticType', $property) === false) {
            return;
        }

        $value = $property['referenceSemanticType'];
        if ($value === null) {
            return;
        }

        if (is_string($value) === false || $value === '') {
            throw new InvalidArgumentException(
                $this->formatError(
                    propertyName: $propertyName,
                    key: 'referenceSemanticType',
                    detail: 'must be a non-empty string'
                )
            );
        }

        if ($this->jsonLd->isAbsoluteIri(value: $value) === false) {
            throw new InvalidArgumentException(
                $this->formatError(
                    propertyName: $propertyName,
                    key: 'referenceSemanticType',
                    detail: sprintf("must be an absolute IRI, got '%s'", $value)
                )
            );
        }

        // Optional provider hint — string when present.
        if (array_key_exists('referenceSemanticApp', $property) === true) {
            $appHint = $property['referenceSemanticApp'];
            if ($appHint !== null && is_string($appHint) === false) {
                throw new InvalidArgumentException(
                    $this->formatError(
                        propertyName: $propertyName,
                        key: 'referenceSemanticApp',
                        detail: 'must be a string when present'
                    )
                );
            }
        }
    }//end validate()

    /**
     * Validate every property in a schema's `properties` map.
     *
     * @param array<string,array<string,mixed>> $properties Property map.
     *
     * @return void
     *
     * @throws InvalidArgumentException On the first invalid property.
     *
     * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
     *   (Requirement: A property can reference a semantic type)
     */
    public function validateAll(array $properties): void
    {
        foreach ($properties as $name => $definition) {
            if (is_array($definition) === true) {
                $resolvedName = null;
                if (is_string($name) === true) {
                    $resolvedName = $name;
                }

                $this->validate(property: $definition, propertyName: $resolvedName);
            }
        }
    }//end validateAll()

    /**
     * Build the standard error-message prefix.
     *
     * @param string|null $propertyName Property name (or null for schema-level).
     * @param string      $key          The offending keyword.
     * @param string      $detail       Specific failure detail.
     *
     * @return string
     */
    private function formatError(?string $propertyName, string $key, string $detail): string
    {
        $prefix = $key;
        if ($propertyName !== null) {
            $prefix = sprintf("Property '%s' %s", $propertyName, $key);
        }

        return sprintf('%s %s', $prefix, $detail);
    }//end formatError()
}//end class

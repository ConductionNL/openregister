<?php

/**
 * PropertyReferenceTypeValidator — validate the `referenceType` marker
 * on schema property definitions (AD-18).
 *
 * Schema properties may declare `referenceType: <integration-id>` to
 * indicate they hold a reference to an entity managed by that
 * integration. `CnFormDialog` and `CnDetailGrid` then render the
 * matching integration's `single-entity` widget surface inline
 * instead of a raw uuid string.
 *
 * This validator enforces:
 *   - referenceType is a string when present (null / absent is OK)
 *   - the value matches an id currently registered in
 *     `IntegrationRegistry`
 *
 * Backwards-compat: schemas without `referenceType` validate as
 * before. Adding the marker is opt-in; removing it from a schema is
 * also a no-op for validation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use InvalidArgumentException;

/**
 * Validate the optional `referenceType` marker on schema properties.
 */
class PropertyReferenceTypeValidator
{

    /**
     * Constructor.
     *
     * @param IntegrationRegistry $registry The integration registry —
     *                                      used to look up valid ids.
     *
     * @return void
     */
    public function __construct(
        private IntegrationRegistry $registry,
    ) {
    }//end __construct()

    /**
     * Validate a single property definition.
     *
     * Properties without a `referenceType` key pass through. Properties
     * with one MUST carry a string that matches a currently-registered
     * integration id; otherwise this throws.
     *
     * @param array<string,mixed> $property     Schema property definition.
     * @param string|null         $propertyName Optional property name
     *                                          for error messages.
     *
     * @return void
     *
     * @throws InvalidArgumentException When referenceType is non-string
     *                                  or refers to an unregistered id.
     */
    public function validate(array $property, ?string $propertyName = null): void
    {
        if (array_key_exists('referenceType', $property) === false) {
            return;
        }

        $value = $property['referenceType'];
        if ($value === null) {
            return;
        }

        if (is_string($value) === false) {
            throw new InvalidArgumentException(
                $this->formatError($propertyName, 'must be a string or null')
            );
        }

        if ($value === '') {
            throw new InvalidArgumentException(
                $this->formatError($propertyName, 'must not be empty')
            );
        }

        if ($this->registry->isValidIntegrationId($value) === false) {
            $validIds = $this->registry->listIds();
            sort($validIds);
            throw new InvalidArgumentException(
                $this->formatError(
                    $propertyName,
                    sprintf(
                        "refers to unregistered integration '%s'. Registered ids: %s",
                        $value,
                        ($validIds === []) ? '(none)' : implode(', ', $validIds)
                    )
                )
            );
        }
    }//end validate()

    /**
     * Validate every property in a schema's `properties` map.
     *
     * Convenience wrapper for the per-schema validation path.
     *
     * @param array<string,array<string,mixed>> $properties Property map.
     *
     * @return void
     *
     * @throws InvalidArgumentException On the first invalid property.
     */
    public function validateAll(array $properties): void
    {
        foreach ($properties as $name => $definition) {
            if (is_array($definition) === true) {
                $this->validate($definition, is_string($name) ? $name : null);
            }
        }
    }//end validateAll()

    /**
     * Build the standard error-message prefix.
     *
     * @param string|null $propertyName Property name (or null for
     *                                  schema-level errors).
     * @param string      $detail       Specific failure detail.
     *
     * @return string
     */
    private function formatError(?string $propertyName, string $detail): string
    {
        $prefix = $propertyName === null
            ? "referenceType"
            : sprintf("Property '%s' referenceType", $propertyName);

        return sprintf('%s %s', $prefix, $detail);
    }//end formatError()

}//end class

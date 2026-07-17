<?php

/**
 * OpenRegister Schema Import — ImportOptions value object.
 *
 * Immutable options controlling a standards import: an optional property
 * subset, whether ancestor (inherited) properties are included, and an
 * optional target register. Pure data — no Nextcloud dependencies — so the
 * importers and their unit tests can construct it freely.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\Service\SchemaImport
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

namespace OCA\OpenRegister\Service\SchemaImport;

/**
 * Options for a standards import.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final class ImportOptions
{
    /**
     * Constructor.
     *
     * @param array<int, string> $propertySubset   Explicit property names to import; empty = all direct properties.
     * @param bool               $includeAncestors When true, inherited (ancestor) properties are also imported.
     * @param int|null           $targetRegister   Register id the resulting schema should be associated with, or null.
     */
    public function __construct(
        public readonly array $propertySubset=[],
        public readonly bool $includeAncestors=false,
        public readonly ?int $targetRegister=null
    ) {
    }//end __construct()

    /**
     * Build options from a loosely-typed request payload.
     *
     * @param array<string, mixed> $payload The request body / parameters.
     *
     * @return self The constructed options.
     *
     * @spec openspec/specs/schema-import/spec.md
     */
    public static function fromArray(array $payload): self
    {
        $subset = [];
        if (isset($payload['propertySubset']) === true && is_array($payload['propertySubset']) === true) {
            foreach ($payload['propertySubset'] as $name) {
                if (is_string($name) === true && $name !== '') {
                    $subset[] = $name;
                }
            }
        }

        $includeAncestors = false;
        if (isset($payload['includeAncestors']) === true) {
            $includeAncestors = filter_var($payload['includeAncestors'], FILTER_VALIDATE_BOOLEAN);
        }

        $targetRegister = null;
        if (isset($payload['targetRegister']) === true && is_numeric($payload['targetRegister']) === true) {
            $targetRegister = (int) $payload['targetRegister'];
        }

        return new self(
            propertySubset: $subset,
            includeAncestors: $includeAncestors,
            targetRegister: $targetRegister
        );
    }//end fromArray()

    /**
     * Whether an explicit property subset was requested.
     *
     * @return bool True when a non-empty subset is set.
     */
    public function hasSubset(): bool
    {
        return $this->propertySubset !== [];
    }//end hasSubset()
}//end class

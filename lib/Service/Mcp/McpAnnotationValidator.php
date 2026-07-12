<?php

/**
 * OpenRegister MCP Annotation Validator
 *
 * Save-time validator for the `x-openregister-mcp` dialect (ADR-031 dialect
 * family, ADR-063 MCP as Platform Abstraction). Declares — per schema,
 * opt-in — which coarse CRUD MCP tools that schema exposes. This validator
 * checks shape and types only; it never emits tools and never treats an MCP
 * annotation hint value as a security decision (the authoritative gate is
 * always OpenRegister RBAC at invoke time).
 *
 * Mirrors the error style of the sibling annotation validators
 * ({@see \OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidator},
 * {@see \OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator}) —
 * `validate()` returns an aggregated array of `{code, message}` errors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

/**
 * Validate the `x-openregister-mcp` annotation on a schema.
 *
 * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
 *   (Requirement: REQ-DIALECT-001, REQ-DIALECT-002, REQ-DIALECT-003)
 */
class McpAnnotationValidator
{

    /**
     * The fixed, closed verb set the dialect may declare (REQ-DIALECT-003).
     *
     * @var array<int, string>
     */
    public const VERBS = ['search', 'get', 'create', 'update', 'delete'];

    /**
     * Allowed `scope` values for a verb config.
     *
     * @var array<int, string>
     */
    public const SCOPES = ['read', 'create', 'update', 'delete'];

    /**
     * The MCP 2025-11-25 boolean annotation hints a verb config may carry.
     *
     * @var array<int, string>
     */
    public const HINT_KEYS = ['readOnlyHint', 'destructiveHint', 'idempotentHint'];

    /**
     * Validate the annotation.
     *
     * Expects the annotation shape `SchemaMapper` hands every annotation
     * validator: `['properties' => [...], 'x-openregister-mcp' => [...]]`.
     *
     * @param array<string, mixed> $schema The schema shape to validate.
     *
     * @return array<int, array{code: string, message: string}> Aggregated errors (empty = valid).
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Requirement: REQ-DIALECT-001 — The x-openregister-mcp schema dialect)
     */
    public function validate(array $schema): array
    {
        if (isset($schema['x-openregister-mcp']) === false) {
            return [];
        }

        $annotation = $schema['x-openregister-mcp'];
        if (is_array($annotation) === false) {
            return [
                [
                    'code'    => 'mcp-bad-annotation',
                    'message' => 'x-openregister-mcp must be an object.',
                ],
            ];
        }

        $errors = [];
        $this->validateEnabled(annotation: $annotation, errors: $errors);

        $properties = ($schema['properties'] ?? []);
        if (is_array($properties) === false) {
            $properties = [];
        }

        $this->validateTools(annotation: $annotation, properties: $properties, errors: $errors);

        return $errors;
    }//end validate()

    /**
     * Validate the required `enabled` boolean opt-in gate.
     *
     * @param array<string, mixed>                             $annotation The x-openregister-mcp block.
     * @param array<int, array{code: string, message: string}> $errors     Error accumulator (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Scenario: enabled must be boolean)
     */
    private function validateEnabled(array $annotation, array &$errors): void
    {
        if (array_key_exists('enabled', $annotation) === false) {
            $errors[] = [
                'code'    => 'mcp-missing-enabled',
                'message' => 'x-openregister-mcp requires an `enabled` boolean (the opt-in gate).',
            ];
            return;
        }

        if (is_bool($annotation['enabled']) === false) {
            $errors[] = [
                'code'    => 'mcp-bad-enabled',
                'message' => sprintf(
                    'x-openregister-mcp `enabled` must be a boolean, got %s.',
                    get_debug_type($annotation['enabled'])
                ),
            ];
        }
    }//end validateEnabled()

    /**
     * Validate the optional `tools` object: closed verb-key set, per-verb shape.
     *
     * @param array<string, mixed>                             $annotation The x-openregister-mcp block.
     * @param array<string, mixed>                             $properties The schema's declared properties (for `filters` cross-checks).
     * @param array<int, array{code: string, message: string}> $errors     Error accumulator (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Scenario: Unknown verb key is rejected; Scenario: The verb set is closed)
     */
    private function validateTools(array $annotation, array $properties, array &$errors): void
    {
        if (array_key_exists('tools', $annotation) === false) {
            return;
        }

        $tools = $annotation['tools'];
        if (is_array($tools) === false) {
            $errors[] = [
                'code'    => 'mcp-bad-tools',
                'message' => 'x-openregister-mcp `tools` must be an object.',
            ];
            return;
        }

        foreach ($tools as $verb => $config) {
            if (in_array((string) $verb, self::VERBS, true) === false) {
                $errors[] = [
                    'code'    => 'mcp-unknown-verb',
                    'message' => sprintf(
                        'x-openregister-mcp: unrecognised verb "%s". Allowed verbs: %s.',
                        (string) $verb,
                        implode(', ', self::VERBS)
                    ),
                ];
                continue;
            }

            if (is_array($config) === false) {
                $errors[] = [
                    'code'    => 'mcp-bad-verb-config',
                    'message' => sprintf('x-openregister-mcp: verb "%s" config must be an object.', (string) $verb),
                ];
                continue;
            }

            $this->validateVerbConfig(verb: (string) $verb, config: $config, properties: $properties, errors: $errors);
        }//end foreach
    }//end validateTools()

    /**
     * Validate a single verb's config object.
     *
     * @param string                                           $verb       The verb name (already known-valid).
     * @param array<string, mixed>                             $config     The verb's config object.
     * @param array<string, mixed>                             $properties The schema's declared properties.
     * @param array<int, array{code: string, message: string}> $errors     Error accumulator (by reference).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Aggregating every per-verb shape rule in one pass is inherent.
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Requirement: REQ-DIALECT-002 — Save-time validation of the dialect shape)
     */
    private function validateVerbConfig(string $verb, array $config, array $properties, array &$errors): void
    {
        $knownKeys = array_merge(['description', 'scope', 'filters'], self::HINT_KEYS);

        foreach (array_keys($config) as $key) {
            if (in_array((string) $key, $knownKeys, true) === false) {
                $errors[] = [
                    'code'    => 'mcp-unknown-key',
                    'message' => sprintf(
                        'x-openregister-mcp: verb "%s" has an unrecognised key "%s".',
                        $verb,
                        (string) $key
                    ),
                ];
            }
        }

        if (array_key_exists('description', $config) === true && is_string($config['description']) === false) {
            $errors[] = [
                'code'    => 'mcp-bad-description',
                'message' => sprintf('x-openregister-mcp: verb "%s" `description` must be a string.', $verb),
            ];
        }

        if (array_key_exists('scope', $config) === true
            && in_array($config['scope'], self::SCOPES, true) === false
        ) {
            $errors[] = [
                'code'    => 'mcp-bad-scope',
                'message' => sprintf(
                    'x-openregister-mcp: verb "%s" `scope` must be one of [%s].',
                    $verb,
                    implode(', ', self::SCOPES)
                ),
            ];
        }

        foreach (self::HINT_KEYS as $hintKey) {
            if (array_key_exists($hintKey, $config) === true && is_bool($config[$hintKey]) === false) {
                $errors[] = [
                    'code'    => 'mcp-bad-hint',
                    'message' => sprintf(
                        'x-openregister-mcp: verb "%s" `%s` must be a boolean.',
                        $verb,
                        $hintKey
                    ),
                ];
            }
        }

        $this->validateFilters(verb: $verb, config: $config, properties: $properties, errors: $errors);
    }//end validateVerbConfig()

    /**
     * Validate the `filters` key: permitted on `search` only, a list of
     * strings, each naming an existing schema property.
     *
     * @param string                                           $verb       The verb name.
     * @param array<string, mixed>                             $config     The verb's config object.
     * @param array<string, mixed>                             $properties The schema's declared properties.
     * @param array<int, array{code: string, message: string}> $errors     Error accumulator (by reference).
     *
     * @return void
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Scenario: search filter must reference an existing property; Scenario: filters are permitted only on the search verb)
     */
    private function validateFilters(string $verb, array $config, array $properties, array &$errors): void
    {
        if (array_key_exists('filters', $config) === false) {
            return;
        }

        if ($verb !== 'search') {
            $errors[] = [
                'code'    => 'mcp-filters-not-search',
                'message' => sprintf(
                    'x-openregister-mcp: `filters` is valid on the "search" verb only (found on "%s").',
                    $verb
                ),
            ];
            return;
        }

        $filters = $config['filters'];
        if (is_array($filters) === false) {
            $errors[] = [
                'code'    => 'mcp-bad-filters',
                'message' => 'x-openregister-mcp: `search.filters` must be a list of property names.',
            ];
            return;
        }

        foreach ($filters as $filter) {
            if (is_string($filter) === false) {
                $errors[] = [
                    'code'    => 'mcp-bad-filters',
                    'message' => 'x-openregister-mcp: `search.filters` entries must be strings.',
                ];
                continue;
            }

            if (array_key_exists($filter, $properties) === false) {
                $errors[] = [
                    'code'    => 'mcp-unknown-filter-property',
                    'message' => sprintf(
                        'x-openregister-mcp: `search.filters` names "%s" which is not a property on this schema.',
                        $filter
                    ),
                ];
            }
        }//end foreach
    }//end validateFilters()
}//end class

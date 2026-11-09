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
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Mcp;

/**
 * Validate the `x-openregister-mcp` annotation on a schema.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-DIALECT-001, REQ-DIALECT-002, REQ-DIALECT-003)
 */
class McpAnnotationValidator implements McpAnnotationValidatorInterface {

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
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-DIALECT-001 — The x-openregister-mcp schema dialect)
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-mcp']) === false) {
			return [];
		}

		$annotation = $schema['x-openregister-mcp'];
		if (is_array($annotation) === false) {
			return [['code' => 'mcp-bad-annotation', 'message' => 'x-openregister-mcp must be an object.']];
		}

		$properties = $this->declaredProperties(schema: $schema);
		return array_merge($this->enabledErrors(annotation: $annotation), $this->toolsErrors(annotation: $annotation, properties: $properties));
	}//end validate()

	/**
	 * The schema's declared properties; none when `properties` is absent or not an object.
	 *
	 * @param array<string, mixed> $schema The schema shape to validate.
	 *
	 * @return array<string, mixed> The declared properties.
	 */
	private function declaredProperties(array $schema): array {
		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		return $properties;
	}//end declaredProperties()

	/**
	 * Validate the required `enabled` boolean opt-in gate.
	 *
	 * @param array<string, mixed> $annotation The x-openregister-mcp block.
	 *
	 * @return array<int, array{code: string, message: string}> The errors of the gate.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Scenario: enabled must be boolean)
	 */
	private function enabledErrors(array $annotation): array {
		if (array_key_exists('enabled', $annotation) === false) {
			return [['code' => 'mcp-missing-enabled', 'message' => 'x-openregister-mcp requires an `enabled` boolean (the opt-in gate).']];
		}

		if (is_bool($annotation['enabled']) === true) {
			return [];
		}

		$type = get_debug_type($annotation['enabled']);
		return [['code' => 'mcp-bad-enabled', 'message' => sprintf('x-openregister-mcp `enabled` must be a boolean, got %s.', $type)]];
	}//end enabledErrors()

	/**
	 * Validate the optional `tools` object: closed verb-key set, per-verb shape.
	 *
	 * @param array<string, mixed> $annotation The x-openregister-mcp block.
	 * @param array<string, mixed> $properties The schema's declared properties (for `filters` cross-checks).
	 *
	 * @return array<int, array{code: string, message: string}> The errors of the tools and their verbs.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Scenario: Unknown verb key is rejected; Scenario: The verb set is closed)
	 */
	private function toolsErrors(array $annotation, array $properties): array {
		if (array_key_exists('tools', $annotation) === false) {
			return [];
		}

		if (is_array($annotation['tools']) === false) {
			return [['code' => 'mcp-bad-tools', 'message' => 'x-openregister-mcp `tools` must be an object.']];
		}
		$errors = [];
		foreach ($annotation['tools'] as $verb => $config) {
			$errors = array_merge($errors, (new McpVerbAnnotation(verb: (string)$verb, config: $config, properties: $properties))->errors());
		}

		return $errors;
	}//end toolsErrors()
}//end class

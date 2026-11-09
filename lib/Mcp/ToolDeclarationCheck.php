<?php

/**
 * Closed-vocabulary checks of a #[McpTool] declaration
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator;
use OCA\OpenRegister\Service\Capability\ToolReachLevels;

/**
 * Finds the first closed-vocabulary declaration on a `#[McpTool]` that holds
 * a value outside its vocabulary.
 *
 * `scope` is checked against {@see McpAnnotationValidator::SCOPES}
 * (REQ-ATTR-005) and `reach` against {@see ToolReachLevels::ORDER}
 * (REQ-ATTR-006), and every `annotations` entry must be a string key
 * holding a scalar (REQ-ATTR-007). A misspelt reach would otherwise resolve
 * to `external` in silence: safe, but the author would never learn why the
 * tool keeps asking for a grant, so the scanner refuses it loudly instead.
 */
final class ToolDeclarationCheck {

	/**
	 * The offending declaration, or null when every declared one is valid.
	 *
	 * @param McpTool $attribute The resolved attribute instance.
	 *
	 * @return array{key: string, value: string, allowed: list<string>}|null The offending declaration.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-006 — A curated attribute tool declares its reach)
	 */
	public static function unrecognised(McpTool $attribute): ?array {
		if (self::outside(value: $attribute->scope, allowed: McpAnnotationValidator::SCOPES) === true) {
			return ['key' => 'scope', 'value' => (string) $attribute->scope, 'allowed' => McpAnnotationValidator::SCOPES];
		}

		if (self::outside(value: $attribute->reach, allowed: ToolReachLevels::ORDER) === true) {
			return ['key' => 'reach', 'value' => (string) $attribute->reach, 'allowed' => ToolReachLevels::ORDER];
		}

		return self::annotationProblem(annotations: $attribute->annotations);
	}//end unrecognised()

	/**
	 * True when a value is declared and not in its vocabulary.
	 *
	 * @param string|null  $value   The declared value, or null when undeclared.
	 * @param list<string> $allowed The vocabulary.
	 *
	 * @return bool
	 */
	private static function outside(?string $value, array $allowed): bool {
		return $value !== null && in_array($value, $allowed, true) === false;
	}//end outside()

	/**
	 * The offending annotations declaration, or null when the map is well formed.
	 *
	 * @param array<mixed> $annotations The declared map.
	 *
	 * @return array{key: string, value: string, allowed: list<string>}|null
	 */
	private static function annotationProblem(array $annotations): ?array {
		$badAnnotation = self::malformedAnnotation(annotations: $annotations);
		if ($badAnnotation === null) {
			return null;
		}

		return [
			'key' => 'annotations',
			'value' => $badAnnotation,
			'allowed' => [
				'non-empty string key => scalar value, other than the MCP hint keys '
				. implode(', ', AttributeToolScanner::RESERVED_ANNOTATION_KEYS),
			],
		];
	}//end annotationProblem()

	/**
	 * The first entry of a declared annotations map that is not a non-empty
	 * string key holding a scalar, or that uses one of the MCP specification's
	 * reserved hint keys, or null when the whole map is well formed.
	 *
	 * The map is free-form on purpose (OpenRegister reads none of it), but
	 * not shapeless: a nested array or an integer key would reach a consumer
	 * as something it cannot compare against a mark, and the tool would
	 * silently never qualify. Refusing it at scan time names the mistake. The
	 * map is forwarded to external MCP clients under `annotations`, the key the
	 * specification uses for tool hints, so a hint key in it (`readOnlyHint` on
	 * a write tool) would be trusted by a client that auto-approves read-only
	 * tools while OpenRegister's own checks say otherwise; those keys are
	 * refused too, compared case-insensitively.
	 *
	 * @param array<mixed> $annotations The declared map.
	 *
	 * @return string|null The offending key, or null.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-007 — A curated attribute tool forwards free-form annotations)
	 */
	private static function malformedAnnotation(array $annotations): ?string {
		foreach ($annotations as $key => $value) {
			if (self::isMalformedEntry(key: $key, value: $value) === true) {
				return (string) $key;
			}
		}

		return null;
	}//end malformedAnnotation()

	/**
	 * Whether one annotations entry is malformed or uses a reserved key.
	 *
	 * @param mixed $key   The entry key.
	 * @param mixed $value The entry value.
	 *
	 * @return bool
	 */
	private static function isMalformedEntry(mixed $key, mixed $value): bool {
		return is_string($key) === false
			|| trim($key) === ''
			|| is_scalar($value) === false
			|| self::isReservedKey(key: $key) === true;
	}//end isMalformedEntry()

	/**
	 * Whether a key is one the MCP specification reserves for tool hints.
	 *
	 * @param string $key The annotation key.
	 *
	 * @return bool
	 */
	private static function isReservedKey(string $key): bool {
		return in_array(strtolower(trim($key)), array_map('strtolower', AttributeToolScanner::RESERVED_ANNOTATION_KEYS), true);
	}//end isReservedKey()
}//end class

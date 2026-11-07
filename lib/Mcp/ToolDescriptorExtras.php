<?php

/**
 * Author-declared extras of an attributed MCP tool descriptor
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
use OCA\OpenRegister\Service\Capability\ToolReachResolver;
use ReflectionMethod;

/**
 * The descriptor keys an author declares on `#[McpTool]`: the MCP
 * 2025-11-25 annotation hints, the advisory `scope` (REQ-ATTR-005), the
 * grant-matrix taxonomy and reach, the free-form annotations and the title.
 *
 * Forwarded additively — a key is present ONLY when the author set it on the
 * attribute; nothing here ever infers or fabricates a value (an invented
 * `readOnlyHint: true` on an unannotated write tool would be a dangerous
 * lie). All of it is advisory metadata only — OpenRegister RBAC and the
 * owning method's own authorization remain the sole authoritative
 * invoke-time gate.
 */
final class ToolDescriptorExtras {

	/**
	 * Every declared extra, in descriptor order: hints, scope, taxonomy, title.
	 *
	 * @param McpTool          $attribute The resolved attribute instance.
	 * @param ReflectionMethod $method    The attributed method.
	 *
	 * @return array<string, mixed> The declared keys only.
	 */
	public static function of(McpTool $attribute, ReflectionMethod $method): array {
		return self::hints(attribute: $attribute)
			+ self::declared(key: 'scope', value: $attribute->scope)
			+ self::taxonomy(attribute: $attribute)
			+ self::title(attribute: $attribute, method: $method);
	}//end of()

	/**
	 * The declared boolean hints, in {@see McpAnnotationValidator::HINT_KEYS} order.
	 *
	 * @param McpTool $attribute The resolved attribute instance.
	 *
	 * @return array<string, bool> The declared hints.
	 */
	private static function hints(McpTool $attribute): array {
		$hints = [];
		foreach (McpAnnotationValidator::HINT_KEYS as $hintKey) {
			$hintValue = self::hintValue(attribute: $attribute, hintKey: $hintKey);
			if ($hintValue !== null) {
				$hints[$hintKey] = $hintValue;
			}
		}

		return $hints;
	}//end hints()

	/**
	 * Read one boolean hint property off the attribute by its
	 * {@see McpAnnotationValidator::HINT_KEYS} name.
	 *
	 * @param McpTool $attribute The resolved attribute instance.
	 * @param string  $hintKey   One of `readOnlyHint`/`destructiveHint`/`idempotentHint`.
	 *
	 * @return bool|null The declared hint value, or null when the author did not set it.
	 */
	private static function hintValue(McpTool $attribute, string $hintKey): ?bool {
		return match ($hintKey) {
			'readOnlyHint' => $attribute->readOnlyHint,
			'destructiveHint' => $attribute->destructiveHint,
			'idempotentHint' => $attribute->idempotentHint,
			default => null,
		};
	}//end hintValue()

	/**
	 * The grant-matrix taxonomy (subject, action), the declared reach and
	 * the free-form annotations, in that descriptor order.
	 *
	 * Never inferred from the method name. `ToolRegistryFacade::describeTools()`
	 * makes the same choice downstream, and for the same reason — an inferred
	 * subject is indistinguishable from a declared one, so a consumer handed
	 * one cannot know whether to trust it.
	 *
	 * ⚠️ The consequence is that an OMISSION HAS NO SYMPTOM: the tool simply
	 * arrives at the grant matrix ungroupable, with nothing failing anywhere.
	 * Apps are expected to pin this with a test, the way hermiq's provider
	 * does.
	 *
	 * The reach was already validated against ToolReachResolver::ORDER at
	 * scan time (REQ-ATTR-006) and the annotations at REQ-ATTR-007; an empty
	 * annotations map is no declaration and is not forwarded.
	 *
	 * @param McpTool $attribute The resolved attribute instance.
	 *
	 * @return array<string, mixed> The declared taxonomy keys only.
	 */
	private static function taxonomy(McpTool $attribute): array {
		$annotations = [];
		if ($attribute->annotations !== []) {
			$annotations = ['annotations' => $attribute->annotations];
		}

		return self::declaredText(key: 'subject', value: $attribute->subject)
			+ self::declared(key: ToolReachResolver::REACH_KEY, value: $attribute->reach)
			+ self::declaredText(key: 'action', value: $attribute->action)
			+ $annotations;
	}//end taxonomy()

	/**
	 * The tool's human title (`#[McpToolTitle]`), as the descriptor's top-level `title`, or an empty array.
	 *
	 * A blank title is no declaration. The `annotations` map never carries it.
	 *
	 * @param McpTool          $attribute The method's #[McpTool].
	 * @param ReflectionMethod $method    The attributed method.
	 *
	 * @return array{title?: string} The declared title only.
	 */
	private static function title(McpTool $attribute, ReflectionMethod $method): array {
		return self::declaredText(key: 'title', value: trim((string)$attribute->titleOn(method: $method)));
	}//end title()

	/**
	 * A one-key array when the value is declared, else an empty array.
	 *
	 * @param string      $key   The descriptor key.
	 * @param string|null $value The declared value.
	 *
	 * @return array<string, string>
	 */
	private static function declared(string $key, ?string $value): array {
		if ($value === null) {
			return [];
		}

		return [$key => $value];
	}//end declared()

	/**
	 * A one-key array when the value is declared and not blank, else an empty array.
	 *
	 * @param string      $key   The descriptor key.
	 * @param string|null $value The declared value.
	 *
	 * @return array<string, string>
	 */
	private static function declaredText(string $key, ?string $value): array {
		if ($value === null || trim($value) === '') {
			return [];
		}

		return [$key => $value];
	}//end declaredText()
}//end class

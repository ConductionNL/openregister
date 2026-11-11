<?php

/**
 * One validation of a case-plan definition, or of one ad-hoc item.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Exception\CaseValidationException;

/**
 * A single validation pass, built by CasePlanDefinition for one definition
 * (or one ad-hoc item) and dropped after it: it collects the keys seen so far
 * and the stages' flow bindings while it walks the tree.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
 */
final class CasePlanValidation {

	/**
	 * What an item key may look like: something a sentry can name and a URL
	 * can carry.
	 */
	private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/';

	/**
	 * Keys seen so far in this plan.
	 *
	 * @var array<string, bool>
	 */
	private array $keys = [];

	/**
	 * Stage flow bindings collected, by stage key.
	 *
	 * @var array<string, string>
	 */
	private array $flows = [];

	/**
	 * Start a pass.
	 *
	 * @param CaseSentryEvaluator $sentries Validates criteria at save time.
	 */
	public function __construct(
		private readonly CaseSentryEvaluator $sentries,
	) {
	}//end __construct()

	/**
	 * Validate a whole definition; return it normalised with `settings.flows`
	 * collected from the stages' `flow` bindings.
	 *
	 * @param array<string, mixed> $definition The definition as submitted.
	 *
	 * @return array{settings: array<string, mixed>, items: array<int, array<string, mixed>>} The normalised definition.
	 *
	 * @throws CaseValidationException On the first refused element.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	public function definition(array $definition): array {
		$items = ($definition['items'] ?? null);
		if (is_array($items) === false || $items === []) {
			throw new CaseValidationException(message: 'A case-plan definition needs a non-empty `items` list.');
		}

		$settings = $this->settingsOf(definition: $definition);
		$normalised = $this->validateLevel(nodes: $items, prefix: 'items');
		if ($this->flows !== []) {
			$settings['flows'] = $this->flows;
		}

		return ['settings' => $settings, 'items' => $normalised];
	}//end definition()

	/**
	 * Validate one node, of a definition or attached ad hoc, and its children.
	 *
	 * @param mixed  $node The node as submitted.
	 * @param string $path Where it is, for messages.
	 *
	 * @return array<string, mixed> The normalised node.
	 *
	 * @throws CaseValidationException On the first refused element.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	public function validateNode(mixed $node, string $path): array {
		$key = $this->nodeKey(node: $node, path: $path);
		$node['key'] = $key;
		$checked = new CaseDefinitionNode(node: $node, path: $path);
		$this->validateGuards(node: $node, key: $key);
		$checked->validateTypeRules();
		$flow = $checked->boundFlow();
		if ($flow !== '') {
			$this->flows[$key] = $flow;
		}

		$children = $checked->stageChildren(flow: $flow);
		$node['children'] = $this->validateLevel(nodes: $children, prefix: $path . '.children');

		return $node;
	}//end validateNode()

	/**
	 * The plan settings of a definition, refused unless every part has its shape.
	 *
	 * @param array<string, mixed> $definition The definition as submitted.
	 *
	 * @return array<string, mixed> The settings as submitted.
	 *
	 * @throws CaseValidationException When a part is malformed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function settingsOf(array $definition): array {
		$settings = ($definition['settings'] ?? []);
		if (is_array($settings) === false) {
			throw new CaseValidationException(message: '`settings` must be an object.');
		}

		$this->validateRuleList(rules: ($settings['authorization'] ?? null), where: 'settings.authorization');
		$this->validateSettingsFields(settings: $settings);

		return $settings;
	}//end settingsOf()

	/**
	 * The result names and the write-through field map, when present, have their shape.
	 *
	 * @param array<string, mixed> $settings The plan settings.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When either is malformed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function validateSettingsFields(array $settings): void {
		if (isset($settings['results']) === true && $this->isStringList(value: $settings['results']) === false) {
			throw new CaseValidationException(message: '`settings.results` must be a list of result names.');
		}

		if (isset($settings['writeThrough']) === true && is_array($settings['writeThrough']) === false) {
			throw new CaseValidationException(message: '`settings.writeThrough` must be an object of field names.');
		}
	}//end validateSettingsFields()

	/**
	 * A node's key: present, well-formed and not seen before in this plan.
	 *
	 * @param mixed  $node The node as submitted.
	 * @param string $path Where it is, for messages.
	 *
	 * @return string The trimmed key, now seen.
	 *
	 * @throws CaseValidationException When the node is not an object or its key is refused.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	private function nodeKey(mixed $node, string $path): string {
		if (is_array($node) === false) {
			throw new CaseValidationException(message: sprintf('%s must be an object.', $path));
		}

		$key = trim((string)($node['key'] ?? ''));
		if ($key === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
			throw new CaseValidationException(message: sprintf('%s needs a `key` of letters, digits, `_`, `.` or `-` (max 128).', $path));
		}

		if (isset($this->keys[$key]) === true) {
			throw new CaseValidationException(message: sprintf("%s repeats item key '%s'; keys are unique within a plan.", $path, $key));
		}

		$this->keys[$key] = true;

		return $key;
	}//end nodeKey()

	/**
	 * A node's criteria, authorization rules and repetition rule have their shape.
	 *
	 * @param array<string, mixed> $node The node.
	 * @param string               $key  Its key.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException On the first malformed one.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	private function validateGuards(array $node, string $key): void {
		$this->sentries->validateCriteria(criteria: ($node['entryCriteria'] ?? null), where: sprintf("'%s' entry", $key));
		$this->sentries->validateCriteria(criteria: ($node['exitCriteria'] ?? null), where: sprintf("'%s' exit", $key));
		$this->validateRuleList(rules: ($node['authorization'] ?? null), where: sprintf("'%s'.authorization", $key));

		$repetition = ($node['repetition'] ?? null);
		if ($repetition !== null && (is_array($repetition) === false || (int)($repetition['max'] ?? 0) < 1)) {
			throw new CaseValidationException(message: sprintf("'%s'.repetition must be {\"max\": N} with N >= 1.", $key));
		}
	}//end validateGuards()

	/**
	 * Validate one level of nodes in order: the plan's items, or a stage's children.
	 *
	 * @param array<int|string, mixed> $nodes  The nodes as submitted.
	 * @param string                   $prefix The level's path (`items`, or a stage's path plus `.children`), for messages.
	 *
	 * @return array<int, array<string, mixed>> The normalised nodes.
	 *
	 * @throws CaseValidationException On the first refused element.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	private function validateLevel(array $nodes, string $prefix): array {
		$normalised = [];
		foreach ($nodes as $index => $node) {
			$normalised[] = $this->validateNode(node: $node, path: sprintf('%s[%d]', $prefix, (int)$index));
		}

		return $normalised;
	}//end validateLevel()

	/**
	 * An authorization rule list is a list of non-empty strings, or absent.
	 *
	 * @param mixed  $rules The list.
	 * @param string $where For the message.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When malformed.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function validateRuleList(mixed $rules, string $where): void {
		if ($rules === null) {
			return;
		}

		if ($this->isStringList(value: $rules) === false) {
			throw new CaseValidationException(
				message: sprintf('%s must be a list of group ids, `user:<uid>` or `role:<name>` entries.', $where)
			);
		}
	}//end validateRuleList()

	/**
	 * Whether a value is a list of non-empty strings.
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean True for a (possibly empty) list of non-empty strings.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	private function isStringList(mixed $value): bool {
		if (is_array($value) === false) {
			return false;
		}

		foreach ($value as $entry) {
			if (is_string($entry) === false || trim($entry) === '') {
				return false;
			}
		}

		return true;
	}//end isStringList()
}//end class

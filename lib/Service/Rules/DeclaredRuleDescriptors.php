<?php

/**
 * OpenRegister DeclaredRuleDescriptors
 *
 * Reads the rules a schema annotation declares into inventory descriptors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * The calculations, state field blocks and transition conditions of one schema, as descriptors.
 *
 * Built per read by {@see RuleInventoryService}: the descriptors are a
 * projection of the annotations and nothing here stores anything.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class DeclaredRuleDescriptors {

	/**
	 * Reads a declaration's scope.
	 *
	 * @var RuleScope
	 */
	private readonly RuleScope $scopes;

	/**
	 * Constructor.
	 *
	 * @param string $slug The slug of the schema the rules are declared on.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $slug,
	) {
		$this->scopes = new RuleScope();
	}//end __construct()

	/**
	 * The declared calculations, each one rule.
	 *
	 * @param array<string, mixed> $merged The one calculations map, property calculations lifted in.
	 *
	 * @return array<int, RuleDescriptor> The descriptors.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function calculations(array $merged): array {
		$calculations = [];
		foreach (array_filter($merged, 'is_array') as $name => $declaration) {
			$calculations[] = $this->calculation(name: (string)$name, declaration: $declaration);
		}

		return $calculations;
	}//end calculations()

	/**
	 * The per-state field blocks, one rule per state that declares one.
	 *
	 * The block is the vocabulary `field-rules-by-state` adds. A schema saved
	 * before that change declares none, and this returns nothing for it rather
	 * than inventing an entry, which is the projection property working.
	 *
	 * @param mixed $states The lifecycle's `states` block.
	 *
	 * @return array<int, RuleDescriptor> The descriptors.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function stateFieldRules(mixed $states): array {
		$blocks = [];
		foreach ($this->mapOf(value: $states) as $state => $block) {
			if (is_array($block) === true && is_array(($block['fields'] ?? null)) === true) {
				$blocks[] = $this->stateFieldRule(state: (string)$state, block: $block);
			}
		}

		return $blocks;
	}//end stateFieldRules()

	/**
	 * The transitions that guard themselves with a condition.
	 *
	 * A transition with no condition is not a rule: it is a move that is always
	 * allowed, and listing it would fill the inventory with entries that can
	 * never refuse anything and never appear in the run log.
	 *
	 * @param mixed $transitions The lifecycle's `transitions` block.
	 *
	 * @return array<int, RuleDescriptor> The descriptors.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function lifecycleConditions(mixed $transitions): array {
		$guarded = [];
		foreach ($this->mapOf(value: $transitions) as $name => $spec) {
			if (is_array($spec) === true && ($spec['condition'] ?? null) !== null) {
				$guarded[] = $this->lifecycleCondition(name: (string)$name, spec: $spec);
			}
		}

		return $guarded;
	}//end lifecycleConditions()

	/**
	 * One declared calculation as a rule.
	 *
	 * @param string               $name        The calculation.
	 * @param array<string, mixed> $declaration Its declaration.
	 *
	 * @return RuleDescriptor The descriptor.
	 */
	private function calculation(string $name, array $declaration): RuleDescriptor {
		return $this->describe(
			facts: [
				'kind' => RuleVocabulary::KIND_CALCULATION,
				'key' => $name,
				'label' => $name,
				'source' => ('x-openregister-calculations.' . $name),
				'actions' => [RuleVocabulary::ACTION_SET_VALUE],
				'condition' => ($declaration['expression'] ?? null),
			],
			declaration: $declaration
		);
	}//end calculation()

	/**
	 * One state's field block as a rule.
	 *
	 * @param string               $state The state.
	 * @param array<string, mixed> $block The state's block, carrying `fields`.
	 *
	 * @return RuleDescriptor The descriptor.
	 */
	private function stateFieldRule(string $state, array $block): RuleDescriptor {
		return $this->describe(
			facts: [
				'kind' => RuleVocabulary::KIND_STATE_FIELD_RULE,
				'key' => $state,
				'label' => $state,
				'source' => ('x-openregister-lifecycle.states.' . $state . '.fields'),
				'actions' => RuleVocabulary::KINDS[RuleVocabulary::KIND_STATE_FIELD_RULE]['actions'],
				'condition' => ($block['condition'] ?? null),
			],
			declaration: $block
		);
	}//end stateFieldRule()

	/**
	 * One guarded transition as a rule.
	 *
	 * @param string               $name The transition.
	 * @param array<string, mixed> $spec The transition, carrying a condition.
	 *
	 * @return RuleDescriptor The descriptor.
	 */
	private function lifecycleCondition(string $name, array $spec): RuleDescriptor {
		return $this->describe(
			facts: [
				'kind' => RuleVocabulary::KIND_LIFECYCLE_CONDITION,
				'key' => $name,
				'label' => (string)($spec['label'] ?? $name),
				'source' => ('x-openregister-lifecycle.transitions.' . $name . '.condition'),
				'actions' => [RuleVocabulary::ACTION_REFUSE_TRANSITION],
				'condition' => $spec['condition'],
			],
			declaration: $spec
		);
	}//end lifecycleCondition()

	/**
	 * A descriptor from what its kind says and what its declaration carries.
	 *
	 * @param array{kind: string, key: string, label: string, source: string, actions: array<int, string>, condition: mixed} $facts What the kind reads.
	 * @param array<string, mixed> $declaration The declaration: its switch, ceiling and scope.
	 *
	 * @return RuleDescriptor The descriptor.
	 */
	private function describe(array $facts, array $declaration): RuleDescriptor {
		return new RuleDescriptor(
			kind: $facts['kind'],
			schemaSlug: $this->slug,
			key: $facts['key'],
			label: $facts['label'],
			source: $facts['source'],
			enabled: ($declaration['enabled'] ?? true) !== false,
			actions: $facts['actions'],
			condition: $facts['condition'],
			maxObjects: $this->ceilingOf(declaration: $declaration),
			scope: $this->scopes->fromDeclaration(declaration: $declaration)
		);
	}//end describe()

	/**
	 * A lifecycle block as a map, or nothing when it is not one.
	 *
	 * @param mixed $value The block as declared.
	 *
	 * @return array<mixed, mixed> The block, or an empty array.
	 */
	private function mapOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end mapOf()

	/**
	 * The ceiling a declaration carries, when it carries a usable one.
	 *
	 * A ceiling of zero or less is not a ceiling, it is a rule that can never
	 * run, so it is read as no ceiling rather than as a silent shutdown.
	 *
	 * @param array<string, mixed> $declaration The rule's declaration.
	 *
	 * @return int|null The ceiling, or null when none is declared.
	 */
	private function ceilingOf(array $declaration): ?int {
		$ceiling = ($declaration['maxObjects'] ?? null);
		if (is_int($ceiling) === false || $ceiling <= 0) {
			return null;
		}

		return $ceiling;
	}//end ceilingOf()
}//end class

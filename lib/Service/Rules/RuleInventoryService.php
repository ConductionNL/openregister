<?php

/**
 * OpenRegister RuleInventoryService
 *
 * Every rule that can act on a schema's objects, in the order the save
 * pipeline evaluates them.
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

use DateTime;
use OCA\OpenRegister\Db\RuleRunSummaryMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;

/**
 * The inventory, derived on every read and stored nowhere.
 *
 * D-1. A rules table an administrator maintains beside the annotations is two
 * sources of truth and one of them drifts. So this reads the four places a rule
 * actually lives (the calculations annotation, the lifecycle states block, the
 * lifecycle transitions block and the flow trigger index), sorts them by the
 * order the save pipeline evaluates the KINDS, and merges each rule's own
 * summary row on top. A rule that is not declared cannot appear in the list,
 * and a rule that is declared cannot be missing from it.
 *
 * The summary is the only thing here that is stored, and it is a projection
 * too: it is written by evaluations, never by an author, so a summary for a
 * rule that has since been deleted simply finds no descriptor to attach to.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The inventory reads the four places a
 *   rule is declared plus the summary store; each collaborator is one of those places and
 *   folding any of them away would mean this class reading the tables itself.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleInventoryService implements RuleInventoryServiceInterface {

	/**
	 * Applies the inventory's scope filter.
	 *
	 * @var RuleScope
	 */
	private readonly RuleScope $scopes;

	/**
	 * Lifts a property's own calculation into the one map; stateless, so it is built in place.
	 *
	 * @var PropertyCalculations
	 */
	private readonly PropertyCalculations $propertyCalculations;

	/**
	 * The published kinds, verdicts and actions; stateless, so it is built in place.
	 *
	 * @var RuleVocabulary
	 */
	private readonly RuleVocabulary $vocabulary;

	/**
	 * Constructor.
	 *
	 * @param RuleRunSummaryMapperInterface $summaries Last run and last error per rule.
	 * @param FlowRuleDescriptors $flows Reads the flows a schema's objects trigger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleRunSummaryMapperInterface $summaries,
		private readonly FlowRuleDescriptors $flows,
	) {
		$this->scopes = new RuleScope();
		$this->propertyCalculations = new PropertyCalculations();
		$this->vocabulary = new RuleVocabulary();
	}//end __construct()

	/**
	 * Every rule declared on a schema, in evaluation order.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array<int, RuleDescriptor> The descriptors, in the order the pipeline evaluates them.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function describe(Schema $schema): array {
		$slug = (string)($schema->getSlug() ?? '');
		$configuration = ($schema->getConfiguration() ?? []);
		$lifecycle = ($configuration['x-openregister-lifecycle'] ?? []);
		$declared = new DeclaredRuleDescriptors(slug: $slug);

		$descriptors = array_merge(
			$declared->calculations(merged: $this->calculationsOf(configuration: $configuration, properties: ($schema->getProperties() ?? []))),
			$declared->stateFieldRules(states: ($lifecycle['states'] ?? [])),
			$declared->lifecycleConditions(transitions: ($lifecycle['transitions'] ?? [])),
			$this->flows->describe(slug: $slug)
		);
		usort($descriptors, $this->inEvaluationOrder(...));

		return $descriptors;
	}//end describe()

	/**
	 * The inventory as the API returns it: descriptors with their summaries.
	 *
	 * @param Schema               $schema  The schema to read.
	 * @param RuleInventoryOptions $options The idle window, the moment it is measured from and the scope filter.
	 *
	 * @return array<int, array<string, mixed>> The inventory rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function inventory(Schema $schema, RuleInventoryOptions $options = new RuleInventoryOptions()): array {
		$summaries = $this->summaries->findBySchema(schemaSlug: (string)($schema->getSlug() ?? ''));
		$window = new RuleInventoryWindow(idleDays: $options->idleDays, now: ($options->now ?? new DateTime()));
		$rowOf = static fn (RuleDescriptor $rule): array => $window->rowOf(descriptor: $rule, summary: ($summaries[$rule->getId()] ?? null));

		return array_map($rowOf, $this->admitted(schema: $schema, filter: $options->scope));
	}//end inventory()

	/**
	 * The rules of a schema a scope filter admits, in evaluation order.
	 *
	 * @param Schema $schema The schema to read.
	 * @param array<string, mixed> $filter Property => value: only the rules with that scope, plus those without one.
	 *
	 * @return array<int, RuleDescriptor> The admitted descriptors.
	 */
	private function admitted(Schema $schema, array $filter): array {
		$admitted = [];
		foreach ($this->describe(schema: $schema) as $descriptor) {
			if ($this->scopes->admits(scope: $descriptor->getScope(), filter: $filter) === true) {
				$admitted[] = $descriptor;
			}
		}

		return $admitted;
	}//end admitted()

	/**
	 * One rule of a schema, by its derived id.
	 *
	 * @param Schema $schema The schema to read.
	 * @param string $ruleId The derived rule id.
	 *
	 * @return RuleDescriptor|null The descriptor, or null when the schema declares no such rule.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function find(Schema $schema, string $ruleId): ?RuleDescriptor {
		foreach ($this->describe(schema: $schema) as $descriptor) {
			if ($descriptor->getId() === $ruleId) {
				return $descriptor;
			}
		}

		return null;
	}//end find()

	/**
	 * Each named condition of a schema, with the rules that name it directly (task 1.4b).
	 *
	 * Every rule in the inventory and every administered validation is read
	 * for `$condition` references, keyed by the same rule id the run log and
	 * the routes use. Direct references only, as {@see NamedConditionLibrary::usage()}
	 * answers them: a condition that merely composes another is not a rule.
	 *
	 * A name a rule references but the schema does not declare is listed too,
	 * with `declared: false`: such a rule refuses at evaluation, and the
	 * inventory is where an administrator looks for why.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array<int, array{name: string, description: string, declared: bool, usedBy: array<int, string>}> One row per condition, by name.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md#requirement-a-condition-is-named-once-and-used-inside-other-rules-req-rct-001
	 */
	public function namedConditions(Schema $schema): array {
		$configuration = ($schema->getConfiguration() ?? []);
		$library = new NamedConditionLibrary();
		$declared = $library->libraryFrom(annotation: $this->annotationOf(configuration: $configuration, key: NamedConditionLibrary::ANNOTATION));
		$usage = $library->usage(usingNodes: $this->conditionNodes(schema: $schema, configuration: $configuration));

		$rows = [];
		foreach (array_unique(array_merge(array_keys($declared), array_keys($usage))) as $name) {
			$rows[(string)$name] = [
				'name' => (string)$name,
				'description' => ($declared[$name]['description'] ?? ''),
				'declared' => array_key_exists($name, $declared),
				'usedBy' => ($usage[$name] ?? []),
			];
		}

		ksort($rows);
		return array_values($rows);
	}//end namedConditions()

	/**
	 * The condition of every rule and administered validation, by rule id.
	 *
	 * @param Schema               $schema        The schema to read.
	 * @param array<string, mixed> $configuration The schema's configuration block.
	 *
	 * @return array<string, mixed> Condition node by rule id.
	 */
	private function conditionNodes(Schema $schema, array $configuration): array {
		$nodes = [];
		foreach ($this->describe(schema: $schema) as $descriptor) {
			if ($descriptor->getCondition() !== null) {
				$nodes[$descriptor->getId()] = $descriptor->getCondition();
			}
		}

		$slug = (string)($schema->getSlug() ?? '');
		foreach ($this->annotationOf(configuration: $configuration, key: AdministeredValidations::ANNOTATION) ?? [] as $key => $validation) {
			if (is_array($validation) === true && array_key_exists('condition', $validation) === true) {
				$id = RuleDescriptor::idFor(kind: RuleVocabulary::KIND_ADMINISTERED_VALIDATION, schemaSlug: $slug, key: (string)$key);
				$nodes[$id] = $validation['condition'];
			}
		}

		return $nodes;
	}//end conditionNodes()

	/**
	 * One annotation of a configuration block, or null when it is absent or not an object.
	 *
	 * @param array<string, mixed> $configuration The schema's configuration block.
	 * @param string               $key           The annotation key.
	 *
	 * @return array<string, mixed>|null The annotation.
	 */
	private function annotationOf(array $configuration, string $key): ?array {
		$annotation = ($configuration[$key] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		return $annotation;
	}//end annotationOf()

	/**
	 * The one calculations map: the annotation with every property calculation lifted in.
	 *
	 * The same merge the save listener runs, so an authored property
	 * calculation and a hand-written annotation appear once each and in the
	 * same place the pipeline will find them.
	 *
	 * @param array<string, mixed> $configuration The schema's configuration block.
	 * @param array<string, mixed> $properties The schema's properties map.
	 *
	 * @return array<string, mixed> The merged calculations.
	 */
	private function calculationsOf(array $configuration, array $properties): array {
		$annotation = ($configuration['x-openregister-calculations'] ?? []);
		if (is_array($annotation) === false) {
			$annotation = [];
		}

		return $this->propertyCalculations->merge(annotation: $annotation, properties: $properties);
	}//end calculationsOf()

	/**
	 * Order two rules the way the pipeline evaluates them: by kind, then by key.
	 *
	 * @param RuleDescriptor $left The one rule.
	 * @param RuleDescriptor $right The other.
	 *
	 * @return int The comparison.
	 */
	private function inEvaluationOrder(RuleDescriptor $left, RuleDescriptor $right): int {
		$byKind = ($this->vocabulary->orderOf(kind: $left->getKind()) <=> $this->vocabulary->orderOf(kind: $right->getKind()));
		if ($byKind !== 0) {
			return $byKind;
		}

		return strcmp($left->getKey(), $right->getKey());
	}//end inEvaluationOrder()
}//end class

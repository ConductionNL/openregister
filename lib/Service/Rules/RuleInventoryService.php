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
	 * @param Schema $schema The schema to read.
	 * @param int $idleDays How long a rule may go unevaluated before it is flagged.
	 * @param DateTime|null $now The moment to measure the idle window from.
	 * @param array<string, mixed> $scope Property => value: only the rules with that scope, plus those without one.
	 *
	 * @return array<int, array<string, mixed>> The inventory rows.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function inventory(Schema $schema, int $idleDays = self::DEFAULT_IDLE_DAYS, ?DateTime $now = null, array $scope = []): array {
		$summaries = $this->summaries->findBySchema(schemaSlug: (string)($schema->getSlug() ?? ''));
		$window = new RuleInventoryWindow(idleDays: $idleDays, now: ($now ?? new DateTime()));
		$rowOf = static fn (RuleDescriptor $rule): array => $window->rowOf(descriptor: $rule, summary: ($summaries[$rule->getId()] ?? null));

		return array_map($rowOf, $this->admitted(schema: $schema, filter: $scope));
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

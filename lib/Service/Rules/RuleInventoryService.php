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
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use DateTime;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\RuleRunSummaryMapper;
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
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */
final class RuleInventoryService {

	/**
	 * How long a rule may go unevaluated before the inventory says so.
	 *
	 * Ninety days is the window the change names, and it is a default rather
	 * than a constant of nature: the caller may ask for another.
	 *
	 * @var integer
	 */
	public const DEFAULT_IDLE_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param FlowTriggerMapper $triggers The derived flow trigger index.
	 * @param RuleRunSummaryMapper $summaries Last run and last error per rule.
	 * @param PropertyCalculations $propertyCalculations Lifts a property's own calculation into the one map.
	 * @param RuleVocabulary $vocabulary The published kinds, verdicts and actions.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FlowTriggerMapper $triggers,
		private readonly RuleRunSummaryMapper $summaries,
		private readonly PropertyCalculations $propertyCalculations,
		private readonly RuleVocabulary $vocabulary,
	) {
	}//end __construct()

	/**
	 * Every rule declared on a schema, in evaluation order.
	 *
	 * @param Schema $schema The schema to read.
	 *
	 * @return array<int, RuleDescriptor> The descriptors, in the order the pipeline evaluates them.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function describe(Schema $schema): array {
		$slug = (string)($schema->getSlug() ?? '');
		$configuration = ($schema->getConfiguration() ?? []);
		$properties = ($schema->getProperties() ?? []);

		$descriptors = array_merge(
			$this->calculations(slug: $slug, configuration: $configuration, properties: $properties),
			$this->stateFieldRules(slug: $slug, configuration: $configuration),
			$this->lifecycleConditions(slug: $slug, configuration: $configuration),
			$this->flows(slug: $slug)
		);

		usort(
			$descriptors,
			function (RuleDescriptor $left, RuleDescriptor $right): int {
				$byKind = ($this->vocabulary->orderOf(kind: $left->getKind()) <=> $this->vocabulary->orderOf(kind: $right->getKind()));
				if ($byKind !== 0) {
					return $byKind;
				}

				return strcmp($left->getKey(), $right->getKey());
			}
		);

		return $descriptors;
	}//end describe()

	/**
	 * The inventory as the API returns it: descriptors with their summaries.
	 *
	 * @param Schema $schema The schema to read.
	 * @param int $idleDays How long a rule may go unevaluated before it is flagged.
	 * @param DateTime|null $now The moment to measure the idle window from.
	 *
	 * @return array<int, array<string, mixed>> The inventory rows.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function inventory(Schema $schema, int $idleDays = self::DEFAULT_IDLE_DAYS, ?DateTime $now = null): array {
		$slug = (string)($schema->getSlug() ?? '');
		$summaries = $this->summaries->findBySchema(schemaSlug: $slug);
		$measuredAt = ($now ?? new DateTime());
		$cutoff = (clone $measuredAt)->modify('-' . max(1, $idleDays) . ' days');

		$rows = [];
		foreach ($this->describe(schema: $schema) as $descriptor) {
			$row = $descriptor->jsonSerialize();
			$summary = ($summaries[$descriptor->getId()] ?? null);

			$row['lastRun'] = null;
			$row['lastVerdict'] = null;
			$row['lastError'] = null;
			$row['lastErrorAt'] = null;
			// A rule that has never been evaluated has not run inside the
			// window either. Saying "unknown" here would hide exactly the rule
			// an administrator most wants to see: one that was written, saved
			// and has never once been reached.
			$row['ranInsideWindow'] = false;

			if ($summary !== null) {
				$row = array_merge($row, $summary->jsonSerialize());
				$row['id'] = $descriptor->getId();
				$row['schema'] = $slug;
				$lastRun = $summary->getLastRun();
				$row['ranInsideWindow'] = ($lastRun !== null && $lastRun >= $cutoff);
			}

			$row['idleWindowDays'] = max(1, $idleDays);
			$rows[] = $row;
		}

		return $rows;
	}//end inventory()

	/**
	 * One rule of a schema, by its derived id.
	 *
	 * @param Schema $schema The schema to read.
	 * @param string $ruleId The derived rule id.
	 *
	 * @return RuleDescriptor|null The descriptor, or null when the schema declares no such rule.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
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
	 * The declared calculations, each one rule.
	 *
	 * @param string $slug The schema's slug.
	 * @param array<string, mixed> $configuration The schema's configuration block.
	 * @param array<string, mixed> $properties The schema's properties map.
	 *
	 * @return array<int, RuleDescriptor> The descriptors.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function calculations(string $slug, array $configuration, array $properties): array {
		$annotation = ($configuration['x-openregister-calculations'] ?? []);
		if (is_array($annotation) === false) {
			$annotation = [];
		}

		// The same merge the save listener runs, so an authored property
		// calculation and a hand-written annotation appear once each and in the
		// same place the pipeline will find them.
		$merged = $this->propertyCalculations->merge(annotation: $annotation, properties: $properties);

		$descriptors = [];
		foreach ($merged as $name => $declaration) {
			if (is_array($declaration) === false) {
				continue;
			}

			$descriptors[] = new RuleDescriptor(
				kind: RuleVocabulary::KIND_CALCULATION,
				schemaSlug: $slug,
				key: (string)$name,
				label: (string)$name,
				source: ('x-openregister-calculations.' . (string)$name),
				enabled: ($declaration['enabled'] ?? true) !== false,
				actions: [RuleVocabulary::ACTION_SET_VALUE],
				condition: ($declaration['expression'] ?? null),
				maxObjects: $this->ceilingOf(declaration: $declaration)
			);
		}

		return $descriptors;
	}//end calculations()

	/**
	 * The per-state field blocks, one rule per state that declares one.
	 *
	 * The block is the vocabulary `field-rules-by-state` adds. A schema saved
	 * before that change declares none, and this returns nothing for it rather
	 * than inventing an entry, which is the projection property working.
	 *
	 * @param string $slug The schema's slug.
	 * @param array<string, mixed> $configuration The schema's configuration block.
	 *
	 * @return array<int, RuleDescriptor> The descriptors.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function stateFieldRules(string $slug, array $configuration): array {
		$states = ($configuration['x-openregister-lifecycle']['states'] ?? []);
		if (is_array($states) === false) {
			return [];
		}

		$descriptors = [];
		foreach ($states as $state => $block) {
			if (is_array($block) === false || is_array(($block['fields'] ?? null)) === false) {
				continue;
			}

			$descriptors[] = new RuleDescriptor(
				kind: RuleVocabulary::KIND_STATE_FIELD_RULE,
				schemaSlug: $slug,
				key: (string)$state,
				label: (string)$state,
				source: ('x-openregister-lifecycle.states.' . (string)$state . '.fields'),
				enabled: ($block['enabled'] ?? true) !== false,
				actions: RuleVocabulary::KINDS[RuleVocabulary::KIND_STATE_FIELD_RULE]['actions'],
				condition: ($block['condition'] ?? null),
				maxObjects: $this->ceilingOf(declaration: $block)
			);
		}

		return $descriptors;
	}//end stateFieldRules()

	/**
	 * The transitions that guard themselves with a condition.
	 *
	 * A transition with no condition is not a rule: it is a move that is always
	 * allowed, and listing it would fill the inventory with entries that can
	 * never refuse anything and never appear in the run log.
	 *
	 * @param string $slug The schema's slug.
	 * @param array<string, mixed> $configuration The schema's configuration block.
	 *
	 * @return array<int, RuleDescriptor> The descriptors.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function lifecycleConditions(string $slug, array $configuration): array {
		$transitions = ($configuration['x-openregister-lifecycle']['transitions'] ?? []);
		if (is_array($transitions) === false) {
			return [];
		}

		$descriptors = [];
		foreach ($transitions as $name => $spec) {
			if (is_array($spec) === false || ($spec['condition'] ?? null) === null) {
				continue;
			}

			$descriptors[] = new RuleDescriptor(
				kind: RuleVocabulary::KIND_LIFECYCLE_CONDITION,
				schemaSlug: $slug,
				key: (string)$name,
				label: (string)($spec['label'] ?? $name),
				source: ('x-openregister-lifecycle.transitions.' . (string)$name . '.condition'),
				enabled: ($spec['enabled'] ?? true) !== false,
				actions: [RuleVocabulary::ACTION_REFUSE_TRANSITION],
				condition: $spec['condition'],
				maxObjects: $this->ceilingOf(declaration: $spec)
			);
		}

		return $descriptors;
	}//end lifecycleConditions()

	/**
	 * The flows this schema's objects trigger.
	 *
	 * One descriptor per flow, not per trigger row: a flow subscribed to both
	 * create and update is one rule an administrator switches on or off, and
	 * the events it listens for belong on that one entry.
	 *
	 * @param string $slug The schema's slug.
	 *
	 * @return array<int, RuleDescriptor> The descriptors.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function flows(string $slug): array {
		$byFlow = [];
		foreach ($this->triggers->findBySchema(schemaSlug: $slug) as $row) {
			$uuid = $row['flow_uuid'];
			if (isset($byFlow[$uuid]) === false) {
				$byFlow[$uuid] = ['events' => [], 'enabled' => false];
			}

			$byFlow[$uuid]['events'][] = $row['event'];
			// A flow is on when ANY of its trigger rows is: the row carries the
			// flow's own enabled flag, denormalised, and a flow with one
			// disabled row and one enabled row still runs.
			$byFlow[$uuid]['enabled'] = ($byFlow[$uuid]['enabled'] === true || $row['enabled'] === true);
		}

		$descriptors = [];
		foreach ($byFlow as $uuid => $flow) {
			$events = array_values(array_unique($flow['events']));
			sort($events);

			$descriptors[] = new RuleDescriptor(
				kind: RuleVocabulary::KIND_FLOW,
				schemaSlug: $slug,
				key: (string)$uuid,
				label: (string)$uuid,
				source: ('openregister_flow_triggers.' . (string)$uuid),
				enabled: $flow['enabled'],
				actions: [RuleVocabulary::ACTION_RUN_FLOW],
				condition: ['events' => $events]
			);
		}

		return $descriptors;
	}//end flows()

	/**
	 * The ceiling a declaration carries, when it carries a usable one.
	 *
	 * A ceiling of zero or less is not a ceiling, it is a rule that can never
	 * run, so it is read as no ceiling rather than as a silent shutdown.
	 *
	 * @param array<string, mixed> $declaration The rule's declaration.
	 *
	 * @return int|null The ceiling, or null when none is declared.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function ceilingOf(array $declaration): ?int {
		$ceiling = ($declaration['maxObjects'] ?? null);
		if (is_int($ceiling) === false || $ceiling <= 0) {
			return null;
		}

		return $ceiling;
	}//end ceilingOf()
}//end class

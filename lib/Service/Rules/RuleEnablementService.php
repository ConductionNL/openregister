<?php

/**
 * OpenRegister RuleEnablementService
 *
 * Switches one declared rule off or on, where the rule is actually declared.
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use OCA\OpenRegister\Service\Flow\FlowService;
use Throwable;

/**
 * The switch writes to the declaration, never to a second store.
 *
 * D-1 says the inventory stores nothing of its own, and that has to hold for
 * the switch too: a table of "rules an administrator turned off" beside the
 * annotations would be the second source of truth the whole design refuses. So
 * disabling a calculation writes `enabled: false` into that calculation's own
 * declaration, disabling a transition condition writes it onto the transition,
 * and disabling a flow goes through the flow's own save, which is what keeps
 * the denormalised trigger index in step.
 *
 * That also means the switch is diffable, exportable and importable like every
 * other part of a schema, and that a schema copied to another instance arrives
 * with its rules in the state the administrator left them.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Switching a rule reaches the four
 *   places a rule is declared plus the audit entry; each collaborator owns one of them.
 */
final class RuleEnablementService {

	/**
	 * The refusal when the rule id names nothing the schema declares.
	 *
	 * @var string
	 */
	public const CODE_UNKNOWN_RULE = 'rule-unknown';

	/**
	 * The refusal when the switch could not be written.
	 *
	 * @var string
	 */
	public const CODE_NOT_WRITTEN = 'rule-switch-not-written';

	/**
	 * Constructor.
	 *
	 * @param RuleInventoryService $inventory Finds the rule as declared.
	 * @param SchemaMapper $schemaMapper Saves the schema carrying the switch.
	 * @param FlowService $flows Saves a flow's own enabled flag and reindexes it.
	 * @param RuleAuditService $audit Records who switched what.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleInventoryService $inventory,
		private readonly SchemaMapper $schemaMapper,
		private readonly FlowService $flows,
		private readonly RuleAuditService $audit,
	) {
	}//end __construct()

	/**
	 * Switch one rule of a schema.
	 *
	 * @param Schema $schema The schema the rule is declared on.
	 * @param string $ruleId The derived rule id.
	 * @param bool $enabled What to switch it to.
	 *
	 * @return array{ok: bool, rule?: array<string, mixed>, audit?: array<string, mixed>, error?: array{code: string, message: string}} The outcome.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function setEnabled(Schema $schema, string $ruleId, bool $enabled): array {
		$rule = $this->inventory->find(schema: $schema, ruleId: $ruleId);
		if ($rule === null) {
			return [
				'ok' => false,
				'error' => [
					'code' => self::CODE_UNKNOWN_RULE,
					'message' => sprintf('Schema "%s" declares no rule "%s".', (string)($schema->getSlug() ?? ''), $ruleId),
				],
			];
		}

		$audit = $this->audit->switched(rule: $rule, enabled: $enabled);

		try {
			$updated = $this->write(schema: $schema, rule: $rule, enabled: $enabled);
		} catch (Throwable $e) {
			return [
				'ok' => false,
				'error' => ['code' => self::CODE_NOT_WRITTEN, 'message' => $e->getMessage()],
			];
		}

		$after = $this->inventory->find(schema: $updated, ruleId: $ruleId);

		return [
			'ok' => true,
			'rule' => ($after?->jsonSerialize() ?? $rule->jsonSerialize()),
			'audit' => $audit,
		];
	}//end setEnabled()

	/**
	 * Write the switch into whichever declaration owns the rule.
	 *
	 * @param Schema $schema The schema the rule is declared on.
	 * @param RuleDescriptor $rule The rule as declared.
	 * @param bool $enabled What to switch it to.
	 *
	 * @return Schema The schema as it now stands.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function write(Schema $schema, RuleDescriptor $rule, bool $enabled): Schema {
		if ($rule->getKind() === RuleVocabulary::KIND_FLOW) {
			// A flow's enabled flag lives on the flow and is denormalised onto
			// every one of its trigger rows. Going through the flow's own save
			// is what keeps the index in step; writing the column here would
			// leave the hot path reading the old value.
			$this->flows->save(data: ['enabled' => $enabled], uuid: $rule->getKey());
			return $schema;
		}

		$configuration = ($schema->getConfiguration() ?? []);
		$properties = ($schema->getProperties() ?? []);
		$key = $rule->getKey();

		switch ($rule->getKind()) {
			case RuleVocabulary::KIND_CALCULATION:
				// A calculation is declared in one of two places and the switch
				// has to land in the one the author used, or the merge that
				// builds the one calculations map will read the old value back.
				if (isset($properties[$key][PropertyCalculations::PROPERTY_KEY]) === true) {
					$properties[$key][PropertyCalculations::PROPERTY_KEY]['enabled'] = $enabled;
					break;
				}

				$configuration['x-openregister-calculations'][$key]['enabled'] = $enabled;
				break;

			case RuleVocabulary::KIND_STATE_FIELD_RULE:
				$configuration['x-openregister-lifecycle']['states'][$key]['enabled'] = $enabled;
				break;

			case RuleVocabulary::KIND_LIFECYCLE_CONDITION:
			default:
				$configuration['x-openregister-lifecycle']['transitions'][$key]['enabled'] = $enabled;
				break;
		}

		return $this->schemaMapper->updateFromArray(
			id: (int)$schema->getId(),
			object: ['configuration' => $configuration, 'properties' => $properties]
		);
	}//end write()
}//end class

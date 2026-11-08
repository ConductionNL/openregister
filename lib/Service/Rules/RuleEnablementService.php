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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
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
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleEnablementService implements RuleEnablementServiceInterface {

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
	 * @param SchemaMapperInterface $schemaMapper Saves the schema carrying the switch.
	 * @param FlowService $flows Saves a flow's own enabled flag and reindexes it.
	 * @param RuleAuditService $audit Records who switched what.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleInventoryService $inventory,
		private readonly SchemaMapperInterface $schemaMapper,
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
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function setEnabled(Schema $schema, string $ruleId, bool $enabled): array {
		$rule = $this->inventory->find(schema: $schema, ruleId: $ruleId);
		if ($rule === null) {
			$message = sprintf('Schema "%s" declares no rule "%s".', (string)($schema->getSlug() ?? ''), $ruleId);
			return ['ok' => false, 'error' => ['code' => self::CODE_UNKNOWN_RULE, 'message' => $message]];
		}

		$audit = $this->audit->switched(rule: $rule, enabled: $enabled);
		try {
			$updated = $this->write(schema: $schema, rule: $rule, enabled: $enabled);
		} catch (Throwable $e) {
			return ['ok' => false, 'error' => ['code' => self::CODE_NOT_WRITTEN, 'message' => $e->getMessage()]];
		}

		$after = $this->inventory->find(schema: $updated, ruleId: $ruleId);

		return ['ok' => true, 'rule' => ($after?->jsonSerialize() ?? $rule->jsonSerialize()), 'audit' => $audit];
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
	 * @spec openspec/specs/flow-engine/spec.md
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

		return $this->schemaMapper->updateFromArray(
			id: (int)$schema->getId(),
			object: $this->switched(schema: $schema, rule: $rule, enabled: $enabled)
		);
	}//end write()

	/**
	 * The schema's configuration and properties with the switch written into the rule's declaration.
	 *
	 * @param Schema $schema The schema the rule is declared on.
	 * @param RuleDescriptor $rule The rule as declared; not a flow.
	 * @param bool $enabled What to switch it to.
	 *
	 * @return array{configuration: array<string, mixed>, properties: array<string, mixed>} The declarations to save.
	 */
	private function switched(Schema $schema, RuleDescriptor $rule, bool $enabled): array {
		$declarations = ['configuration' => ($schema->getConfiguration() ?? []), 'properties' => ($schema->getProperties() ?? [])];

		return $this->withFlag(tree: $declarations, path: $this->switchPath(declarations: $declarations, rule: $rule), enabled: $enabled);
	}//end switched()

	/**
	 * A tree with one flag written at the end of a path, creating the maps on the way.
	 *
	 * @param array<string, mixed> $tree The tree.
	 * @param array<int, string> $path The path, never empty.
	 * @param bool $enabled The flag.
	 *
	 * @return array<string, mixed> The tree with the flag written.
	 */
	private function withFlag(array $tree, array $path, bool $enabled): array {
		$key = (string)array_shift($path);
		if ($path === []) {
			$tree[$key] = $enabled;
			return $tree;
		}

		$tree[$key] = $this->withFlag(tree: ($tree[$key] ?? []), path: $path, enabled: $enabled);

		return $tree;
	}//end withFlag()

	/**
	 * Where a rule's `enabled` flag is declared, as a path into the declarations.
	 *
	 * @param array{configuration: array<string, mixed>, properties: array<string, mixed>} $declarations The schema's declarations.
	 * @param RuleDescriptor $rule The rule as declared; not a flow.
	 *
	 * @return array<int, string> The path, ending in `enabled`.
	 */
	private function switchPath(array $declarations, RuleDescriptor $rule): array {
		$key = $rule->getKey();
		if ($rule->getKind() === RuleVocabulary::KIND_STATE_FIELD_RULE) {
			return ['configuration', 'x-openregister-lifecycle', 'states', $key, 'enabled'];
		}

		if ($rule->getKind() !== RuleVocabulary::KIND_CALCULATION) {
			return ['configuration', 'x-openregister-lifecycle', 'transitions', $key, 'enabled'];
		}

		// A calculation is declared in one of two places and the switch has to
		// land in the one the author used, or the merge that builds the one
		// calculations map will read the old value back.
		if (isset($declarations['properties'][$key][PropertyCalculations::PROPERTY_KEY]) === true) {
			return ['properties', $key, PropertyCalculations::PROPERTY_KEY, 'enabled'];
		}

		return ['configuration', 'x-openregister-calculations', $key, 'enabled'];
	}//end switchPath()
}//end class

<?php

/**
 * NamedConditionCheck: save-time refusal of a named condition a schema cannot resolve.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\DeclaredRuleDescriptors;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;

/**
 * Refuses a schema save whose rules reference a named condition that is not
 * declared, whose named conditions form a cycle, or whose chain is deeper than
 * the library allows (REQ-RCT-001).
 *
 * The library is the schema's own `x-openregister-conditions`. The rules read
 * are the schema's: the lifecycle transition guards, the state field rules and
 * the administered validations, each named by its rule id, so the refusal says
 * which rule referenced what. Flows keep their conditions on the flow, not on
 * the schema, and are not read here.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */
final class NamedConditionCheck {

	/**
	 * Refuse the save when a named condition cannot be resolved.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws \Exception When a reference is undeclared, a cycle closes or a chain is too deep.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md#requirement-a-condition-is-named-once-and-used-inside-other-rules-req-rct-001
	 */
	public function check(Schema $schema): void {
		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration[NamedConditionLibrary::ANNOTATION] ?? null);
		if (is_array($annotation) === false) {
			$annotation = null;
		}

		$refusal = (new NamedConditionLibrary())->refusalFor(
			annotation: $annotation,
			usingNodes: self::conditionNodes(schema: $schema, configuration: $configuration)
		);
		if ($refusal !== null) {
			(new AnnotationFaults(schema: $schema, key: NamedConditionLibrary::ANNOTATION))->refuseWith(messages: [$refusal]);
		}
	}//end check()

	/**
	 * The condition of every schema rule that can reference a named condition, by rule id.
	 *
	 * @param Schema               $schema        The schema.
	 * @param array<string, mixed> $configuration Its configuration.
	 *
	 * @return array<string, mixed> Condition nodes by rule id.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md#requirement-a-condition-is-named-once-and-used-inside-other-rules-req-rct-001
	 */
	public static function conditionNodes(Schema $schema, array $configuration): array {
		$slug = (string)($schema->getSlug() ?? '');
		$lifecycle = ($configuration['x-openregister-lifecycle'] ?? []);
		if (is_array($lifecycle) === false) {
			$lifecycle = [];
		}

		$declared = new DeclaredRuleDescriptors(slug: $slug);
		$nodes = [];
		foreach (array_merge(
			$declared->stateFieldRules(states: ($lifecycle['states'] ?? [])),
			$declared->lifecycleConditions(transitions: ($lifecycle['transitions'] ?? []))
		) as $descriptor) {
			if ($descriptor->getCondition() !== null) {
				$nodes[$descriptor->getId()] = $descriptor->getCondition();
			}
		}

		$validations = ($configuration[AdministeredValidations::ANNOTATION] ?? []);
		if (is_array($validations) === false) {
			$validations = [];
		}

		foreach ($validations as $key => $validation) {
			if (is_array($validation) === true && array_key_exists('condition', $validation) === true) {
				$id = RuleDescriptor::idFor(kind: RuleVocabulary::KIND_ADMINISTERED_VALIDATION, schemaSlug: $slug, key: (string)$key);
				$nodes[$id] = $validation['condition'];
			}
		}

		return $nodes;
	}//end conditionNodes()
}//end class

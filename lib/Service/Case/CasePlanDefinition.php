<?php

/**
 * The case layer's own definition format, validated at save time and
 * compiled into plan-item rows.
 *
 * NOT CMMN XML. The format is JSON in the system's own vocabulary:
 *
 *   {
 *     "settings": {
 *       "authorization": ["group-id", "user:uid", "role:name"],
 *       "results": ["toegekend", "afgewezen"],
 *       "writeThrough": {"statusField": "status", "statusAtField": "statusReachedAt",
 *                        "resultField": "resultaat", "resultAtField": "resultaatReachedAt"}
 *     },
 *     "items": [
 *       {"key": "intake", "type": "stage", "name": "Intake", "required": true,
 *        "entryCriteria": [{"id": "s1", "on": {"event": "case.item.completed", "item": "x"}, "if": {...}}],
 *        "exitCriteria": [], "children": [ ... ]},
 *       {"key": "check", "type": "humanTask", "candidateGroups": ["behandelaars"],
 *        "discretionary": false, "repetition": {"max": 2}, "authorization": [...]}
 *     ]
 *   }
 *
 * Everything that can be wrong is refused HERE: an unknown event, an invalid
 * if-part, an unknown type, a duplicate key, a flow binding on a non-stage,
 * children on a non-stage. The editor fails, not the case.
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

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseValidationException;
use Throwable;

/**
 * Validates and compiles case-plan definitions.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) `rowFrom()` is one setter
 * per optional definition field; each nullable field is one branch.
 * @SuppressWarnings(PHPMD.NPathComplexity) Same cause.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The sum of every shape
 * the boundary refuses; each refusal is one `if` with one message, and the
 * spec asks for all of them to fail at save time.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
 */
class CasePlanDefinition {

	/**
	 * Constructor.
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
	 * @spec openspec/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function validate(array $definition): array {
		return (new CasePlanValidation(sentries: $this->sentries))->definition(definition: $definition);
	}//end validate()

	/**
	 * Validate ONE ad-hoc item as submitted at runtime.
	 *
	 * An ad-hoc item may not declare its own authorization (it derives from
	 * its parent or the plan root), may not be discretionary (it is attached
	 * by an act, there is nothing to enable), and may not carry children.
	 *
	 * @param array<string, mixed> $node The item as submitted.
	 *
	 * @return array<string, mixed> The normalised node.
	 *
	 * @throws CaseValidationException On any refused element.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function validateAdHoc(array $node): array {
		$this->validateAdHocShape(node: $node);

		return (new CasePlanValidation(sentries: $this->sentries))->validateNode(node: $node, path: 'item');
	}//end validateAdHoc()

	/**
	 * What an ad-hoc item may not carry: its own authorization, a discretionary
	 * flag, children or a flow binding.
	 *
	 * @param array<string, mixed> $node The item as submitted.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException On any refused element.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function validateAdHocShape(array $node): void {
		if (array_key_exists('authorization', $node) === true) {
			throw new CaseValidationException(
				message: 'An ad-hoc item cannot declare its own authorization; it derives it from its parent stage or the plan root.'
			);
		}

		if (($node['discretionary'] ?? false) === true) {
			throw new CaseValidationException(message: 'An ad-hoc item cannot be discretionary; it is entered by the act of attaching it.');
		}

		if (isset($node['children']) === true || isset($node['flow']) === true) {
			throw new CaseValidationException(message: 'An ad-hoc item cannot carry children or a flow binding.');
		}
	}//end validateAdHocShape()

	/**
	 * Build the unsaved row for one placed node.
	 *
	 * @param CasePlanPlacement $placed    The normalised node, its parent row id and its position.
	 * @param CasePlanInsertion $insertion The anchor, the plan settings carried on every row, the creating
	 *                                     identity and the provenance; an origin it names wins over the node's own.
	 *
	 * @return CaseItem The unsaved row in `available`.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function rowFrom(CasePlanPlacement $placed, CasePlanInsertion $insertion): CaseItem {
		$node = $placed->node;
		$origin = ($insertion->provenance->origin ?? $this->originOf(node: $node));
		$row = $this->describedRow(node: $node);
		$this->anchor(row: $row, insertion: $insertion);
		if ($origin !== CaseItem::ORIGIN_ADHOC) {
			$row->setDefinitionItemKey((string)$node['key']);
		}

		$row->setOrigin($origin);
		$this->place(row: $row, placed: $placed);
		$this->applyRules(row: $row, node: $node);
		$this->applyAssignment(row: $row, node: $node);

		return $row;
	}//end rowFrom()

	/**
	 * Where a definition node comes from: a discretionary node waits to be
	 * enabled, every other one is defined.
	 *
	 * @param array<string, mixed> $node The normalised node.
	 *
	 * @return string discretionary | defined.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function originOf(array $node): string {
		if (($node['discretionary'] ?? false) === true) {
			return CaseItem::ORIGIN_DISCRETIONARY;
		}

		return CaseItem::ORIGIN_DEFINED;
	}//end originOf()

	/**
	 * A new row carrying the node's key, name and description.
	 *
	 * @param array<string, mixed> $node The normalised node.
	 *
	 * @return CaseItem The unsaved row.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function describedRow(array $node): CaseItem {
		$row = new CaseItem();
		$row->setItemKey((string)$node['key']);
		$row->setName($this->stringOrNull(value: ($node['name'] ?? null)));
		$row->setDescription($this->stringOrNull(value: ($node['description'] ?? null)));

		return $row;
	}//end describedRow()

	/**
	 * What every row of one insertion shares: the anchor, the provenance, the
	 * plan settings and the creating identity.
	 *
	 * @param CaseItem          $row       The unsaved row.
	 * @param CasePlanInsertion $insertion The anchor, settings, creating identity and provenance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function anchor(CaseItem $row, CasePlanInsertion $insertion): void {
		$row->setObjectUuid($insertion->anchor->objectUuid);
		$row->setRegisterId($insertion->anchor->registerId);
		$row->setSchemaId($insertion->anchor->schemaId);
		$row->setFlowUuid($insertion->provenance->flowUuid);
		$row->setFlowVersion($insertion->provenance->flowVersion);
		$row->setPlanSettings($insertion->settings);
		$row->setCreatedBy($insertion->actor);
	}//end anchor()

	/**
	 * Where a row sits: its parent, its type and its order among its siblings.
	 *
	 * @param CaseItem          $row    The unsaved row.
	 * @param CasePlanPlacement $placed The normalised node, its parent row id and its position.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function place(CaseItem $row, CasePlanPlacement $placed): void {
		$row->setParentItemId($placed->parentId);
		$row->setPlanItemType((string)$placed->node['type']);
		$row->setPosition($placed->position);
	}//end place()

	/**
	 * The row's lifecycle rules: its start state, criteria, required and
	 * discretionary flags, repetition and authorization.
	 *
	 * @param CaseItem $row The unsaved row.
	 * @param array<string, mixed> $node The normalised node.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function applyRules(CaseItem $row, array $node): void {
		$row->setState(CaseItem::STATE_AVAILABLE);
		$row->setIsTerminal(false);
		$row->setEntryCriteria($this->listOrNull(value: ($node['entryCriteria'] ?? null)));
		$row->setExitCriteria($this->listOrNull(value: ($node['exitCriteria'] ?? null)));
		$row->setRequired(($node['required'] ?? true) !== false);
		$row->setDiscretionary(($node['discretionary'] ?? false) === true);
		$row->setRepetition($this->listOrNull(value: ($node['repetition'] ?? null)));
		$row->setRealisationCount(1);
		$row->setAuthorizationRules($this->listOrNull(value: ($node['authorization'] ?? null)));
	}//end applyRules()

	/**
	 * Who the row's work is for and by when: candidates, deadlines and the carried terms.
	 *
	 * @param CaseItem $row The unsaved row.
	 * @param array<string, mixed> $node The normalised node.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When a deadline is not a date.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function applyAssignment(CaseItem $row, array $node): void {
		$row->setCandidateUsers($this->listOrNull(value: ($node['candidateUsers'] ?? null)));
		$row->setCandidateGroups($this->listOrNull(value: ($node['candidateGroups'] ?? null)));
		$row->setCandidateRole($this->stringOrNull(value: ($node['candidateRole'] ?? null)));
		$row->setDueAt($this->dateOrNull(value: ($node['dueAt'] ?? null), field: 'dueAt'));
		$row->setExpiresAt($this->dateOrNull(value: ($node['expiresAt'] ?? null), field: 'expiresAt'));
		$row->setDoorlooptijd($this->stringOrNull(value: ($node['doorlooptijd'] ?? null)));
		$row->setServicenorm($this->stringOrNull(value: ($node['servicenorm'] ?? null)));
	}//end applyAssignment()

	/**
	 * A trimmed string, or null for empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string or null.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	private function stringOrNull(mixed $value): ?string {
		// Null is not a scalar, so it is refused here too.
		$text = '';
		if (is_scalar($value) === true) {
			$text = trim((string)$value);
		}

		if ($text === '') {
			return null;
		}

		return $text;
	}//end stringOrNull()

	/**
	 * An array, or null for empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array|null The array or null.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	private function listOrNull(mixed $value): ?array {
		if (is_array($value) === false || $value === []) {
			return null;
		}

		return $value;
	}//end listOrNull()

	/**
	 * A date, or null; an unparseable one is refused naming the field.
	 *
	 * @param mixed $value The value.
	 * @param string $field The field, for the message.
	 *
	 * @return DateTime|null The date or null.
	 *
	 * @throws CaseValidationException When unparseable.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function dateOrNull(mixed $value, string $field): ?DateTime {
		if (in_array($value, [null, ''], true) === true) {
			return null;
		}

		if ($value instanceof DateTime) {
			return $value;
		}

		try {
			return new DateTime((string)$value);
		} catch (Throwable) {
			throw new CaseValidationException(message: sprintf("'%s' is not a date: %s", $field, (string)$value));
		}
	}//end dateOrNull()
}//end class

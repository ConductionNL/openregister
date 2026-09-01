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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
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
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) `rowFrom()` takes the
 * anchor triple, the tree position, the settings and the provenance
 * separately because they come from different callers (definition import,
 * ad-hoc attach, repetition); a parameter object would be built and unpacked
 * at every one of them.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
 */
class CasePlanDefinition {

	/**
	 * What an item key may look like: something a sentry can name and a URL
	 * can carry.
	 */
	private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/';

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-sentries-are-entry-and-exit-criteria-over-existing-engine-primitives
	 */
	public function validate(array $definition): array {
		$items = ($definition['items'] ?? null);
		if (is_array($items) === false || $items === []) {
			throw new CaseValidationException(message: 'A case-plan definition needs a non-empty `items` list.');
		}

		$settings = ($definition['settings'] ?? []);
		if (is_array($settings) === false) {
			throw new CaseValidationException(message: '`settings` must be an object.');
		}

		$this->validateRuleList(rules: ($settings['authorization'] ?? null), where: 'settings.authorization');
		if (isset($settings['results']) === true && $this->isStringList(value: $settings['results']) === false) {
			throw new CaseValidationException(message: '`settings.results` must be a list of result names.');
		}

		if (isset($settings['writeThrough']) === true && is_array($settings['writeThrough']) === false) {
			throw new CaseValidationException(message: '`settings.writeThrough` must be an object of field names.');
		}

		$keys = [];
		$flows = [];
		$normalised = [];
		foreach ($items as $index => $node) {
			$normalised[] = $this->validateNode(node: $node, path: 'items[' . (int)$index . ']', keys: $keys, flows: $flows);
		}

		if ($flows !== []) {
			$settings['flows'] = $flows;
		}

		return ['settings' => $settings, 'items' => $normalised];
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function validateAdHoc(array $node): array {
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

		$keys = [];
		$flows = [];

		return $this->validateNode(node: $node, path: 'item', keys: $keys, flows: $flows);
	}//end validateAdHoc()

	/**
	 * Build the unsaved row for one normalised node.
	 *
	 * @param array<string, mixed> $node The normalised node.
	 * @param string $objectUuid The anchor.
	 * @param int|null $registerId The anchor's register.
	 * @param int|null $schemaId The anchor's schema.
	 * @param int|null $parentId The containing stage's row id, or null.
	 * @param int $position Order among siblings.
	 * @param array<string, mixed> $settings The plan settings, carried on every row.
	 * @param string $origin defined | discretionary | adhoc.
	 * @param string|null $actor The creating identity.
	 * @param string|null $flowUuid Definition provenance, when any.
	 * @param int|null $flowVersion Definition provenance, when any.
	 *
	 * @return CaseItem The unsaved row in `available`.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function rowFrom(
		array $node,
		string $objectUuid,
		?int $registerId,
		?int $schemaId,
		?int $parentId,
		int $position,
		array $settings,
		string $origin,
		?string $actor,
		?string $flowUuid = null,
		?int $flowVersion = null,
	): CaseItem {
		$row = new CaseItem();
		$row->setItemKey((string)$node['key']);
		$row->setName($this->stringOrNull(value: ($node['name'] ?? null)));
		$row->setDescription($this->stringOrNull(value: ($node['description'] ?? null)));
		$row->setObjectUuid($objectUuid);
		$row->setRegisterId($registerId);
		$row->setSchemaId($schemaId);
		$row->setFlowUuid($flowUuid);
		$row->setFlowVersion($flowVersion);
		if ($origin !== CaseItem::ORIGIN_ADHOC) {
			$row->setDefinitionItemKey((string)$node['key']);
		}

		$row->setOrigin($origin);
		$row->setParentItemId($parentId);
		$row->setPlanItemType((string)$node['type']);
		$row->setPosition($position);
		$row->setState(CaseItem::STATE_AVAILABLE);
		$row->setIsTerminal(false);
		$row->setEntryCriteria($this->listOrNull(value: ($node['entryCriteria'] ?? null)));
		$row->setExitCriteria($this->listOrNull(value: ($node['exitCriteria'] ?? null)));
		$row->setRequired(($node['required'] ?? true) !== false);
		$row->setDiscretionary(($node['discretionary'] ?? false) === true);
		$row->setRepetition($this->listOrNull(value: ($node['repetition'] ?? null)));
		$row->setRealisationCount(1);
		$row->setAuthorizationRules($this->listOrNull(value: ($node['authorization'] ?? null)));
		$row->setCandidateUsers($this->listOrNull(value: ($node['candidateUsers'] ?? null)));
		$row->setCandidateGroups($this->listOrNull(value: ($node['candidateGroups'] ?? null)));
		$row->setCandidateRole($this->stringOrNull(value: ($node['candidateRole'] ?? null)));
		$row->setDueAt($this->dateOrNull(value: ($node['dueAt'] ?? null), field: 'dueAt'));
		$row->setExpiresAt($this->dateOrNull(value: ($node['expiresAt'] ?? null), field: 'expiresAt'));
		$row->setDoorlooptijd($this->stringOrNull(value: ($node['doorlooptijd'] ?? null)));
		$row->setServicenorm($this->stringOrNull(value: ($node['servicenorm'] ?? null)));
		$row->setPlanSettings($settings);
		$row->setCreatedBy($actor);

		return $row;
	}//end rowFrom()

	/**
	 * Validate one node and, recursively, its children.
	 *
	 * @param mixed $node The node as submitted.
	 * @param string $path Where it is, for messages.
	 * @param array<string, bool> $keys Keys seen so far (by reference).
	 * @param array<string, string> $flows Stage flow bindings collected (by reference).
	 *
	 * @return array<string, mixed> The normalised node.
	 *
	 * @throws CaseValidationException On the first refused element.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	private function validateNode(mixed $node, string $path, array &$keys, array &$flows): array {
		if (is_array($node) === false) {
			throw new CaseValidationException(message: sprintf('%s must be an object.', $path));
		}

		$key = trim((string)($node['key'] ?? ''));
		if ($key === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
			throw new CaseValidationException(message: sprintf('%s needs a `key` of letters, digits, `_`, `.` or `-` (max 128).', $path));
		}

		if (isset($keys[$key]) === true) {
			throw new CaseValidationException(message: sprintf("%s repeats item key '%s'; keys are unique within a plan.", $path, $key));
		}

		$keys[$key] = true;
		$type = (string)($node['type'] ?? '');
		if (in_array($type, CaseItem::TYPES, true) === false) {
			throw new CaseValidationException(
				message: sprintf("%s ('%s') has type '%s'; expected one of %s.", $path, $key, $type, implode(', ', CaseItem::TYPES))
			);
		}

		$this->sentries->validateCriteria(criteria: ($node['entryCriteria'] ?? null), where: sprintf("'%s' entry", $key));
		$this->sentries->validateCriteria(criteria: ($node['exitCriteria'] ?? null), where: sprintf("'%s' exit", $key));
		$this->validateRuleList(rules: ($node['authorization'] ?? null), where: sprintf("'%s'.authorization", $key));

		$repetition = ($node['repetition'] ?? null);
		if ($repetition !== null && (is_array($repetition) === false || (int)($repetition['max'] ?? 0) < 1)) {
			throw new CaseValidationException(message: sprintf("'%s'.repetition must be {\"max\": N} with N >= 1.", $key));
		}

		if ($type === CaseItem::TYPE_MILESTONE && ($node['discretionary'] ?? false) === true) {
			throw new CaseValidationException(message: sprintf("'%s' is a milestone and cannot be discretionary: a milestone is never enabled.", $key));
		}

		if ($type !== CaseItem::TYPE_STAGE) {
			if (isset($node['children']) === true) {
				throw new CaseValidationException(message: sprintf("'%s' is a %s and cannot contain children; only a stage nests.", $key, $type));
			}

			if (isset($node['flow']) === true) {
				throw new CaseValidationException(message: sprintf("'%s' is a %s and cannot bind a flow; only a stage is realised by a run.", $key, $type));
			}
		}

		$node['key'] = $key;
		$children = ($node['children'] ?? []);
		$node['children'] = [];
		if ($type === CaseItem::TYPE_STAGE) {
			$flow = trim((string)($node['flow'] ?? ''));
			if ($flow !== '') {
				$flows[$key] = $flow;
			}

			if (is_array($children) === false) {
				throw new CaseValidationException(message: sprintf("'%s'.children must be a list.", $key));
			}

			// A stage bound to a flow is driven by its run; children under it
			// would have two masters.
			if ($flow !== '' && $children !== []) {
				throw new CaseValidationException(message: sprintf("'%s' binds a flow and cannot also contain children.", $key));
			}

			$normalisedChildren = [];
			foreach ($children as $index => $child) {
				$normalisedChildren[] = $this->validateNode(node: $child, path: sprintf('%s.children[%d]', $path, (int)$index), keys: $keys, flows: $flows);
			}

			$node['children'] = $normalisedChildren;
		}

		return $node;
	}//end validateNode()

	/**
	 * An authorization rule list is a list of non-empty strings, or absent.
	 *
	 * @param mixed $rules The list.
	 * @param string $where For the message.
	 *
	 * @return void
	 *
	 * @throws CaseValidationException When malformed.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
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

	/**
	 * A trimmed string, or null for empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string or null.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null || is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-cmmn-notation-is-not-adopted-and-bpmn-remains-a-format
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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-zaaktype-maps-to-a-case-skeleton-and-reports-what-it-could-not-map
	 */
	private function dateOrNull(mixed $value, string $field): ?DateTime {
		if ($value === null || $value === '') {
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

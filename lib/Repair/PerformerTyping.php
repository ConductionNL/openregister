<?php

/**
 * One pass of the performer typing repair over the stored flows.
 *
 * Collects, for the one run that builds it, what was rewritten and what was
 * left alone, so the steps of the walk share that state without passing it
 * from method to method by reference.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalKindsInterface;
use OCP\Migration\IOutput;

/**
 * Types the performer strings of one flow at a time, and reports at the end.
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */
final class PerformerTyping {

	/**
	 * The performer fields, and the type a bare entry in each already means.
	 *
	 * 🔑 THE FIELD NAME IS EVIDENCE. `candidateGroups` holds group names and
	 * always did, so its entries are not ambiguous at all: they need no
	 * resolution and no guess. Only the fields whose name says nothing about
	 * the kind are resolved.
	 *
	 * @var array<string, string|null>
	 */
	private const FIELDS = [
		'assignee' => null,
		'routingFallback' => null,
		'candidateUsers' => 'user',
		'candidateGroups' => 'group',
		'candidateRole' => 'group',
	];

	/**
	 * Strings naming more than one kind.
	 *
	 * @var array<int, string>
	 */
	private array $ambiguous = [];

	/**
	 * Strings naming nothing at all.
	 *
	 * @var array<int, string>
	 */
	private array $unresolvable = [];

	/**
	 * Rewrites stored so far.
	 *
	 * @var integer
	 */
	private int $rewritten = 0;

	/**
	 * Rewrites in the flow being typed, counted only once it is stored.
	 *
	 * @var integer
	 */
	private int $pending = 0;

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface     $flows The mapper, to store the result.
	 * @param PrincipalKindsInterface $kinds Says which kinds answer to a name.
	 */
	public function __construct(
		private readonly FlowMapperInterface $flows,
		private readonly PrincipalKindsInterface $kinds,
	) {

	}//end __construct()

	/**
	 * Type one flow's performer strings and store the flow when one changed.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function typeFlow(Flow $flow): void {
		$this->pending = 0;
		$nodes = $this->typedNodes(flow: $flow);
		if ($nodes === null) {
			return;
		}

		$flow->setNodes($nodes);
		$this->flows->update($flow);
		$this->rewritten += $this->pending;

	}//end typeFlow()

	/**
	 * How many strings were rewritten and stored.
	 *
	 * @return integer The count.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function rewritten(): int {
		return $this->rewritten;
	}//end rewritten()

	/**
	 * Say what was left alone, and why.
	 *
	 * 🔑 EACH ONE BY NAME, WITH ITS REASON. "3 could not be migrated" tells
	 * nobody which flow to open, and a report nobody can act on is the same as
	 * no report.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function report(IOutput $output): void {
		foreach ($this->ambiguous as $line) {
			$output->warning(
				'AMBIGUOUS, left as it is: ' . $line
					. '. Today both may answer, so choosing one would silently narrow who can. Set the type by hand.'
			);
		}

		foreach ($this->unresolvable as $line) {
			$output->warning(
				'UNRESOLVABLE, left as it is: ' . $line
					. '. Nothing on this instance answers to that name; the flow was already broken before this step ran.'
			);
		}

	}//end report()

	/**
	 * The flow's nodes with their performers typed, or null when none changed.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array<int|string, mixed>|null The nodes, or null to leave the flow alone.
	 */
	private function typedNodes(Flow $flow): ?array {
		$nodes = ($flow->getNodes() ?? []);
		if (is_array($nodes) === false || $nodes === []) {
			return null;
		}

		return $this->typedEachNode(nodes: $nodes, flowUuid: (string)$flow->getUuid());

	}//end typedNodes()

	/**
	 * The nodes with each step's performer fields typed, or null when no step changed.
	 *
	 * A typed config is only returned for a step whose config changed, so the
	 * nodes are unchanged exactly when no step returned one.
	 *
	 * @param array  $nodes    The flow's nodes.
	 * @param string $flowUuid The flow, as a warning names it.
	 *
	 * @return array|null The typed nodes.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function typedEachNode(array $nodes, string $flowUuid): ?array {
		$typedNodes = $nodes;
		foreach ($nodes as $index => $node) {
			$config = $this->typedConfig(node: $node, flowUuid: $flowUuid);
			if ($config !== null) {
				$typedNodes[$index]['config'] = $config;
			}
		}

		if ($typedNodes === $nodes) {
			return null;
		}

		return $typedNodes;

	}//end typedEachNode()

	/**
	 * One step's config with its performers typed, or null when none changed.
	 *
	 * @param mixed  $node     One stored step.
	 * @param string $flowUuid The flow it belongs to, for the report.
	 *
	 * @return array<string, mixed>|null The config, or null to leave the step alone.
	 */
	private function typedConfig(mixed $node, string $flowUuid): ?array {
		if (is_array($node) === false || is_array(($node['config'] ?? null)) === false) {
			return null;
		}

		return $this->typedFields(config: $node['config'], step: $flowUuid . ' step "' . (string)($node['id'] ?? '?') . '"');

	}//end typedConfig()

	/**
	 * The step's config with every performer field typed, or null when none changed.
	 *
	 * @param array  $config The step's config.
	 * @param string $step   The flow and step, as a warning names them.
	 *
	 * @return array|null The typed config.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function typedFields(array $config, string $step): ?array {
		$changed = false;
		foreach (self::FIELDS as $name => $impliedType) {
			$field = new PerformerField(impliedType: $impliedType, where: $step . ' field "' . $name . '"');
			$typed = $this->typeValue(value: ($config[$name] ?? null), field: $field);
			if ($typed !== null) {
				$config[$name] = $typed;
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $config;

	}//end typedFields()

	/**
	 * The typed replacement for one stored value, or null to leave it alone.
	 *
	 * @param mixed          $value What is stored.
	 * @param PerformerField $field The field it is stored in.
	 *
	 * @return mixed The replacement, or null when nothing should change.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function typeValue(mixed $value, PerformerField $field): mixed {
		$entries = $this->entriesOf(value: $value);
		if ($entries === null) {
			return null;
		}

		$pendingBefore = $this->pending;
		$out = $this->typeEntries(entries: $entries, field: $field);
		if ($this->pending === $pendingBefore) {
			return null;
		}

		if ($this->isList(value: $value) === true) {
			return $out;
		}

		return ($out[0] ?? null);

	}//end typeValue()

	/**
	 * Type each entry, counting every entry that changed as pending.
	 *
	 * @param array          $entries The stored entries.
	 * @param PerformerField $field   The field they are stored in.
	 *
	 * @return array The typed entries, without the empty ones.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function typeEntries(array $entries, PerformerField $field): array {
		$out = [];
		foreach ($entries as $entry) {
			$typed = $this->typeOneEntry(entry: $entry, field: $field);
			if ($typed === null) {
				// An empty string is not a performer and is not kept.
				continue;
			}

			if ($typed !== $entry) {
				$this->pending++;
			}

			$out[] = $typed;
		}

		return $out;

	}//end typeEntries()

	/**
	 * The entries a stored value holds, or null when it holds none.
	 *
	 * @param mixed $value What is stored.
	 *
	 * @return array<int, mixed>|null The entries, or null when there is nothing to type.
	 */
	private function entriesOf(mixed $value): ?array {
		if ($value === null || $value === '' || $value === []) {
			return null;
		}

		if ($this->isList(value: $value) === true) {
			return $value;
		}

		return [$value];

	}//end entriesOf()

	/**
	 * Whether a stored value is a LIST of performers rather than one of them.
	 *
	 * 🔑 A `{type, id}` MAP IS ITSELF AN ARRAY, so a list is only a list when
	 * its keys are sequential. Reading the map as a list would type its two
	 * VALUES as two separate performers.
	 *
	 * @param mixed $value What is stored.
	 *
	 * @return bool Whether it is a list.
	 */
	private function isList(mixed $value): bool {
		return (is_array($value) === true && array_is_list($value) === true);

	}//end isList()

	/**
	 * The typed form of ONE stored entry, or the entry unchanged.
	 *
	 * 🔴 RETURNING THE ENTRY UNCHANGED IS THE "LEAVE IT ALONE" ANSWER, and it
	 * is deliberately not null: null means "this was never a performer", and
	 * the two must not be confused. An unresolvable string is a performer
	 * somebody wrote down; it stays exactly as it is, and the guard keeps
	 * reading it under its old, wider meaning, so nothing regresses.
	 *
	 * @param mixed          $entry One stored entry.
	 * @param PerformerField $field The field it is stored in.
	 *
	 * @return mixed The typed entry, the entry unchanged, or null when it is not one.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function typeOneEntry(mixed $entry, PerformerField $field): mixed {
		// Already typed: nothing to decide.
		if (is_array($entry) === true) {
			return $entry;
		}

		$name = trim((string)$entry);
		if ($name === '') {
			return null;
		}

		// 🔴 A TEMPLATE IS NOT A NAME. `{{ case.assignee }}` is resolved per
		// item when the step runs, so there is nothing here to look up and
		// nothing broken about it. Found on the dev instance, where the first
		// draft reported it as "already broken" — a false alarm that would
		// have sent somebody looking for a flow that is working correctly.
		if (str_contains($name, '{{') === true) {
			return $entry;
		}

		return ($this->typedName(name: $name, field: $field) ?? $entry);

	}//end typeOneEntry()

	/**
	 * The `{type, id}` a bare name certainly means, or null when it is not certain.
	 *
	 * A null answer has been reported as ambiguous or unresolvable, by name.
	 *
	 * @param string         $name  The trimmed stored string.
	 * @param PerformerField $field The field it is stored in.
	 *
	 * @return array{type: string, id: string}|null The typed reference, or null to leave it.
	 */
	private function typedName(string $name, PerformerField $field): ?array {
		// The field name already says what kind it is, so there is nothing
		// ambiguous to resolve.
		if ($field->impliedType !== null) {
			return ['type' => $field->impliedType, 'id' => $name];
		}

		$kinds = $this->kinds->kindsAnsweringTo(name: $name);
		if (count($kinds) === 1) {
			return ['type' => $kinds[0], 'id' => $name];
		}

		if ($kinds === []) {
			$this->unresolvable[] = '"' . $name . '" at ' . $field->where;

			return null;
		}

		$this->ambiguous[] = '"' . $name . '" at ' . $field->where . ' names a ' . implode(' and a ', $kinds);

		return null;

	}//end typedName()
}//end class

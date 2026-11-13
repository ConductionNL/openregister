<?php

/**
 * The declared extras of a graph-mode lifecycle: forms, side moves and reopen.
 *
 * Graph mode derives its ordered moves from FK-scoped sibling rows. What the
 * order cannot express is declared beside it on the `graph` block: a `form`
 * whose values are written with a move, `sideMoves` that set a second field
 * (suspend, resume) and freeze the ordered graph while set, and a `reopen`
 * out of a terminal state. This class holds the pure rules for those three;
 * TransitionEngine reads the siblings, checks the caller and saves.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use RuntimeException;

/**
 * Forms, side moves and reopen on a graph-mode lifecycle.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Three declarations, each normalised defensively
 * because a malformed one must offer nothing rather than fail the read.
 *
 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
 */
final class GraphMoveCatalogue {

	/**
	 * The action name of a declared reopen.
	 */
	public const REOPEN = 'reopen';

	/**
	 * The action a reopen requires when it names none.
	 */
	public const REOPEN_REQUIRES = 'manage';

	/**
	 * A declared form as `{properties, required}`, or null when none (or a malformed one) is declared.
	 *
	 * @param mixed $form The `form` declaration.
	 *
	 * @return array{properties: list<string>, required: list<string>}|null
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function form(mixed $form): ?array {
		if (is_array($form) === false || is_array($form['properties'] ?? null) === false) {
			return null;
		}

		$properties = array_values(array_filter($form['properties'], static fn (mixed $property): bool => is_string($property) === true && $property !== ''));
		if ($properties === []) {
			return null;
		}

		$required = array_values(
			array_filter(
				(array)($form['required'] ?? []),
				static fn (mixed $r): bool => is_string($r) === true && in_array($r, $properties, true) === true
			)
		);

		return ['properties' => $properties, 'required' => $required];
	}//end form()

	/**
	 * Whether the graph, a side move or the reopen declares a form: whether any action takes a payload.
	 *
	 * @param array<string, mixed> $graph The `graph` block.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function declaresForms(array $graph): bool {
		if ($this->form(form: ($graph['form'] ?? null)) !== null) {
			return true;
		}

		$reopen = $this->reopen(graph: $graph);
		if ($reopen !== null && $reopen['form'] !== null) {
			return true;
		}

		foreach ($this->sideMoves(graph: $graph) as $sideMove) {
			if ($sideMove['form'] !== null) {
				return true;
			}
		}

		return false;
	}//end declaresForms()

	/**
	 * The form of an ordered graph move: the graph's `form`, unless it applies to final targets only.
	 *
	 * @param array<string, mixed> $graph       The `graph` block.
	 * @param bool                 $targetFinal Whether the move's target is a terminal state.
	 *
	 * @return array{properties: list<string>, required: list<string>}|null
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function moveForm(array $graph, bool $targetFinal): ?array {
		$declared = ($graph['form'] ?? null);
		if (is_array($declared) === true && ($declared['on'] ?? 'any') === 'final' && $targetFinal === false) {
			return null;
		}

		return $this->form(form: $declared);
	}//end moveForm()

	/**
	 * A published action with its form: the `form` itself, and its properties as `inputs`.
	 *
	 * An action without a form is answered unchanged, so a graph that declares
	 * none publishes exactly what it did before.
	 *
	 * @param array<string, mixed>                                       $action The published action.
	 * @param array{properties: list<string>, required: list<string>}|null $form   The action's form.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function withForm(array $action, ?array $form): array {
		if ($form === null) {
			return $action;
		}

		$inputs = [];
		foreach ($form['properties'] as $property) {
			$inputs[] = ['field' => $property, 'required' => in_array($property, $form['required'], true)];
		}

		$action['inputs'] = $inputs;
		$action['form'] = $form;
		return $action;
	}//end withForm()

	/**
	 * The posted values a form accepts.
	 *
	 * A key the form does not name is a malformed request; a required value
	 * that is absent or empty refuses the move, naming the property.
	 *
	 * @param array{properties: list<string>, required: list<string>}|null $form   The action's form.
	 * @param array<string, mixed>                                       $data   The posted values.
	 * @param string                                                     $action The action name.
	 *
	 * @return array<string, mixed> The values to write with the move.
	 *
	 * @throws InvalidTransitionInputException When a key is not in the form.
	 * @throws RuntimeException When a required value is missing.
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function acceptedValues(?array $form, array $data, string $action): array {
		$properties = ($form['properties'] ?? []);
		$unknown = array_values(array_map('strval', array_diff(array_keys($data), $properties)));
		if ($unknown !== []) {
			throw new InvalidTransitionInputException(
				message: sprintf('Transition "%s" does not accept input field(s): "%s".', $action, implode('", "', $unknown)),
				fields: $unknown
			);
		}

		$missing = [];
		foreach (($form['required'] ?? []) as $property) {
			if (array_key_exists($property, $data) === false || $data[$property] === null || $data[$property] === '') {
				$missing[] = $property;
			}
		}

		if ($missing !== []) {
			throw new RuntimeException(
				sprintf('Transition "%s" is missing required form value(s): "%s".', $action, implode('", "', $missing))
			);
		}

		return $data;
	}//end acceptedValues()

	/**
	 * Whether a side move that sets its field to true holds it there: the ordered graph is frozen.
	 *
	 * @param array<string, mixed> $graph The `graph` block.
	 * @param array<string, mixed> $data  The object's data.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function frozen(array $graph, array $data): bool {
		foreach ($this->sideMoves(graph: $graph) as $sideMove) {
			if ($sideMove['field'] !== null && $sideMove['set'] === true && ($data[$sideMove['field']] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end frozen()

	/**
	 * The side moves offered on an object in a non-terminal state.
	 *
	 * A side move with a field is offered while its field is not at its value.
	 * One without a field (a term change: a form, no field) is offered only
	 * while the graph is not frozen.
	 *
	 * @param array<string, mixed> $graph   The `graph` block.
	 * @param array<string, mixed> $data    The object's data.
	 * @param string               $current The object's current state.
	 *
	 * @return list<array{published: array<string, mixed>, write: array<string, mixed>, form: array|null}>
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function sideMoveCandidates(array $graph, array $data, string $current): array {
		$frozen = $this->frozen(graph: $graph, data: $data);
		$candidates = [];
		foreach ($this->sideMoves(graph: $graph) as $name => $sideMove) {
			$write = [];
			if ($sideMove['field'] === null && $frozen === true) {
				continue;
			}

			if ($sideMove['field'] !== null) {
				if ((bool)($data[$sideMove['field']] ?? false) === $sideMove['set']) {
					continue;
				}

				$write = [$sideMove['field'] => $sideMove['set']];
			}

			$published = [
				'action' => $name,
				'to' => $current,
				'label' => $sideMove['label'],
				'requires' => null,
				'description' => null,
				'inputs' => [],
			];
			$candidates[] = [
				'published' => $this->withForm(action: $published, form: $sideMove['form']),
				'write' => $write,
				'form' => $sideMove['form'],
			];
		}//end foreach

		return $candidates;
	}//end sideMoveCandidates()

	/**
	 * The declared reopen, normalised, or null when the graph declares none.
	 *
	 * @param array<string, mixed> $graph The `graph` block.
	 *
	 * @return array{to: mixed, requires: string, label: string, form: array|null}|null
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function reopen(array $graph): ?array {
		$reopen = ($graph['reopen'] ?? null);
		if (is_array($reopen) === false) {
			return null;
		}

		$requires = ($reopen['requires'] ?? self::REOPEN_REQUIRES);
		if (is_string($requires) === false || trim($requires) === '') {
			$requires = self::REOPEN_REQUIRES;
		}

		$label = ($reopen['label'] ?? 'Reopen');
		if (is_string($label) === false || $label === '') {
			$label = 'Reopen';
		}

		return [
			'to' => ($reopen['to'] ?? 'first'),
			'requires' => $requires,
			'label' => $label,
			'form' => $this->form(form: ($reopen['form'] ?? null)),
		];
	}//end reopen()

	/**
	 * The sibling a reopen moves to: the first, the one with a uuid, or the first matching a filter.
	 *
	 * @param mixed              $target   The reopen's `to`: 'first', a sibling uuid, or a `{property: value}` filter.
	 * @param list<ObjectEntity> $siblings The ordered siblings.
	 *
	 * @return ObjectEntity|null The target, or null when none matches.
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function reopenTarget(mixed $target, array $siblings): ?ObjectEntity {
		if ($siblings === []) {
			return null;
		}

		if ($target === 'first') {
			return $siblings[0];
		}

		foreach ($siblings as $sibling) {
			if ($this->matches(sibling: $sibling, target: $target) === true) {
				return $sibling;
			}
		}

		return null;
	}//end reopenTarget()

	/**
	 * Whether a sibling is the reopen target: its uuid, or every filter value equal.
	 *
	 * @param ObjectEntity $sibling The sibling.
	 * @param mixed        $target  A sibling uuid or a `{property: value}` filter.
	 *
	 * @return bool
	 */
	private function matches(ObjectEntity $sibling, mixed $target): bool {
		if (is_string($target) === true) {
			return $target !== '' && (string)$sibling->getUuid() === $target;
		}

		if (is_array($target) === false || $target === []) {
			return false;
		}

		$data = ($sibling->getObject() ?? []);
		foreach ($target as $property => $value) {
			if (array_key_exists((string)$property, $data) === false || $data[(string)$property] !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * The declared side moves, normalised; malformed entries are left out.
	 *
	 * @param array<string, mixed> $graph The `graph` block.
	 *
	 * @return array<string, array{field: string|null, set: bool, label: string, form: array|null}>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each key falls back on its own when malformed.
	 */
	private function sideMoves(array $graph): array {
		$declared = ($graph['sideMoves'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$sideMoves = [];
		foreach ($declared as $name => $sideMove) {
			if (is_string($name) === false || $name === '' || is_array($sideMove) === false) {
				continue;
			}

			$field = ($sideMove['field'] ?? null);
			if (is_string($field) === false || $field === '') {
				$field = null;
			}

			$label = ($sideMove['label'] ?? $name);
			if (is_string($label) === false || $label === '') {
				$label = $name;
			}

			$sideMoves[$name] = [
				'field' => $field,
				'set' => (($sideMove['set'] ?? true) === true),
				'label' => $label,
				'form' => $this->form(form: ($sideMove['form'] ?? null)),
			];
		}//end foreach

		return $sideMoves;
	}//end sideMoves()
}//end class

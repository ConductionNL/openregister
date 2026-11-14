<?php

/**
 * The shape rules for a graph block's `form`, `sideMoves` and `reopen`.
 *
 * A form names schema properties the actor fills with a move; a side move sets
 * a second (boolean) property; a reopen names the sibling it returns to and the
 * action it requires. None of the three may write the lifecycle field itself,
 * and a side move may not take an action name the engine already gives out.
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

/**
 * Validates the graph-mode extras at schema save.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One shape check per declared key, each answering its own error.
 *
 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
 */
final class LifecycleGraphMovesValidator {

	/**
	 * The errors of a graph block's `form`, `sideMoves` and `reopen`.
	 *
	 * @param array<string, mixed> $graph  The `graph` block.
	 * @param array<string, mixed> $schema The full schema definition.
	 * @param string               $field  The lifecycle field.
	 *
	 * @return list<array{code: string, message: string}> The errors (empty = valid).
	 *
	 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
	 */
	public function validate(array $graph, array $schema, string $field): array {
		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		$errors = [];
		if (array_key_exists('form', $graph) === true) {
			$errors = $this->formErrors(form: $graph['form'], where: 'graph.form', context: ['properties' => $properties, 'field' => $field]);
			$errors = array_merge($errors, $this->formOnErrors(form: $graph['form']));
		}

		if (array_key_exists('sideMoves', $graph) === true) {
			$errors = array_merge($errors, $this->sideMoveErrors(sideMoves: $graph['sideMoves'], context: ['properties' => $properties, 'field' => $field]));
		}

		if (array_key_exists('reopen', $graph) === true) {
			$errors = array_merge($errors, $this->reopenErrors(reopen: $graph['reopen'], context: ['properties' => $properties, 'field' => $field]));
		}

		return $errors;
	}//end validate()

	/**
	 * The errors of one `form`: declared properties, required among them, never the lifecycle field.
	 *
	 * @param mixed                $form    The `form` declaration.
	 * @param string               $where   Where it sits, for the message.
	 * @param array<string, mixed> $context The schema `properties` and the lifecycle `field`.
	 *
	 * @return list<array{code: string, message: string}>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One check per form key, each answering its own error.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      One check per form key, each answering its own error.
	 */
	private function formErrors(mixed $form, string $where, array $context): array {
		$properties = ($form['properties'] ?? null);
		if (is_array($form) === false || is_array($properties) === false || $properties === []) {
			return [self::error(
				code: 'lifecycle-graph-form-invalid',
				message: sprintf('x-openregister-lifecycle.%s must declare a non-empty `properties` list.', $where)
			)];
		}

		$errors = [];
		foreach ($properties as $property) {
			if (is_string($property) === false || isset($context['properties'][$property]) === false) {
								$errors[] = self::error(
									code: 'lifecycle-graph-form-invalid',
									message: sprintf('x-openregister-lifecycle.%s property "%s" is not declared in `properties`.', $where, self::shown(value: $property))
								);
				continue;
			}

			if ($property === $context['field']) {
				$errors[] = self::error(
					code: 'lifecycle-graph-form-invalid',
					message: sprintf('x-openregister-lifecycle.%s may not name the lifecycle field "%s".', $where, $property)
				);
			}
		}

		$required = ($form['required'] ?? []);
		if (is_array($required) === false) {
			$errors[] = self::error(
				code: 'lifecycle-graph-form-invalid',
				message: sprintf('x-openregister-lifecycle.%s.required must be a list.', $where)
			);
			return $errors;
		}

		foreach ($required as $name) {
			if (is_string($name) === false || in_array($name, $properties, true) === false) {
								$errors[] = self::error(
									code: 'lifecycle-graph-form-invalid',
									message: sprintf('x-openregister-lifecycle.%s.required "%s" is not one of its `properties`.', $where, self::shown(value: $name))
								);
			}
		}

		return $errors;
	}//end formErrors()

	/**
	 * The error of a graph form's `on`, when it is neither `any` nor `final`.
	 *
	 * @param mixed $form The graph's `form`.
	 *
	 * @return list<array{code: string, message: string}>
	 */
	private function formOnErrors(mixed $form): array {
		if (is_array($form) === false || array_key_exists('on', $form) === false || in_array($form['on'], ['any', 'final'], true) === true) {
			return [];
		}

		return [self::error(
			code: 'lifecycle-graph-form-invalid',
			message: 'x-openregister-lifecycle.graph.form.on must be one of any|final.'
		)];
	}//end formOnErrors()

	/**
	 * The errors of `sideMoves`: a map of named moves, each setting a boolean property or carrying a form.
	 *
	 * @param mixed                $sideMoves The `sideMoves` declaration.
	 * @param array<string, mixed> $context   The schema `properties` and the lifecycle `field`.
	 *
	 * @return list<array{code: string, message: string}>
	 */
	private function sideMoveErrors(mixed $sideMoves, array $context): array {
		if (is_array($sideMoves) === false || $sideMoves === [] || array_is_list($sideMoves) === true) {
			return [self::error(
				code: 'lifecycle-graph-sidemove-invalid',
				message: 'x-openregister-lifecycle.graph.sideMoves must be a map of named side moves.'
			)];
		}

		$errors = [];
		foreach ($sideMoves as $name => $sideMove) {
			$name = (string)$name;
			if ($name === GraphMoveCatalogue::REOPEN || str_starts_with($name, 'move-to-') === true) {
				$errors[] = self::error(
					code: 'lifecycle-graph-sidemove-invalid',
					message: sprintf('x-openregister-lifecycle.graph.sideMoves "%s" takes an action name the graph already uses.', $name)
				);
				continue;
			}

			if (is_array($sideMove) === false) {
				$errors[] = self::error(
					code: 'lifecycle-graph-sidemove-invalid',
					message: sprintf('x-openregister-lifecycle.graph.sideMoves "%s" must be an object.', $name)
				);
				continue;
			}

			$errors = array_merge($errors, $this->oneSideMoveErrors(name: $name, sideMove: $sideMove, context: $context));
		}

		return $errors;
	}//end sideMoveErrors()

	/**
	 * The errors of one side move: its field a declared property other than the lifecycle field, `set` a boolean.
	 *
	 * @param string               $name     The side move's name.
	 * @param array<string, mixed> $sideMove The side move.
	 * @param array<string, mixed> $context  The schema `properties` and the lifecycle `field`.
	 *
	 * @return list<array{code: string, message: string}>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One check per side-move key, each answering its own error.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      One check per side-move key, each answering its own error.
	 */
	private function oneSideMoveErrors(string $name, array $sideMove, array $context): array {
		$where = sprintf('graph.sideMoves.%s', $name);
		$field = ($sideMove['field'] ?? null);
		if ($field === null && array_key_exists('form', $sideMove) === false) {
			return [self::error(
				code: 'lifecycle-graph-sidemove-invalid',
				message: sprintf('x-openregister-lifecycle.%s must declare a `field` or a `form`.', $where)
			)];
		}

		$errors = [];
		if ($field !== null) {
			if (is_string($field) === false || isset($context['properties'][$field]) === false || $field === $context['field']) {
				$errors[] = self::error(
					code: 'lifecycle-graph-sidemove-invalid',
					message: sprintf('x-openregister-lifecycle.%s.field must name a declared property other than the lifecycle field.', $where)
				);
			}

			if (is_bool($sideMove['set'] ?? null) === false) {
				$errors[] = self::error(
					code: 'lifecycle-graph-sidemove-invalid',
					message: sprintf('x-openregister-lifecycle.%s.set must be true or false.', $where)
				);
			}
		}

		if (array_key_exists('label', $sideMove) === true && (is_string($sideMove['label']) === false || $sideMove['label'] === '')) {
			$errors[] = self::error(
				code: 'lifecycle-graph-sidemove-invalid',
				message: sprintf('x-openregister-lifecycle.%s.label must be a non-empty string.', $where)
			);
		}

		if (array_key_exists('form', $sideMove) === true) {
			$errors = array_merge($errors, $this->formErrors(form: $sideMove['form'], where: $where . '.form', context: $context));
		}

		return $errors;
	}//end oneSideMoveErrors()

	/**
	 * The errors of `reopen`: `to` is `first`, a uuid or a filter; `requires` a non-empty action.
	 *
	 * @param mixed                $reopen  The `reopen` declaration.
	 * @param array<string, mixed> $context The schema `properties` and the lifecycle `field`.
	 *
	 * @return list<array{code: string, message: string}>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One check per reopen key, each answering its own error.
	 */
	private function reopenErrors(mixed $reopen, array $context): array {
		if (is_array($reopen) === false) {
			return [self::error(
				code: 'lifecycle-graph-reopen-invalid',
				message: 'x-openregister-lifecycle.graph.reopen must be an object.'
			)];
		}

		$errors = [];
		$target = ($reopen['to'] ?? 'first');
		$validTarget = (is_string($target) === true && $target !== '')
			|| (is_array($target) === true && $target !== [] && array_is_list($target) === false);
		if ($validTarget === false) {
			$errors[] = self::error(
				code: 'lifecycle-graph-reopen-invalid',
				message: 'x-openregister-lifecycle.graph.reopen.to must be "first", a sibling uuid or a {property: value} filter.'
			);
		}

		if (array_key_exists('requires', $reopen) === true && (is_string($reopen['requires']) === false || trim($reopen['requires']) === '')) {
			$errors[] = self::error(
				code: 'lifecycle-graph-reopen-invalid',
				message: 'x-openregister-lifecycle.graph.reopen.requires must name an action.'
			);
		}

		if (array_key_exists('form', $reopen) === true) {
			$errors = array_merge($errors, $this->formErrors(form: $reopen['form'], where: 'graph.reopen.form', context: $context));
		}

		return $errors;
	}//end reopenErrors()

	/**
	 * A declared value as a message shows it: the string, else its type.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function shown(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		return gettype($value);
	}//end shown()

	/**
	 * One error entry.
	 *
	 * @param string $code    The error code.
	 * @param string $message The message.
	 *
	 * @return array{code: string, message: string}
	 */
	private static function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()
}//end class

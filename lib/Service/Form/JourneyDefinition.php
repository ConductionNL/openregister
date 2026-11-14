<?php

/**
 * JourneyDefinition: a stored journey's steps, branching, writes and access, read from its body.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * An ordered list of steps, each `{ id, type, fields?, next?, writes? }`, and the journey's access.
 *
 * A step declares the answers it collects in `fields` (names, or field
 * objects carrying `field`, `key` or `name`) and through the `field` of each
 * `writes[].mapping.fields[]` entry. A `next` rule is `{ when?, step }`: the
 * first rule whose `when` (a `visibleWhen` condition) holds names the next
 * step; a rule without `when` always holds; with no rule holding the next
 * declared step follows.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */
final class JourneyDefinition {

	/**
	 * The step types a journey accepts.
	 *
	 * @var list<string>
	 */
	public const STEP_TYPES = ['form', 'review', 'confirmation'];

	/**
	 * The access values a journey accepts.
	 *
	 * @var list<string>
	 */
	public const ACCESS = ['anonymous', 'authenticated', 'minTrust'];

	/**
	 * The steps, keyed by id, in declared order.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $steps = [];

	/**
	 * Read a journey body.
	 *
	 * @param string               $id   The journey object's uuid.
	 * @param array<string, mixed> $body The journey object's body.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $body,
	) {
		foreach ((array)($body['steps'] ?? []) as $step) {
			if (is_array($step) === false) {
				continue;
			}

			$stepId = $step['id'] ?? null;
			if (is_string($stepId) === true && $stepId !== '' && isset($this->steps[$stepId]) === false) {
				$this->steps[$stepId] = $step;
			}
		}
	}//end __construct()

	/**
	 * The declared access, or null when absent or not one of the accepted values.
	 *
	 * Access is never inferred: a caller treats null as closed.
	 *
	 * @return string|null anonymous, authenticated, minTrust or null.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function access(): ?string {
		$access = $this->body['access'] ?? null;
		if (is_array($access) === true) {
			$access = $access['level'] ?? null;
		}

		if (is_string($access) === true && in_array($access, self::ACCESS, true) === true) {
			return $access;
		}

		return null;
	}//end access()

	/**
	 * Whether the journey is published.
	 *
	 * @return bool True when its status is `published` or it is flagged published.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function isPublished(): bool {
		return ($this->body['status'] ?? null) === 'published' || ($this->body['published'] ?? null) === true;
	}//end isPublished()

	/**
	 * The step ids in declared order.
	 *
	 * @return list<string> The ids.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function stepIds(): array {
		return array_keys($this->steps);
	}//end stepIds()

	/**
	 * Whether the journey declares a step with this id.
	 *
	 * @param string $stepId The step id.
	 *
	 * @return bool True when declared.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function hasStep(string $stepId): bool {
		return isset($this->steps[$stepId]);
	}//end hasStep()

	/**
	 * One step's declaration.
	 *
	 * @param string $stepId The step id.
	 *
	 * @return array<string, mixed> The step; empty when undeclared.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function step(string $stepId): array {
		return ($this->steps[$stepId] ?? []);
	}//end step()

	/**
	 * The step's writes, in declared order.
	 *
	 * @param string $stepId The step id.
	 *
	 * @return list<array<string, mixed>> The writes `{ register, schema, mapping?, as? }`.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
	 */
	public function writesOf(string $stepId): array {
		return array_values(array_filter((array)($this->step(stepId: $stepId)['writes'] ?? []), 'is_array'));
	}//end writesOf()

	/**
	 * The field names one step declares: its `fields` and its write mappings' fields.
	 *
	 * @param string $stepId The step id.
	 *
	 * @return list<string> The field names, without duplicates.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
	 */
	public function fieldsOf(string $stepId): array {
		$names = [];
		foreach ((array)($this->step(stepId: $stepId)['fields'] ?? []) as $field) {
			$names[] = $this->fieldName(field: $field);
		}

		foreach ($this->writesOf(stepId: $stepId) as $write) {
			foreach ((array)(($write['mapping'] ?? [])['fields'] ?? []) as $field) {
				$names[] = $this->fieldName(field: $field);
			}
		}

		return array_values(array_unique(array_filter($names, static fn (?string $name): bool => $name !== null && $name !== '')));
	}//end fieldsOf()

	/**
	 * The field names in scope at a step: those it and every step before it declare.
	 *
	 * A later step's fields are out of scope: the client does not choose them.
	 *
	 * @param string $stepId The step id.
	 *
	 * @return list<string> The field names; empty for an undeclared step.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-saved-journey-must-be-its-draft-objects-and-must-commit-a-step-all-or-none
	 */
	public function fieldsInScope(string $stepId): array {
		if ($this->hasStep(stepId: $stepId) === false) {
			return [];
		}

		$names = [];
		foreach ($this->stepIds() as $candidate) {
			$names = array_merge($names, $this->fieldsOf(stepId: $candidate));
			if ($candidate === $stepId) {
				break;
			}
		}

		return array_values(array_unique($names));
	}//end fieldsInScope()

	/**
	 * The step after this one for these answers, or null at the end.
	 *
	 * @param string                  $stepId     The current step id.
	 * @param array<string, mixed>    $answers    The answers so far.
	 * @param LocalConditionEvaluator $conditions Evaluates a rule's `when`.
	 *
	 * @return string|null The next step id; null when this is the last.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function nextAfter(string $stepId, array $answers, LocalConditionEvaluator $conditions): ?string {
		foreach ((array)($this->step(stepId: $stepId)['next'] ?? []) as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$target = $rule['step'] ?? null;
			if (is_string($target) === false || $this->hasStep(stepId: $target) === false) {
				continue;
			}

			$when = $rule['when'] ?? null;
			if ($when === null || (is_array($when) === true && $conditions->holds(condition: $when, values: $answers) === true)) {
				return $target;
			}
		}

		$ids   = $this->stepIds();
		$index = array_search($stepId, $ids, true);
		if ($index === false) {
			return null;
		}

		return ($ids[$index + 1] ?? null);
	}//end nextAfter()

	/**
	 * A field entry's name: the string itself, or its `field`, `key` or `name`.
	 *
	 * @param mixed $field The entry.
	 *
	 * @return string|null The name, or null when it has none.
	 */
	private function fieldName(mixed $field): ?string {
		if (is_string($field) === true) {
			return $field;
		}

		if (is_array($field) === false) {
			return null;
		}

		foreach (['field', 'key', 'name'] as $key) {
			if (is_string($field[$key] ?? null) === true && $field[$key] !== '') {
				return $field[$key];
			}
		}

		return null;
	}//end fieldName()
}//end class

<?php

/**
 * OpenRegister Handoff Annotation Validator
 *
 * Save-time validator for the `x-openregister-handoff` dialect on SOURCE
 * schemas (ADR-031 dialect family, ADR-051 semantic-object-handoff). Each
 * dialect entry declares a handoff to a canonical semantic kind: id, target
 * kind URI, trigger, a mapping restricted to five expression kinds, an
 * optional degradation mode, and an optional post-success source update.
 *
 * Mirrors the error style of the sibling annotation validators
 * ({@see \OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator})
 * — `validate()` returns an aggregated array of `{code, message}` errors —
 * and the ADR-048 dialect precedent
 * ({@see \OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator}).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * Validate the `x-openregister-handoff` annotation on a source schema.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: `x-openregister-handoff` declarative dialect)
 */
class HandoffAnnotationValidator {

	/**
	 * The five mapping expression kinds the v1 dialect permits.
	 *
	 * @var array<int, string>
	 */
	public const EXPRESSION_KINDS = ['from', 'const', 'template', 'semanticRef', 'provenance'];

	/**
	 * Degradation modes for `whenUnavailable`.
	 *
	 * @var array<int, string>
	 */
	public const UNAVAILABLE_MODES = ['hide', 'queue'];

	/**
	 * Validate the annotation.
	 *
	 * Expects the annotation at top-level alongside `properties` (the shape
	 * SchemaMapper hands every annotation validator):
	 * `['properties' => [...], 'x-openregister-handoff' => [...entries],
	 *   'x-openregister-lifecycle' => [...]?]`.
	 *
	 * @param array<string, mixed> $schema The schema shape to validate.
	 *
	 * @return array<int, array{code: string, message: string}> Aggregated errors (empty = valid).
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Aggregating every dialect rule in one pass is inherent.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-handoff']) === false) {
			return [];
		}

		$annotation = $schema['x-openregister-handoff'];
		if (is_array($annotation) === false) {
			return [
				[
					'code' => 'handoff-bad-annotation',
					'message' => 'x-openregister-handoff must be an array of handoff entries.',
				],
			];
		}

		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		$lifecycleStates = $this->lifecycleStates(schema: $schema);

		$errors = [];
		$seenIds = [];
		foreach (array_values($annotation) as $index => $entry) {
			if (is_array($entry) === false) {
				$errors[] = [
					'code' => 'handoff-bad-annotation',
					'message' => sprintf('x-openregister-handoff entry %d must be an object.', $index),
				];
				continue;
			}

			$entryId = $this->validateId(entry: $entry, index: $index, seenIds: $seenIds, errors: $errors);
			$this->validateTargetType(entry: $entry, entryId: $entryId, errors: $errors);
			$this->validateTrigger(entry: $entry, entryId: $entryId, lifecycleStates: $lifecycleStates, errors: $errors);
			$this->validateMapping(entry: $entry, entryId: $entryId, errors: $errors);
			$this->validateWhenUnavailable(entry: $entry, entryId: $entryId, errors: $errors);
			$this->validateOnSuccess(entry: $entry, entryId: $entryId, properties: $properties, errors: $errors);
		}//end foreach

		return $errors;
	}//end validate()

	/**
	 * Validate + register an entry's `id` (unique, slug-like).
	 *
	 * @param array<string, mixed> $entry The handoff entry.
	 * @param int $index The entry's position (for messages when id is unusable).
	 * @param array<string, bool> $seenIds Ids seen so far (by reference).
	 * @param array<int, array{code: string, message: string}> $errors Error accumulator (by reference).
	 *
	 * @return string The entry id (or a positional placeholder for messages).
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	private function validateId(array $entry, int $index, array &$seenIds, array &$errors): string {
		$id = ($entry['id'] ?? null);
		if (is_string($id) === false || preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) !== 1) {
			$errors[] = [
				'code' => 'handoff-bad-id',
				'message' => sprintf(
					'x-openregister-handoff entry %d must declare a slug-like `id` (lowercase letters, digits, hyphens).',
					$index
				),
			];
			return sprintf('entry-%d', $index);
		}

		if (isset($seenIds[$id]) === true) {
			$errors[] = [
				'code' => 'handoff-duplicate-id',
				'message' => sprintf('x-openregister-handoff id "%s" is declared more than once.', $id),
			];
		}

		$seenIds[$id] = true;
		return $id;
	}//end validateId()

	/**
	 * Validate `targetSemanticType` is an absolute URI.
	 *
	 * @param array<string, mixed> $entry The handoff entry.
	 * @param string $entryId The entry id (for messages).
	 * @param array<int, array{code: string, message: string}> $errors Error accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Target kind URI is malformed)
	 */
	private function validateTargetType(array $entry, string $entryId, array &$errors): void {
		$target = ($entry['targetSemanticType'] ?? null);
		if (is_string($target) === true
			&& filter_var($target, FILTER_VALIDATE_URL) !== false
			&& preg_match('/^https?:\/\//', $target) === 1
		) {
			return;
		}

		$errors[] = [
			'code' => 'handoff-bad-target-type',
			'message' => sprintf(
				'Handoff "%s": `targetSemanticType` must be an absolute kind URI (e.g. "%sCase").',
				$entryId,
				HandoffKindContracts::NAMESPACE_URI
			),
		];

	}//end validateTargetType()

	/**
	 * Validate the `trigger` field: `manual` or `lifecycle:<state>` where the
	 * state exists in the schema's declared lifecycle enum.
	 *
	 * @param array<string, mixed> $entry The handoff entry.
	 * @param string $entryId The entry id (for messages).
	 * @param array<int, string>|null $lifecycleStates Declared lifecycle states (null = no lifecycle declared).
	 * @param array<int, array{code: string, message: string}> $errors Error accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	private function validateTrigger(array $entry, string $entryId, ?array $lifecycleStates, array &$errors): void {
		$trigger = ($entry['trigger'] ?? 'manual');
		if ($trigger === 'manual') {
			return;
		}

		if (is_string($trigger) === false || str_starts_with($trigger, 'lifecycle:') === false) {
			$errors[] = [
				'code' => 'handoff-bad-trigger',
				'message' => sprintf('Handoff "%s": `trigger` must be "manual" or "lifecycle:<state>".', $entryId),
			];
			return;
		}

		$state = substr($trigger, strlen('lifecycle:'));
		if ($state === '') {
			$errors[] = [
				'code' => 'handoff-bad-trigger',
				'message' => sprintf('Handoff "%s": lifecycle trigger must name a state ("lifecycle:<state>").', $entryId),
			];
			return;
		}

		if ($lifecycleStates === null) {
			$errors[] = [
				'code' => 'handoff-bad-trigger',
				'message' => sprintf(
					'Handoff "%s": trigger "lifecycle:%s" requires the schema to declare x-openregister-lifecycle.',
					$entryId,
					$state
				),
			];
			return;
		}

		if (in_array($state, $lifecycleStates, true) === false) {
			$errors[] = [
				'code' => 'handoff-bad-trigger',
				'message' => sprintf(
					'Handoff "%s": lifecycle state "%s" is not declared in the schema\'s lifecycle enum (%s).',
					$entryId,
					$state,
					implode(', ', $lifecycleStates)
				),
			];
		}

	}//end validateTrigger()

	/**
	 * Validate the mapping: keys within the target kind's contract fields,
	 * every mandatory field mapped, expression kinds within the allowed five.
	 *
	 * @param array<string, mixed> $entry The handoff entry.
	 * @param string $entryId The entry id (for messages).
	 * @param array<int, array{code: string, message: string}> $errors Error accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Unknown mapping expression kind is rejected)
	 */
	private function validateMapping(array $entry, string $entryId, array &$errors): void {
		$mapping = ($entry['mapping'] ?? null);
		if (is_array($mapping) === false || $mapping === []) {
			$errors[] = [
				'code' => 'handoff-bad-annotation',
				'message' => sprintf('Handoff "%s": a non-empty `mapping` object is required.', $entryId),
			];
			return;
		}

		$kindUri = (string)($entry['targetSemanticType'] ?? '');
		$isContractKind = HandoffKindContracts::isContractKind($kindUri);
		$allFields = HandoffKindContracts::allFields($kindUri);

		foreach ($mapping as $field => $expression) {
			if ($isContractKind === true && in_array((string)$field, $allFields, true) === false) {
				$errors[] = [
					'code' => 'handoff-unknown-mapping-field',
					'message' => sprintf(
						'Handoff "%s": mapping field "%s" is not part of the "%s" contract (fields: %s).',
						$entryId,
						(string)$field,
						$kindUri,
						implode(', ', $allFields)
					),
				];
			}

			$this->validateExpression(expression: $expression, field: (string)$field, entryId: $entryId, errors: $errors);
		}

		if ($isContractKind === true) {
			$missing = array_diff(HandoffKindContracts::mandatoryFields($kindUri), array_map('strval', array_keys($mapping)));
			if ($missing !== []) {
				$errors[] = [
					'code' => 'handoff-missing-mandatory-field',
					'message' => sprintf(
						'Handoff "%s": mandatory "%s" contract field(s) not mapped: %s.',
						$entryId,
						$kindUri,
						implode(', ', $missing)
					),
				];
			}
		}

	}//end validateMapping()

	/**
	 * Validate one mapping expression uses exactly one of the five kinds.
	 *
	 * @param mixed $expression The mapping expression value.
	 * @param string $field The contract field being mapped.
	 * @param string $entryId The entry id (for messages).
	 * @param array<int, array{code: string, message: string}> $errors Error accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Unknown mapping expression kind is rejected)
	 */
	private function validateExpression(mixed $expression, string $field, string $entryId, array &$errors): void {
		if (is_array($expression) === false) {
			$errors[] = [
				'code' => 'handoff-bad-mapping-expression',
				'message' => sprintf(
					'Handoff "%s": mapping for "%s" must be an object using one of: %s.',
					$entryId,
					$field,
					implode(', ', self::EXPRESSION_KINDS)
				),
			];
			return;
		}

		$kinds = array_values(array_intersect(array_map('strval', array_keys($expression)), self::EXPRESSION_KINDS));
		// `default` is an allowed modifier alongside `from`; every other key
		// must be one of the five expression kinds.
		$unknown = array_diff(array_map('strval', array_keys($expression)), self::EXPRESSION_KINDS, ['default']);

		if (count($kinds) !== 1 || $unknown !== []) {
			$offending = 'none';
			if ($unknown !== []) {
				$offending = implode(', ', $unknown);
			}

			$errors[] = [
				'code' => 'handoff-bad-mapping-expression',
				'message' => sprintf(
					'Handoff "%s": mapping for "%s" must use exactly one expression kind of [%s]; offending key(s): %s.',
					$entryId,
					$field,
					implode(', ', self::EXPRESSION_KINDS),
					$offending
				),
			];
		}

	}//end validateExpression()

	/**
	 * Validate `whenUnavailable` when present.
	 *
	 * @param array<string, mixed> $entry The handoff entry.
	 * @param string $entryId The entry id (for messages).
	 * @param array<int, array{code: string, message: string}> $errors Error accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Graceful degradation when no provider implements the kind)
	 */
	private function validateWhenUnavailable(array $entry, string $entryId, array &$errors): void {
		$mode = ($entry['whenUnavailable'] ?? 'hide');
		if (in_array($mode, self::UNAVAILABLE_MODES, true) === true) {
			return;
		}

		$errors[] = [
			'code' => 'handoff-bad-when-unavailable',
			'message' => sprintf(
				'Handoff "%s": `whenUnavailable` must be one of [%s].',
				$entryId,
				implode(', ', self::UNAVAILABLE_MODES)
			),
		];

	}//end validateWhenUnavailable()

	/**
	 * Validate `onSuccess.set` keys exist on the source schema.
	 *
	 * @param array<string, mixed> $entry The handoff entry.
	 * @param string $entryId The entry id (for messages).
	 * @param array<string, mixed> $properties The source schema's properties map.
	 * @param array<int, array{code: string, message: string}> $errors Error accumulator (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: `onSuccess.set` names a property missing from the source schema)
	 */
	private function validateOnSuccess(array $entry, string $entryId, array $properties, array &$errors): void {
		$onSuccess = ($entry['onSuccess'] ?? null);
		if ($onSuccess === null) {
			return;
		}

		$set = null;
		if (is_array($onSuccess) === true) {
			$set = ($onSuccess['set'] ?? null);
		}

		if (is_array($set) === false || $set === []) {
			$errors[] = [
				'code' => 'handoff-bad-success-update',
				'message' => sprintf('Handoff "%s": `onSuccess` must carry a non-empty `set` map.', $entryId),
			];
			return;
		}

		foreach (array_keys($set) as $property) {
			if (array_key_exists((string)$property, $properties) === false) {
				$errors[] = [
					'code' => 'handoff-bad-success-update',
					'message' => sprintf(
						'Handoff "%s": onSuccess.set names property "%s" which the source schema does not define.',
						$entryId,
						(string)$property
					),
				];
			}
		}

	}//end validateOnSuccess()

	/**
	 * Extract the schema's declared lifecycle states (the lifecycle field's
	 * enum), or null when the schema declares no usable lifecycle annotation.
	 *
	 * @param array<string, mixed> $schema The schema shape.
	 *
	 * @return array<int, string>|null The lifecycle states, or null.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	private function lifecycleStates(array $schema): ?array {
		$lifecycle = ($schema['x-openregister-lifecycle'] ?? null);
		if (is_array($lifecycle) === false) {
			return null;
		}

		$field = ($lifecycle['field'] ?? ($lifecycle['property'] ?? null));
		if (is_string($field) === false) {
			return null;
		}

		$properties = ($schema['properties'] ?? []);
		$enum = ($properties[$field]['enum'] ?? null);
		if (is_array($enum) === false) {
			return null;
		}

		return array_values(array_map('strval', $enum));
	}//end lifecycleStates()
}//end class

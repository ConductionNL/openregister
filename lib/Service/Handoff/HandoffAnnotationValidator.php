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
 * ({@see \OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidator})
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

use OCA\OpenRegister\Service\Schemas\SchemaAnnotationValidatorInterface;

/**
 * Validate the `x-openregister-handoff` annotation on a source schema.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 *   (Requirement: `x-openregister-handoff` declarative dialect)
 */
class HandoffAnnotationValidator implements SchemaAnnotationValidatorInterface {

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
	 * The rules for an entry's `onSuccess` block.
	 *
	 * @var HandoffSuccessUpdateChecks
	 */
	private readonly HandoffSuccessUpdateChecks $onSuccessChecks;

	/**
	 * Constructor.
	 *
	 * Takes no arguments: annotation validators are constructed with `new` in
	 * the SchemaMapper save path.
	 */
	public function __construct() {
		$this->onSuccessChecks = new HandoffSuccessUpdateChecks();
	}//end __construct()

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
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-handoff']) === false) {
			return [];
		}

		$annotation = $schema['x-openregister-handoff'];
		if (is_array($annotation) === false) {
			return [$this->error(code: 'handoff-bad-annotation', message: 'x-openregister-handoff must be an array of handoff entries.')];
		}

		return $this->entriesErrors(entries: array_values($annotation), schema: $schema);
	}//end validate()

	/**
	 * The errors of every entry, in entry order.
	 *
	 * @param array<int, mixed>    $entries The annotation's entries.
	 * @param array<string, mixed> $schema  The schema shape they belong to.
	 *
	 * @return array<int, array{code: string, message: string}> Aggregated errors.
	 */
	private function entriesErrors(array $entries, array $schema): array {
		$properties = $this->ownProperties(schema: $schema);
		$lifecycleStates = $this->lifecycleStates(schema: $schema);
		$errors = [];
		foreach ($entries as $index => $raw) {
			$entry = new HandoffAnnotationEntry(entry: $raw, index: $index, lifecycleStates: $lifecycleStates, properties: $properties);
			array_push($errors, ...$this->entryErrors(entry: $entry, earlier: array_slice($entries, 0, $index)));
		}

		return $errors;
	}//end entriesErrors()

	/**
	 * The errors of one entry, rule by rule.
	 *
	 * @param HandoffAnnotationEntry $entry   The entry.
	 * @param array<int, mixed>      $earlier The entries before it (for duplicate ids).
	 *
	 * @return array<int, array{code: string, message: string}> The entry's errors.
	 */
	private function entryErrors(HandoffAnnotationEntry $entry, array $earlier): array {
		if ($entry->isObject() === false) {
			return [$this->error(code: 'handoff-bad-annotation', message: sprintf('x-openregister-handoff entry %d must be an object.', $entry->index))];
		}

		return array_merge(
			$this->idErrors(entry: $entry, earlier: $earlier),
			$this->targetTypeErrors(entry: $entry),
			$this->triggerErrors(entry: $entry),
			$this->mappingChecksFor(entry: $entry)->errors(entry: $entry),
			$this->whenUnavailableErrors(entry: $entry),
			$this->onSuccessChecks->errors(entry: $entry)
		);
	}//end entryErrors()

	/**
	 * Validate an entry's `id` (slug-like, and unique among the entries before it).
	 *
	 * @param HandoffAnnotationEntry $entry   The entry.
	 * @param array<int, mixed>      $earlier The entries before it.
	 *
	 * @return array<int, array{code: string, message: string}> The id errors.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	private function idErrors(HandoffAnnotationEntry $entry, array $earlier): array {
		$id = $entry->validId();
		if ($id === null) {
			$format = 'x-openregister-handoff entry %d must declare a slug-like `id` (lowercase letters, digits, hyphens).';
			return [$this->error(code: 'handoff-bad-id', message: sprintf($format, $entry->index))];
		}

		if ($this->declaresId(entries: $earlier, id: $id) === true) {
			return [$this->error(code: 'handoff-duplicate-id', message: sprintf('x-openregister-handoff id "%s" is declared more than once.', $id))];
		}

		return [];
	}//end idErrors()

	/**
	 * Whether any of the given entries is an object declaring this id.
	 *
	 * @param array<int, mixed> $entries The entries to search.
	 * @param string            $id      The id.
	 *
	 * @return bool True when the id is already taken.
	 */
	private function declaresId(array $entries, string $id): bool {
		foreach ($entries as $entry) {
			if (is_array($entry) === true && ($entry['id'] ?? null) === $id) {
				return true;
			}
		}

		return false;
	}//end declaresId()

	/**
	 * Validate `targetSemanticType` is an absolute URI.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> The target errors.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Target kind URI is malformed)
	 */
	private function targetTypeErrors(HandoffAnnotationEntry $entry): array {
		if ($this->isAbsoluteUri(value: $entry->field(key: 'targetSemanticType')) === true) {
			return [];
		}

		$message = sprintf('`targetSemanticType` must be an absolute kind URI (e.g. "%sCase").', HandoffKindContracts::NAMESPACE_URI);
		return [$entry->fault(code: 'handoff-bad-target-type', message: $message)];
	}//end targetTypeErrors()

	/**
	 * Whether a value is an absolute http(s) URL.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return bool True for an absolute http(s) URL.
	 */
	private function isAbsoluteUri(mixed $value): bool {
		return is_string($value) === true
			&& filter_var($value, FILTER_VALIDATE_URL) !== false
			&& preg_match('/^https?:\/\//', $value) === 1;
	}//end isAbsoluteUri()

	/**
	 * Validate the `trigger` field: `manual` or `lifecycle:<state>` where the
	 * state exists in the schema's declared lifecycle enum.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> The trigger errors.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: `x-openregister-handoff` declarative dialect)
	 */
	private function triggerErrors(HandoffAnnotationEntry $entry): array {
		$fault = $this->triggerFault(entry: $entry);
		if ($fault === null) {
			return [];
		}

		return [$entry->fault(code: 'handoff-bad-trigger', message: $fault)];
	}//end triggerErrors()

	/**
	 * What is wrong with an entry's trigger, if anything.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 *
	 * @return string|null The fault, or null for `manual` or a declared lifecycle state.
	 */
	private function triggerFault(HandoffAnnotationEntry $entry): ?string {
		$trigger = $entry->field(key: 'trigger', default: 'manual');
		if ($trigger === 'manual') {
			return null;
		}

		if (is_string($trigger) === false || str_starts_with($trigger, 'lifecycle:') === false) {
			return '`trigger` must be "manual" or "lifecycle:<state>".';
		}

		return $this->lifecycleStateFault(entry: $entry, state: substr($trigger, strlen('lifecycle:')));
	}//end triggerFault()

	/**
	 * What is wrong with a lifecycle trigger's state, if anything.
	 *
	 * @param HandoffAnnotationEntry $entry The entry (for the declared lifecycle states).
	 * @param string                 $state The state the trigger names.
	 *
	 * @return string|null The fault, or null when the state is declared.
	 */
	private function lifecycleStateFault(HandoffAnnotationEntry $entry, string $state): ?string {
		if ($state === '') {
			return 'lifecycle trigger must name a state ("lifecycle:<state>").';
		}

		if ($entry->lifecycleStates === null) {
			return sprintf('trigger "lifecycle:%s" requires the schema to declare x-openregister-lifecycle.', $state);
		}

		if (in_array($state, $entry->lifecycleStates, true) === false) {
			return sprintf('lifecycle state "%s" is not declared in the schema\'s lifecycle enum (%s).', $state, implode(', ', $entry->lifecycleStates));
		}

		return null;
	}//end lifecycleStateFault()

	/**
	 * Validate `whenUnavailable` when present.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> The degradation-mode errors.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Graceful degradation when no provider implements the kind)
	 */
	private function whenUnavailableErrors(HandoffAnnotationEntry $entry): array {
		if (in_array($entry->field(key: 'whenUnavailable', default: 'hide'), self::UNAVAILABLE_MODES, true) === true) {
			return [];
		}

		$message = sprintf('`whenUnavailable` must be one of [%s].', implode(', ', self::UNAVAILABLE_MODES));
		return [$entry->fault(code: 'handoff-bad-when-unavailable', message: $message)];
	}//end whenUnavailableErrors()

	/**
	 * The mapping rules bound to the contract of the entry's target kind.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 *
	 * @return HandoffMappingChecks The rules for its `mapping` block.
	 */
	private function mappingChecksFor(HandoffAnnotationEntry $entry): HandoffMappingChecks {
		$kindUri = $entry->kindUri();
		$contract = new HandoffKindContract(
			kindUri: $kindUri,
			isContractKind: HandoffKindContracts::isContractKind($kindUri),
			allFields: HandoffKindContracts::allFields($kindUri),
			mandatoryFields: HandoffKindContracts::mandatoryFields($kindUri)
		);

		return new HandoffMappingChecks(contract: $contract);
	}//end mappingChecksFor()

	/**
	 * Build one error entry.
	 *
	 * @param string $code    The error code.
	 * @param string $message The human-readable message.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()

	/**
	 * The schema's own properties map, or an empty map when it is malformed.
	 *
	 * @param array<string, mixed> $schema The schema shape.
	 *
	 * @return array<string, mixed> The properties map.
	 */
	private function ownProperties(array $schema): array {
		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		return $properties;
	}//end ownProperties()

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
		$field = $this->lifecycleField(lifecycle: ($schema['x-openregister-lifecycle'] ?? null));
		if ($field === null) {
			return null;
		}

		return $this->enumOf(schema: $schema, field: $field);
	}//end lifecycleStates()

	/**
	 * The `enum` of one schema property, as strings.
	 *
	 * @param array<string, mixed> $schema The schema shape.
	 * @param string               $field  The property name.
	 *
	 * @return array<int, string>|null The enum values, or null when the property declares none.
	 */
	private function enumOf(array $schema, string $field): ?array {
		$properties = ($schema['properties'] ?? []);
		$enum = ($properties[$field]['enum'] ?? null);
		if (is_array($enum) === false) {
			return null;
		}

		return array_values(array_map('strval', $enum));
	}//end enumOf()

	/**
	 * The property a lifecycle annotation drives, from `field` or else `property`.
	 *
	 * @param mixed $lifecycle The raw `x-openregister-lifecycle` value.
	 *
	 * @return string|null The property name, or null when the annotation names none.
	 */
	private function lifecycleField(mixed $lifecycle): ?string {
		if (is_array($lifecycle) === false) {
			return null;
		}

		$field = ($lifecycle['field'] ?? ($lifecycle['property'] ?? null));
		if (is_string($field) === false) {
			return null;
		}

		return $field;
	}//end lifecycleField()
}//end class

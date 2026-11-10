<?php

/**
 * OpenRegister Handoff Mapping Checks
 *
 * The save-time rules for the `mapping` block of one `x-openregister-handoff`
 * entry: fields within the target kind's contract, every mandatory field
 * mapped, and each expression using exactly one of the five permitted kinds.
 * Split out of {@see HandoffAnnotationValidator}, which runs them per entry.
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
 * Validates one handoff entry's `mapping` block.
 */
final class HandoffMappingChecks {

	/**
	 * The error code for a malformed mapping expression.
	 *
	 * @var string
	 */
	private const BAD_EXPRESSION = 'handoff-bad-mapping-expression';

	/**
	 * Constructor.
	 *
	 * @param HandoffKindContract $contract The contract of the entry's target kind.
	 */
	public function __construct(
		private readonly HandoffKindContract $contract,
	) {

	}//end __construct()

	/**
	 * Validate the mapping: keys within the target kind's contract fields,
	 * every mandatory field mapped, expression kinds within the allowed five.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> The mapping errors.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Unknown mapping expression kind is rejected)
	 */
	public function errors(HandoffAnnotationEntry $entry): array {
		$fields = $entry->mappingFields();
		if ($fields === []) {
			return [$entry->fault(code: 'handoff-bad-annotation', message: 'a non-empty `mapping` object is required.')];
		}

		$errors = [];
		foreach ($fields as $field) {
			array_push($errors, ...$this->fieldErrors(entry: $entry, field: $field));
		}

		return array_merge($errors, $this->missingMandatoryErrors(entry: $entry));
	}//end errors()

	/**
	 * The errors for one mapped field: outside the contract, and a malformed expression.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 * @param string                 $field The mapped contract field.
	 *
	 * @return array<int, array{code: string, message: string}> The field's errors.
	 */
	private function fieldErrors(HandoffAnnotationEntry $entry, string $field): array {
		$errors = [];
		if ($this->contract->isForeign(field: $field) === true) {
			$format = 'mapping field "%s" is not part of the "%s" contract (fields: %s).';
			$message = sprintf($format, $field, $this->contract->kindUri, implode(', ', $this->contract->allFields));
			$errors[] = $entry->fault(code: 'handoff-unknown-mapping-field', message: $message);
		}

		return array_merge($errors, $this->expressionErrors(entry: $entry, field: $field));
	}//end fieldErrors()

	/**
	 * The error for mandatory contract fields the mapping leaves out, if any.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> One error, or none.
	 */
	private function missingMandatoryErrors(HandoffAnnotationEntry $entry): array {
		if ($this->contract->isContractKind === false) {
			return [];
		}

		$missing = array_diff($this->contract->mandatoryFields, $entry->mappingFields());
		if ($missing === []) {
			return [];
		}

		$message = sprintf('mandatory "%s" contract field(s) not mapped: %s.', $this->contract->kindUri, implode(', ', $missing));
		return [$entry->fault(code: 'handoff-missing-mandatory-field', message: $message)];
	}//end missingMandatoryErrors()

	/**
	 * Validate one mapping expression uses exactly one of the five kinds.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 * @param string                 $field The contract field being mapped.
	 *
	 * @return array<int, array{code: string, message: string}> The expression errors.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: Unknown mapping expression kind is rejected)
	 */
	private function expressionErrors(HandoffAnnotationEntry $entry, string $field): array {
		$expression = $entry->expressionFor(field: $field);
		$kinds = implode(', ', HandoffAnnotationValidator::EXPRESSION_KINDS);
		if (is_array($expression) === false) {
			return [$entry->fault(code: self::BAD_EXPRESSION, message: sprintf('mapping for "%s" must be an object using one of: %s.', $field, $kinds))];
		}

		$offending = $this->offendingKeys(expression: $expression);
		if ($offending === null) {
			return [];
		}

		$format = 'mapping for "%s" must use exactly one expression kind of [%s]; offending key(s): %s.';
		return [$entry->fault(code: self::BAD_EXPRESSION, message: sprintf($format, $field, $kinds, $offending))];
	}//end expressionErrors()

	/**
	 * What makes an expression's keys invalid, if anything.
	 *
	 * `default` is an allowed modifier alongside `from`; every other key must be
	 * one of the five expression kinds, and exactly one kind must be present.
	 *
	 * @param array<mixed, mixed> $expression The mapping expression.
	 *
	 * @return string|null The unknown keys, `none` when only the kind count is wrong, or null when valid.
	 */
	private function offendingKeys(array $expression): ?string {
		$keys = array_map('strval', array_keys($expression));
		$unknown = array_diff($keys, HandoffAnnotationValidator::EXPRESSION_KINDS, ['default']);
		if ($unknown !== []) {
			return implode(', ', $unknown);
		}

		if (count(array_intersect($keys, HandoffAnnotationValidator::EXPRESSION_KINDS)) !== 1) {
			return 'none';
		}

		return null;
	}//end offendingKeys()
}//end class

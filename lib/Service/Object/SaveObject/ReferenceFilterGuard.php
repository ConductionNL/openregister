<?php

/**
 * OpenRegister ReferenceFilterGuard
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration;
use Throwable;

/**
 * Refuses a reference the narrowing filter would not have offered.
 *
 * 🔴 IT CALLS THE SAME `resolve()` THE OPTIONS READ WILL, and that is the
 * whole design rather than a tidiness note. A picker that offers one set
 * and a save path that accepts another is two evaluators of one rule, and
 * they disagree within a week; the one that ends up wider is the one that
 * discloses. `ReferenceFilterDeclaration` is the single reader and the
 * single resolver, and this class only compares.
 *
 * 🔴 AN UNRESOLVED OPERAND REFUSES, IT DOES NOT WAVE THROUGH. When the
 * record has no organisation yet, the picker would have offered NOTHING,
 * so no value can be inside the filter and every value has to be refused.
 * Waving it through would make the server accept precisely the writes the
 * form was built to prevent, which is the "no options becomes every option"
 * failure one layer down.
 *
 * COST. A property declaring no filter costs one array lookup:
 * `fromProperty()` returns null before anything is read. Only a filtered
 * reference pays for the extra object read, and only for the values that
 * changed, because the caller already skipped unchanged ones.
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
 */
final class ReferenceFilterGuard {

	/**
	 * @param SaveLookups          $lookups Schema and register lookups.
	 * @param MagicMapperInterface $mapper  Reads the referenced object.
	 */
	public function __construct(
		private readonly SaveLookups $lookups,
		private readonly MagicMapperInterface $mapper,
	) {
	}//end __construct()

	/**
	 * Refuse the run's reference when it lies outside its property's filter.
	 *
	 * @param ReferencePropertyRun $run The validation pass, on one reference.
	 *
	 * @return void
	 *
	 * @throws ReferenceValidationException When the value is outside the filter.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md#requirement-a-reference-property-may-narrow-its-choices-with-a-query-over-the-record-req-fuc-003
	 */
	public function assertMatches(ReferencePropertyRun $run): void {
		$declaration = ReferenceFilterDeclaration::fromProperty(property: $run->property, path: $run->propertyName);
		if ($declaration === null) {
			return;
		}

		$answer = $declaration->resolve(record: $run->data);
		if ($answer['needs'] !== []) {
			$message = "'%s' is filtered on %s, and this record answers none of them, so nothing may be chosen for it yet.";
			throw self::refusal(run: $run, message: sprintf($message, $run->propertyName, implode(', ', $answer['needs'])));
		}

		// Null is unreadable to this caller, or gone between the existence check
		// and here. Existence is ReferenceExistence's question and it has already
		// answered it; answering it again differently here would refuse a save
		// for a reason this class cannot see.
		$referenced = $this->readReferenced(run: $run);
		if ($referenced !== null) {
			$this->compare(run: $run, comparison: [$referenced, $answer['filter']]);
		}
	}//end assertMatches()

	/**
	 * Refuse at the first condition the referenced object does not meet.
	 *
	 * @param ReferencePropertyRun $run        The validation pass, on one reference.
	 * @param array                $comparison The referenced data and the resolved filter.
	 *
	 * @psalm-param array{0: array<string,mixed>, 1: array<string,mixed>} $comparison
	 *
	 * @return void
	 *
	 * @throws ReferenceValidationException When a condition fails.
	 */
	private function compare(ReferencePropertyRun $run, array $comparison): void {
		[$referenced, $filter] = $comparison;
		foreach ($filter as $field => $expected) {
			if (self::fieldMatches(actual: ($referenced[$field] ?? null), expected: $expected) === false) {
				$message = "'%s' only accepts an object whose '%s' matches this record. '%s' does not.";
				throw self::refusal(run: $run, message: sprintf($message, $run->propertyName, (string)$field, $run->uuid));
			}
		}
	}//end compare()

	/**
	 * One condition of a resolved filter, compared.
	 *
	 * An operator this class does not know refuses, rather than passing.
	 * `ReferenceFilterDeclaration::OPERATORS` is the list, and a new entry
	 * there without an arm here would otherwise accept everything.
	 *
	 * @param mixed $actual   The referenced object's value.
	 * @param mixed $expected The resolved expectation.
	 *
	 * @return bool True when it matches.
	 */
	private static function fieldMatches(mixed $actual, mixed $expected): bool {
		if (is_array($expected) === false) {
			return ((string)$actual === (string)$expected);
		}

		if (array_key_exists('neq', $expected) === true) {
			return ((string)$actual !== (string)$expected['neq']);
		}

		if (array_key_exists('in', $expected) === true) {
			return in_array((string)$actual, array_map('strval', (array)$expected['in']), true);
		}

		return false;
	}//end fieldMatches()

	/**
	 * The referenced object as a plain array, or null when it cannot be read.
	 *
	 * @param ReferencePropertyRun $run The validation pass, on one reference.
	 *
	 * @return array<string,mixed>|null The object's data.
	 */
	private function readReferenced(ReferencePropertyRun $run): ?array {
		if ($this->lookups->schemaId(reference: $run->ref) === null) {
			return null;
		}

		$found = $this->find(run: $run);
		$data = $found?->getObject();
		if (is_array($data) === true) {
			return $data;
		}

		return null;
	}//end readReferenced()

	/**
	 * Find the referenced object in its register, unfiltered.
	 *
	 * @param ReferencePropertyRun $run The validation pass, on one reference.
	 *
	 * @return ObjectEntity|null The object, or null when it cannot be read.
	 */
	private function find(ReferencePropertyRun $run): ?ObjectEntity {
		try {
			return $this->mapper->find(
				identifier: $run->uuid,
				register: $this->targetRegister(run: $run),
				schema: null,
				includeDeleted: false,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end find()

	/**
	 * The register to look in, when the run names one.
	 *
	 * @param ReferencePropertyRun $run The validation pass, on one reference.
	 *
	 * @return Register|null
	 */
	private function targetRegister(ReferencePropertyRun $run): ?Register {
		if ($run->targetRegister === null) {
			return null;
		}

		return $this->lookups->register(registerId: $run->targetRegister);
	}//end targetRegister()

	/**
	 * The refusal of the run's reference.
	 *
	 * @param ReferencePropertyRun $run     The validation pass, on one reference.
	 * @param string               $message The refusal's words.
	 *
	 * @return ReferenceValidationException
	 */
	private static function refusal(ReferencePropertyRun $run, string $message): ReferenceValidationException {
		return new ReferenceValidationException(
			propertyName: $run->propertyName,
			referencedUuid: $run->uuid,
			targetSchemaSlug: $run->ref,
			targetRegister: $run->targetRegister,
			message: $message
		);
	}//end refusal()
}//end class

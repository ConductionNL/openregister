<?php

/**
 * OpenRegister recorded incompleteness.
 *
 * Owns the `@notSupplied` body key: the record that a value was left out on
 * purpose, with a reason from the list the schema administers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;
use stdClass;

/**
 * Reads, checks and applies the not-supplied record on an object body.
 *
 * WHY THIS IS NOT JUST AN EMPTY FIELD.
 *
 * An empty field says nobody filled it in. A field marked not supplied, with
 * an administered reason, says somebody decided (D-4). The two look identical
 * on a page and answer completely different questions in an audit, which is
 * why they are stored differently rather than distinguished by a convention
 * somebody has to remember.
 *
 * It also ends the validation dance. A required field that the applicant
 * genuinely cannot answer gets "onbekend" typed into it, and from then on the
 * register holds a string that passes every check and means nothing. Marking
 * it not supplied satisfies the required rule honestly.
 *
 * HOW THE REQUIRED RULE IS SATISFIED. The named properties are dropped from
 * `required` in a per-object copy of the schema object handed to the
 * validator, and the `@notSupplied` key is stripped from the copy of the body
 * the validator sees. Nothing about the stored schema changes, and nothing
 * about the stored body changes either: the record rides along with the object
 * and reads back with it.
 */
class NotSuppliedHandler {

	/**
	 * The not-supplied record declared on an object body.
	 *
	 * Returns a map of property name to reason code, empty when the body
	 * declares none. Malformed entries are dropped here and refused by
	 * {@see self::validate()}, so a caller reading this never has to re-check
	 * the shape.
	 *
	 * @param array $object The object body.
	 *
	 * @return array<string, string> Property names mapped to reason codes.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	public function declared(array $object): array {
		$record = ($object[Schema::NOT_SUPPLIED_KEY] ?? null);
		if (is_array($record) === false) {
			return [];
		}

		$declared = [];
		foreach ($record as $property => $reason) {
			if (is_string($property) === false || $property === '') {
				continue;
			}

			if (is_string($reason) === false || $reason === '') {
				continue;
			}

			$declared[$property] = $reason;
		}

		return $declared;
	}//end declared()

	/**
	 * Collect every reason the not-supplied record cannot be accepted.
	 *
	 * Returns a list, empty when the record is compliant. Callers MUST refuse
	 * the write when this returns a non-empty list.
	 *
	 * @param array $object The candidate object body.
	 * @param Schema $schema The schema that administers the reasons.
	 *
	 * @return array<int, array{property: string, code: string, message: string}>
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	public function validate(array $object, Schema $schema): array {
		if (array_key_exists(Schema::NOT_SUPPLIED_KEY, $object) === false) {
			return [];
		}

		$record = $object[Schema::NOT_SUPPLIED_KEY];
		if (is_array($record) === false || ($record !== [] && array_is_list($record) === true)) {
			return [
				[
					'property' => Schema::NOT_SUPPLIED_KEY,
					'code' => 'not-supplied-not-a-map',
					'message' => Schema::NOT_SUPPLIED_KEY . ' takes a property name for each reason code.',
				],
			];
		}

		$reasons = $schema->notSuppliedReasons();
		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			$properties = [];
		}

		$violations = [];
		foreach ($record as $property => $reason) {
			$refusal = $this->refuseEntry(
				property: (string)$property,
				reason: $reason,
				object: $object,
				properties: $properties,
				reasons: $reasons
			);

			if ($refusal !== null) {
				$violations[] = $refusal;
			}
		}

		return $violations;
	}//end validate()

	/**
	 * The reason one entry of the record cannot be accepted, if there is one.
	 *
	 * Four rules, in the order a person would check them: a reason has to be
	 * there, the property has to exist, it cannot also carry a value, and the
	 * reason has to be one this schema administers. The first that fires is the
	 * one returned, because a property refused for two reasons at once is a
	 * message nobody can act on.
	 *
	 * @param string $property The property named in the record.
	 * @param mixed $reason The reason code it was marked with.
	 * @param array $object The candidate object body.
	 * @param array $properties The schema's declared properties.
	 * @param array<string, string> $reasons The reasons this schema administers.
	 *
	 * @return array{property: string, code: string, message: string}|null The refusal, or null.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function refuseEntry(
		string $property,
		mixed $reason,
		array $object,
		array $properties,
		array $reasons,
	): ?array {
		if (is_string($reason) === false || $reason === '') {
			return [
				'property' => $property,
				'code' => 'not-supplied-without-a-reason',
				'message' => "'$property' is marked not supplied with no reason. Name one from the schema's list.",
			];
		}

		if (array_key_exists($property, $properties) === false) {
			return [
				'property' => $property,
				'code' => 'not-supplied-unknown-property',
				'message' => "'$property' is marked not supplied, and this schema does not declare it.",
			];
		}

		if ($this->carriesAValue(object: $object, property: $property) === true) {
			return [
				'property' => $property,
				'code' => 'not-supplied-and-answered',
				'message' => "'$property' carries a value and is marked not supplied. It can be one or the other.",
			];
		}

		if ($reasons === []) {
			return [
				'property' => $property,
				'code' => 'not-supplied-no-reasons-administered',
				'message' => "This schema administers no not-supplied reasons, so '$property' cannot be marked.",
			];
		}

		if (array_key_exists($reason, $reasons) === false) {
			$known = implode(', ', array_keys($reasons));

			return [
				'property' => $property,
				'code' => 'not-supplied-unknown-reason',
				'message' => "'$reason' is not a reason this schema administers. It accepts: $known.",
			];
		}

		return null;
	}//end refuseEntry()

	/**
	 * Whether the body answers a property at all.
	 *
	 * @param array $object The candidate object body.
	 * @param string $property The property name.
	 *
	 * @return boolean Whether a value is there.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function carriesAValue(array $object, string $property): bool {
		if (array_key_exists($property, $object) === false) {
			return false;
		}

		return ($object[$property] !== null && $object[$property] !== '' && $object[$property] !== []);
	}//end carriesAValue()

	/**
	 * The body the validator should see.
	 *
	 * Drops the record itself, which is not a declared property, and drops the
	 * properties it names, which carry no value by definition.
	 *
	 * @param array $object The object body.
	 *
	 * @return array The body to validate.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	public function stripForValidation(array $object): array {
		$declared = $this->declared(object: $object);
		unset($object[Schema::NOT_SUPPLIED_KEY]);

		foreach (array_keys($declared) as $property) {
			unset($object[$property]);
		}

		return $object;
	}//end stripForValidation()

	/**
	 * A copy of the schema object with the not-supplied properties excused.
	 *
	 * The named properties are removed from `required`, which is the whole of
	 * "not supplied satisfies a required-value rule". Everything else about the
	 * schema is left alone: a not-supplied property is excused from being
	 * answered, not from being declared.
	 *
	 * The copy is deliberately shallow-cloned rather than mutated in place.
	 * `ValidateObject` memoizes prepared schema objects per schema id and
	 * version, so mutating the object it handed out would excuse the property
	 * for every later object validated against that schema in the same request.
	 *
	 * @param object $schemaObject The schema object as the schema hands it out.
	 * @param array<string, string> $declared The not-supplied record.
	 *
	 * @return object The schema object to validate against.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	public function excuse(object $schemaObject, array $declared): object {
		if ($declared === []) {
			return $schemaObject;
		}

		$copy = new stdClass();
		foreach (get_object_vars($schemaObject) as $key => $value) {
			$copy->{$key} = $value;
		}

		$required = ($copy->required ?? null);
		if (is_array($required) === false) {
			return $copy;
		}

		$names = array_keys($declared);
		$copy->required = array_values(
			array_filter(
				$required,
				static function ($entry) use ($names): bool {
					return in_array((string)$entry, $names, true) === false;
				}
			)
		);

		return $copy;
	}//end excuse()
}//end class

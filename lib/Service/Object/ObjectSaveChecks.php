<?php

/**
 * ObjectSaveChecks: the save rules a validate-only call runs, in registration order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Listener\CodedValueValidationListener;
use OCA\OpenRegister\Listener\DependentValueListener;

/**
 * Collects the ObjectSaveCheck rules, in the order their listeners are
 * registered on the pre-write events, and runs them on a sample.
 *
 * A later guard joins by implementing ObjectSaveCheck and being added here.
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */
class ObjectSaveChecks {

	/**
	 * The checks, in registration order.
	 *
	 * @var array<int, ObjectSaveCheck>
	 */
	private readonly array $checks;

	/**
	 * Constructor.
	 *
	 * @param CodedValueValidationListener $codedValues     The coded-value and concept-shape rule.
	 * @param DependentValueListener       $dependentValues The dependent-value rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public function __construct(
		CodedValueValidationListener $codedValues,
		DependentValueListener $dependentValues,
	) {
		$this->checks = [$codedValues, $dependentValues];
	}//end __construct()

	/**
	 * The checks, in registration order.
	 *
	 * @return array<int, ObjectSaveCheck>
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public function all(): array {
		return $this->checks;
	}//end all()

	/**
	 * Every check's refusals of an object as it would be saved.
	 *
	 * @param array<string, mixed> $object   The object data.
	 * @param Schema               $schema   Its schema.
	 * @param ObjectEntity|null    $existing The stored object on an update.
	 *
	 * @return array<int, array{property: string, message: string, rule: string}>
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public function violations(array $object, Schema $schema, ?ObjectEntity $existing): array {
		$violations = [];
		foreach ($this->checks as $check) {
			$violations = array_merge($violations, $check->check(object: $object, schema: $schema, existing: $existing));
		}

		return $violations;
	}//end violations()
}//end class

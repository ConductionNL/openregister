<?php

/**
 * ClassificationFloorGuard: refuses a write that lowers an object's classification below its floor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Closure;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Rbac\ClassificationLimits;
use OCA\OpenRegister\Service\Rbac\ClassificationScale;

/**
 * The floor of a schema's `x-openregister-classification` block, checked at save.
 *
 * The floor is read from a declared property, on the object itself or behind
 * one declared reference (`sourceRelation`). A write that sets a label ranking
 * below it is refused, naming both levels; a write that raises it is allowed,
 * because raising is always the safe direction. A floor that cannot be read or
 * placed refuses nothing, as {@see ClassificationLimits::belowFloor()} decides.
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-write-may-raise-a-classification-and-not-lower-it-below-its-floor
 */
final class ClassificationFloorGuard {

	/**
	 * Constructor.
	 *
	 * @param Closure(string): ?array $referencedData Reads the data of the object a reference names, or null.
	 */
	public function __construct(
		private readonly Closure $referencedData,
	) {
	}//end __construct()

	/**
	 * Refuse the write when it sets a label below the object's floor.
	 *
	 * @param Schema               $schema The object's schema.
	 * @param array<string, mixed> $data   The object as it will be written.
	 *
	 * @return void
	 *
	 * @throws ValidationException When the label ranks below the floor.
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-write-may-raise-a-classification-and-not-lower-it-below-its-floor
	 */
	public function refuseBelowFloor(Schema $schema, array $data): void {
		$block = (new SchemaAnnotation(schema: $schema, key: ClassificationScale::ANNOTATION))->value();
		if (is_array($block) === false) {
			return;
		}

		$scale = ClassificationScale::fromBlock(block: $block);
		$limits = ClassificationLimits::fromBlock(scale: $scale, block: $block);
		$floor = $limits->floor();
		if ($floor === null) {
			return;
		}

		$requested = ($data[$scale->property()] ?? null);
		$reason = $limits->belowFloor(requested: $requested, floor: $this->floorValue(floor: $floor, data: $data));
		if ($reason !== null) {
			throw new ValidationException(message: $reason);
		}
	}//end refuseBelowFloor()

	/**
	 * The floor's label: the property on the object, or on the object its reference names.
	 *
	 * @param array{property: string, sourceRelation?: string} $floor Where the floor is read.
	 * @param array<string, mixed>                             $data  The object as it will be written.
	 *
	 * @return mixed The floor's label, or null when it cannot be read.
	 */
	private function floorValue(array $floor, array $data): mixed {
		$property = (string)($floor['property'] ?? '');
		$relation = (string)($floor['sourceRelation'] ?? '');
		if ($relation === '') {
			return ($data[$property] ?? null);
		}

		$reference = $this->referenceOf(value: ($data[$relation] ?? null));
		if ($reference === null) {
			return null;
		}

		$referenced = ($this->referencedData)($reference);
		if (is_array($referenced) === false) {
			return null;
		}

		return ($referenced[$property] ?? null);
	}//end floorValue()

	/**
	 * The uuid or uri a reference holds: a string, or an expanded object's id.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string|null The reference, or null when there is none.
	 */
	private function referenceOf(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? null));
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end referenceOf()
}//end class

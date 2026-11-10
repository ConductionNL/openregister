<?php

/**
 * OpenRegister RelationRowFields
 *
 * Shapes the fields of the relation rows ObjectRelationService writes and reads; holds no state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectRelation;

/**
 * Shapes the fields of the relation rows ObjectRelationService writes and reads; holds no state.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class RelationRowFields {
	/**
	 * The ends, kind and origin of a row a split writes from the new object back to its source.
	 *
	 * @param ObjectEntity $from The object the split created.
	 * @param ObjectEntity $to   The object it was split from.
	 *
	 * @return array<string, mixed> The row's first fields.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function splitLink(ObjectEntity $from, ObjectEntity $to): array {
		return $this->objectLink(from: $from, to: $to) + ['origin' => ObjectRelation::ORIGIN_SPLIT];
	}//end splitLink()

	/**
	 * The ends, kind and origin of a row a derivation writes from the child to its parent.
	 *
	 * @param ObjectEntity $from The derived child.
	 * @param ObjectEntity $to   The parent it was derived from.
	 *
	 * @return array<string, mixed> The row's first fields.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function derivedLink(ObjectEntity $from, ObjectEntity $to): array {
		return $this->objectLink(from: $from, to: $to) + ['origin' => ObjectRelation::ORIGIN_DERIVE];
	}//end derivedLink()

	/**
	 * The ends and kind of a row that links one object to another.
	 *
	 * @param ObjectEntity $from The object the row starts at.
	 * @param ObjectEntity $to   The object it points at.
	 *
	 * @return array<string, mixed> The ends and the kind.
	 */
	private function objectLink(ObjectEntity $from, ObjectEntity $to): array {
		return [
			'sourceUuid' => (string)$from->getUuid(),
			'sourceRegister' => $this->asInt(value: $from->getRegister()),
			'sourceSchema' => $this->asInt(value: $from->getSchema()),
			'targetUuid' => (string)$to->getUuid(),
			'targetRegister' => $this->asInt(value: $to->getRegister()),
			'targetSchema' => $this->asInt(value: $to->getSchema()),
			'kind' => ObjectRelation::KIND_OBJECT,
		];
	}//end objectLink()

	/**
	 * Read a register or schema reference as an int, when it is one.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return int|null The id.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function asInt(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end asInt()

	/**
	 * Whether a property is present with a value that is neither null nor empty.
	 *
	 * @param array<string, mixed> $data     The object data.
	 * @param int|string           $property The property.
	 *
	 * @return bool True when it carries a value.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function hasValue(array $data, int|string $property): bool {
		return array_key_exists($property, $data) === true && $data[$property] !== null && $data[$property] !== '';
	}//end hasValue()

	/**
	 * What a derivation row records as inherited: null when nothing was.
	 *
	 * @param array<string, mixed> $inherited Role => property and value, as applyInheritance() returned it.
	 *
	 * @return array<string, mixed>|null The record.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function recordOf(array $inherited): array|null {
		if ($inherited === []) {
			return null;
		}

		return $inherited;
	}//end recordOf()

	/**
	 * The address and title of an external link; a title nobody gave falls back to the address.
	 *
	 * The fallback keeps the row from ever being nameless in a list.
	 *
	 * @param string      $url   The address.
	 * @param string|null $title The title.
	 *
	 * @return array{targetUrl: string, targetTitle: string} The target fields.
	 *
	 * @throws InvalidArgumentException When the address is not a resolvable web address.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function externalTarget(string $url, string|null $title): array {
		$url = trim($url);
		if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new InvalidArgumentException('An external relation needs a resolvable web address.');
		}

		$named = $url;
		if ($title !== null && trim($title) !== '') {
			$named = trim($title);
		}

		return ['targetUrl' => $url, 'targetTitle' => $named];
	}//end externalTarget()

	/**
	 * A prose reference's scope seen from the far side.
	 *
	 * @param array<string, mixed> $scope The near side's scope.
	 *
	 * @return array<string, mixed> The far side's scope.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function reversedScope(array $scope): array {
		return [
			'sourceRegister' => ($scope['targetRegister'] ?? null),
			'sourceSchema' => ($scope['targetSchema'] ?? null),
			'targetRegister' => ($scope['sourceRegister'] ?? null),
			'targetSchema' => ($scope['sourceSchema'] ?? null),
		];
	}//end reversedScope()

	/**
	 * The first descriptor of a type.
	 *
	 * @param array<string, array<string, mixed>> $descriptors The schema's descriptors.
	 * @param string                              $type        The relation type.
	 *
	 * @return array<string, mixed>|null The descriptor.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function descriptorOfType(array $descriptors, string $type): array|null {
		foreach ($descriptors as $descriptor) {
			if (($descriptor['type'] ?? null) === $type) {
				return $descriptor;
			}
		}

		return null;
	}//end descriptorOfType()
}//end class

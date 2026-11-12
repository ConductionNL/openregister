<?php

/**
 * OpenRegister ResultNameCollector
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;

/**
 * Resolves the UUIDs a page of search results refers to into human-readable names.
 *
 * Reads relations, the organisation and owner metadata, and top-level object
 * fields, one list deep; entities are read without serialising them, which
 * would trigger a full render.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class ResultNameCollector {

	/**
	 * Object fields that never hold a reference to name.
	 */
	private const SKIPPED_KEYS = ['@self' => true, 'id' => true, '_id' => true, 'object' => true];

	/**
	 * Hold the name lookup.
	 *
	 * @param ObjectNameCacheInterface $cacheHandler Resolves UUIDs to names.
	 */
	public function __construct(private readonly ObjectNameCacheInterface $cacheHandler) {
	}//end __construct()

	/**
	 * The UUID-to-name map of everything the results refer to.
	 *
	 * @param array<mixed> $results Rendered objects or ObjectEntity instances; anything else is skipped.
	 *
	 * @return array<string, string> Map of UUID to name.
	 */
	public function namesFor(array $results): array {
		$uuids = [];
		foreach ($results as $result) {
			$uuids = array_merge($uuids, $this->uuidsOfResult(result: $result));
		}

		$uuids = array_unique($uuids);
		if (empty($uuids) === true) {
			return [];
		}

		return $this->cacheHandler->getMultipleObjectNames($uuids);
	}//end namesFor()

	/**
	 * The UUIDs one search result refers to.
	 *
	 * @param mixed $result An entity, a rendered array, or anything else.
	 *
	 * @return list<string>
	 */
	private function uuidsOfResult(mixed $result): array {
		if ($result instanceof ObjectEntity) {
			return $this->uuidsOfEntity(entity: $result);
		}

		if (is_array($result) === true) {
			return $this->uuidsOfRow(row: $result);
		}

		return [];
	}//end uuidsOfResult()

	/**
	 * The UUIDs an entity refers to, without serialising it.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return list<string>
	 */
	private function uuidsOfEntity(ObjectEntity $entity): array {
		return array_merge(
			$this->uuidsInValues(values: $this->listOf(value: $entity->getRelations())),
			$this->uuidsAmong(candidates: [$entity->getOrganisation(), $entity->getOwner()]),
			$this->uuidsOfFields(fields: $this->listOf(value: $entity->getObject()))
		);
	}//end uuidsOfEntity()

	/**
	 * The UUIDs a rendered row refers to, through its `@self` block when it has one.
	 *
	 * @param array<mixed> $row The rendered row.
	 *
	 * @return list<string>
	 */
	private function uuidsOfRow(array $row): array {
		$self   = $this->listOf(value: ($row['@self'] ?? null));
		$fields = $row;
		if (is_array($self['object'] ?? null) === true) {
			$fields = $self['object'];
		}

		return array_merge(
			$this->uuidsInValues(values: $this->listOf(value: ($self['relations'] ?? []))),
			$this->uuidsAmong(candidates: [($self['organisation'] ?? null), ($self['owner'] ?? null)]),
			$this->uuidsOfFields(fields: $fields)
		);
	}//end uuidsOfRow()

	/**
	 * The UUIDs in an object's fields, leaving out the fields that hold no reference.
	 *
	 * @param array<mixed> $fields The object fields.
	 *
	 * @return list<string>
	 */
	private function uuidsOfFields(array $fields): array {
		return $this->uuidsInValues(values: array_diff_key($fields, self::SKIPPED_KEYS));
	}//end uuidsOfFields()

	/**
	 * The UUIDs in a list of values, each a UUID or a flat list of them.
	 *
	 * @param array<mixed> $values The values.
	 *
	 * @return list<string>
	 */
	private function uuidsInValues(array $values): array {
		$uuids = [];
		foreach ($values as $value) {
			$candidates = [$value];
			if (is_array($value) === true) {
				$candidates = $value;
			}

			$uuids = array_merge($uuids, $this->uuidsAmong(candidates: $candidates));
		}

		return $uuids;
	}//end uuidsInValues()

	/**
	 * The candidates that are UUID strings, in order.
	 *
	 * @param array<mixed> $candidates The candidates.
	 *
	 * @return list<string>
	 */
	private function uuidsAmong(array $candidates): array {
		$uuids = [];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && UuidFormat::isCanonical(value: $candidate) === true) {
				$uuids[] = $candidate;
			}
		}

		return $uuids;
	}//end uuidsAmong()

	/**
	 * The value when it is an array, otherwise an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end listOf()
}//end class

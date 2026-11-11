<?php

/**
 * The inverse relations a bulk save writes between the objects of one chunk.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObjects;

/**
 * Adds each source object's uuid to the inversedBy property of the chunk objects it points at.
 *
 * Objects are visited in order and every write lands in the chunk itself, so a later
 * source reads what an earlier one wrote.
 */
final class BulkInverseRelations {

	/**
	 * The chunk objects by uuid, as references into the chunk; only filled during apply().
	 *
	 * @var array<string, array>
	 */
	private array $byUuid = [];

	/**
	 * Constructor.
	 *
	 * @param array    $schemaAnalysis The bulk schema analysis by schema id; only `inverseProperties` is read.
	 * @param \Closure $isUuid         Whether a string is a uuid a relation may point at: (string) => bool.
	 */
	public function __construct(
		private readonly array $schemaAnalysis,
		private readonly \Closure $isUuid,
	) {
	}//end __construct()

	/**
	 * Write the inverse relations of the chunk into the chunk.
	 *
	 * @param array $objects The prepared objects, changed in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function apply(array &$objects): void {
		$this->index(objects: $objects);
		foreach ($objects as &$object) {
			$this->applyObject(object: $object);
		}

		unset($object);
		$this->byUuid = [];
	}//end apply()

	/**
	 * Index the chunk by uuid as references into it; a later object with the same uuid wins.
	 *
	 * @param array $objects The prepared objects.
	 *
	 * @return void
	 */
	private function index(array &$objects): void {
		$this->byUuid = [];
		foreach ($objects as &$object) {
			$uuid = self::uuidOf(object: $object);
			if ($uuid !== null) {
				$this->byUuid[$uuid] = &$object;
			}
		}

		unset($object);
	}//end index()

	/**
	 * Write the inverse relations of one source object.
	 *
	 * @param array $object The source object, by reference so its properties are read as they are now.
	 *
	 * @return void
	 */
	private function applyObject(array &$object): void {
		$uuid = self::uuidOf(object: $object);
		if ($uuid === null) {
			return;
		}

		foreach ($this->inversePropertiesOf(object: $object) as $property => $info) {
			if (isset($object[$property]) === true) {
				$edge = new BulkInverseEdge(inversedBy: (string) $info['inversedBy'], sourceUuid: (string) $uuid);
				$this->linkAll(targetUuids: $this->targetUuids(value: $object[$property], info: $info), edge: $edge);
			}
		}
	}//end applyObject()

	/**
	 * Add the source uuid to each target, in order.
	 *
	 * @param array<string>   $targetUuids The target uuids.
	 * @param BulkInverseEdge $edge        The property and source uuid.
	 *
	 * @return void
	 */
	private function linkAll(array $targetUuids, BulkInverseEdge $edge): void {
		foreach ($targetUuids as $targetUuid) {
			$this->link(targetUuid: $targetUuid, edge: $edge);
		}
	}//end linkAll()

	/**
	 * The inverse properties of an object's schema; none when the schema is false or not analysed.
	 *
	 * @param array $object The object.
	 *
	 * @return array<string, array> Inverse property definitions by property name.
	 */
	private function inversePropertiesOf(array $object): array {
		$schemaId = ($object['@self']['schema'] ?? null);
		if ($schemaId === false || isset($this->schemaAnalysis[$schemaId]) === false) {
			return [];
		}

		return $this->schemaAnalysis[$schemaId]['inverseProperties'];
	}//end inversePropertiesOf()

	/**
	 * Add the source uuid to the target's inversedBy list, once; a target outside the chunk is skipped.
	 *
	 * @param string          $targetUuid The target uuid.
	 * @param BulkInverseEdge $edge       The property and source uuid.
	 *
	 * @return void
	 */
	private function link(string $targetUuid, BulkInverseEdge $edge): void {
		if (isset($this->byUuid[$targetUuid]) === false) {
			return;
		}

		$existing = ($this->byUuid[$targetUuid][$edge->inversedBy] ?? []);
		if (is_array($existing) === false) {
			$existing = [];
		}

		if (in_array($edge->sourceUuid, $existing) === false) {
			$existing[] = $edge->sourceUuid;
			$this->byUuid[$targetUuid][$edge->inversedBy] = $existing;
		}
	}//end link()

	/**
	 * The uuid of an object, from its @self id.
	 *
	 * @param array $object The object.
	 *
	 * @return mixed The id; null when absent.
	 */
	private static function uuidOf(array $object): mixed {
		return ($object['@self']['id'] ?? null);
	}//end uuidOf()

	/**
	 * The valid uuids a relation value points at, in order: the value itself for a single relation, its items for a list.
	 *
	 * @param mixed $value The property value.
	 * @param array $info  The inverse property definition; `isArray` must be a boolean to match.
	 *
	 * @return array<string> The target uuids.
	 */
	private function targetUuids(mixed $value, array $info): array {
		$candidates = match (true) {
			$info['isArray'] === false => [$value],
			$info['isArray'] === true && is_array($value) === true => $value,
			default => [],
		};

		return array_filter($candidates, fn ($candidate): bool => is_string($candidate) === true && ($this->isUuid)($candidate) === true);
	}//end targetUuids()
}//end class

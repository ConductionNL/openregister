<?php

/**
 * MagicSequentialOrder: a table-by-table search in the order of a UNION.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Support\OrderParameter;

/**
 * Order a table-by-table cross-schema search on the keys of the UNION path.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */
final class MagicSequentialOrder {
	use MagicColumnNameTrait;

	/**
	 * The prefix of a metadata column.
	 *
	 * @var string
	 */
	private const PREFIX = '_';

	/**
	 * The row key of the search score.
	 *
	 * @var string
	 */
	private const SCORE = '_search_score';

	/**
	 * The UNION order keys.
	 *
	 * @var array<int, array{row: string, sql: string, dir: string}>
	 */
	private readonly array $orderKeys;

	/**
	 * The ordered properties: column name => property name.
	 *
	 * @var array<string, string>
	 */
	private readonly array $propertyFields;

	/**
	 * Constructor.
	 *
	 * The platform only changes how the SQL of a key is quoted; the row keys
	 * read here are the same on every database.
	 *
	 * @param array $query Search parameters, as the caller gave them.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function __construct(array $query) {
		$this->orderKeys = (new MagicUnionOrder(isPostgres: false))->keys(query: $query);
		$this->propertyFields = $this->orderedProperties(query: $query);
	}//end __construct()

	/**
	 * The `_order` of one table's query.
	 *
	 * The score is `_relevance` there, a metadata column is `@self.<name>`
	 * and a property is its own name. A key neither form can name is left
	 * out, as the UNION path leaves out an unknown metadata column.
	 *
	 * @return array<string, string> The single-table `_order`.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function tableOrder(): array {
		$order = [];
		foreach ($this->orderKeys as $key) {
			$field = $this->tableField(rowKey: $key['row']);
			if ($field !== null && isset($order[$field]) === false) {
				$order[$field] = $key['dir'];
			}
		}

		return $order;
	}//end tableOrder()

	/**
	 * Order merged entities on the UNION path's keys with its comparator.
	 *
	 * Each entity is projected onto the row keys the UNION statement would
	 * have produced, sorted as a union is, and put back in that order.
	 *
	 * @param array<int, ObjectEntity> $results The merged entities.
	 *
	 * @return array<int, ObjectEntity> The entities in the merged order.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function sorted(array $results): array {
		$results = array_values($results);
		$rows = [];
		foreach ($results as $index => $entity) {
			$rows[] = ['__index' => $index] + $this->row(entity: $entity);
		}

		$sorted = [];
		foreach ((new MagicUnionOrder(isPostgres: false))->sorted(rows: $rows, orderKeys: $this->orderKeys) as $row) {
			$sorted[] = $results[$row['__index']];
		}

		return $sorted;
	}//end sorted()

	/**
	 * The property fields a query orders by, keyed by their column name.
	 *
	 * The UNION order keys name a property by its sanitized column; the
	 * single-table query and the object data both use the property's own
	 * name. This maps one back to the other.
	 *
	 * @param array $query Search parameters.
	 *
	 * @return array<string, string> Column name => property name.
	 */
	private function orderedProperties(array $query): array {
		$fields = [];
		foreach (array_keys((new OrderParameter(order: $query['_order'] ?? []))->fields()) as $field) {
			$field = (string) $field;
			if (str_starts_with($field, self::PREFIX) === false) {
				$fields[$this->sanitizeColumnName(name: $field)] = $field;
			}
		}

		return $fields;
	}//end orderedProperties()

	/**
	 * The `_order` field of one row key, or null when no form can name it.
	 *
	 * @param string $rowKey The UNION row key.
	 *
	 * @return string|null
	 */
	private function tableField(string $rowKey): ?string {
		if ($rowKey === self::SCORE) {
			return '_relevance';
		}

		if (isset($this->propertyFields[$rowKey]) === true) {
			return $this->propertyFields[$rowKey];
		}

		if (array_key_exists($rowKey, MagicMetadataColumns::ALL) === true) {
			return '@self.' . substr($rowKey, strlen(self::PREFIX));
		}

		return null;
	}//end tableField()

	/**
	 * An entity as the row keys of a UNION.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return array<string, mixed> Row key => comparable value.
	 */
	private function row(ObjectEntity $entity): array {
		$row = [];
		foreach ($this->orderKeys as $key) {
			$row[$key['row']] = $this->comparable(value: $this->value(entity: $entity, rowKey: $key['row']));
		}

		return $row;
	}//end row()

	/**
	 * The value an entity holds for one UNION order key.
	 *
	 * A metadata column reads the entity's own field, a property column the
	 * object's value, and a missing value is null, which sorts where the
	 * UNION's `NULL AS alias` arm puts it.
	 *
	 * @param ObjectEntity $entity The entity.
	 * @param string       $rowKey The UNION row key.
	 *
	 * @return mixed
	 */
	private function value(ObjectEntity $entity, string $rowKey): mixed {
		if ($rowKey === self::SCORE) {
			return $this->score(entity: $entity);
		}

		if (isset($this->propertyFields[$rowKey]) === true) {
			return ($entity->getObject()[$this->propertyFields[$rowKey]] ?? null);
		}

		if (str_starts_with($rowKey, self::PREFIX) === true) {
			return $this->metadata(entity: $entity, rowKey: $rowKey);
		}

		return null;
	}//end value()

	/**
	 * The score a row carries: `_search_score` in its data, else its relevance as a fraction, else 0.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return float|int
	 */
	private function score(ObjectEntity $entity): float|int {
		$data = $entity->getObject();
		if (isset($data[self::SCORE]) === true) {
			return (float) $data[self::SCORE];
		}

		if ($entity->getRelevance() !== null) {
			return ($entity->getRelevance() / 100);
		}

		return 0;
	}//end score()

	/**
	 * The entity's own field behind a metadata column, or null without one.
	 *
	 * @param ObjectEntity $entity The entity.
	 * @param string       $rowKey The metadata column.
	 *
	 * @return mixed
	 */
	private function metadata(ObjectEntity $entity, string $rowKey): mixed {
		$field = lcfirst(str_replace('_', '', ucwords(substr($rowKey, strlen(self::PREFIX)), '_')));
		if (property_exists($entity, $field) === false) {
			return null;
		}

		return $entity->{'get' . ucfirst($field)}();
	}//end metadata()

	/**
	 * A value as the comparator reads it: dates as `Y-m-d H:i:s.u`, booleans as ints, arrays as JSON.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function comparable(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return $value->format('Y-m-d H:i:s.u');
		}

		if (is_bool($value) === true) {
			return (int) $value;
		}

		if (is_array($value) === true) {
			return json_encode($value);
		}

		return $value;
	}//end comparable()
}//end class

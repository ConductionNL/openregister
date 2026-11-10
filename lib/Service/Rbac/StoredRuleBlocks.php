<?php

/**
 * The stored authorization blocks a schema or register save is judged against.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads schema and register authorization blocks past the mappers' caches and organisation filter.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class StoredRuleBlocks {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection|null $db The connection; without it nothing is stored and a save is judged as new.
	 */
	public function __construct(private readonly ?IDBConnection $db = null) {
	}//end __construct()

	/**
	 * The schema as stored, with the parts a save is judged against.
	 *
	 * @param int|null $id The schema's id; null when it is new.
	 *
	 * @return Schema|null The stored schema, or null when no row holds it.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function schema(?int $id): ?Schema {
		$row = $this->row(table: 'openregister_schemas', id: $id);
		if ($row === null) {
			return null;
		}

		$stored = new Schema();
		$stored->setAuthorization($this->decoded(value: ($row['authorization'] ?? null)));
		$stored->setProperties($this->decoded(value: ($row['properties'] ?? null)));

		return $stored;
	}//end schema()

	/**
	 * A register's authorization block as stored.
	 *
	 * @param int|null $id The register's id; null when it is new.
	 *
	 * @return array<int|string, mixed>|null The block, or null when none is stored.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function registerAuthorization(?int $id): ?array {
		return $this->decoded(value: ($this->row(table: 'openregister_registers', id: $id)['authorization'] ?? null));
	}//end registerAuthorization()

	/**
	 * The authorization blocks of a schema's properties, with what is stored for each.
	 *
	 * @param SchemaSave $save The schema and, when saving over one, the schema as stored.
	 * @param string     $name How a message names the schema.
	 *
	 * @return array<int, array{authorization: array, context: string, stored: array|null}> The blocks.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function propertyBlocks(SchemaSave $save, string $name): array {
		$storedProperties = ($save->stored?->getProperties() ?? []);
		$blocks = [];

		foreach (($save->schema->getProperties() ?? []) as $property => $config) {
			if (is_array($config) === false || is_array(($config['authorization'] ?? null)) === false) {
				continue;
			}

			$held = ($storedProperties[$property]['authorization'] ?? null);
			if (is_array($held) === false) {
				$held = null;
			}

			$blocks[] = [
				'authorization' => $config['authorization'],
				'context'       => sprintf('property "%s" of %s', (string)$property, $name),
				'stored'        => $held,
			];
		}

		return $blocks;
	}//end propertyBlocks()

	/**
	 * The stored row, read past the mappers' caches and organisation filter.
	 *
	 * @param string   $table The table.
	 * @param int|null $id    The row's id, null for a new entity.
	 *
	 * @return array<string, mixed>|null The row, or null when there is none.
	 */
	private function row(string $table, ?int $id): ?array {
		if ($id === null || $this->db === null) {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end row()

	/**
	 * A stored JSON column as an array, or null when it holds none.
	 *
	 * @param mixed $value The column value.
	 *
	 * @return array<int|string, mixed>|null The decoded value.
	 */
	private function decoded(mixed $value): ?array {
		if (is_string($value) === false) {
			return null;
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end decoded()
}//end class

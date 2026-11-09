<?php

/**
 * ReadsRowsById: find()/findAll() for a provider that reads every row and keys them by `id`.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * find() and findAll() over the rows readRows() returns.
 *
 * Each row is an array whose `id` is the object uuid; the row itself is the
 * object data. find() matches on `id`; findAll() pages the full row list by
 * `offset` (0) and `limit` (200).
 */
trait ReadsRowsById {

	/**
	 * Every row the acting user can see, failing closed to an empty list.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	abstract protected function readRows(): array;

	/**
	 * {@inheritDoc}
	 *
	 * @param Register             $register The register.
	 * @param Schema               $schema   The sourced schema.
	 * @param string               $id       The row id.
	 * @param array<string, mixed> $config   The object-source config block (unused).
	 *
	 * @return ObjectEntity|null The virtual object, or null when absent.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function find(Register $register, Schema $schema, string $id, array $config = []): ?ObjectEntity {
		foreach ($this->readRows() as $row) {
			if ((string)($row['id'] ?? '') === $id) {
				return $this->rowEntity(binding: new SourcedObjectBinding(register: $register, schema: $schema), row: $row);
			}
		}

		return null;
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * @param Register             $register The register.
	 * @param Schema               $schema   The sourced schema.
	 * @param array<string, mixed> $query    Query (limit/offset).
	 * @param array<string, mixed> $config   The object-source config block (unused).
	 *
	 * @return ObjectEntity[] The virtual objects.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function findAll(Register $register, Schema $schema, array $query = [], array $config = []): array {
		$limit   = (int)($query['limit'] ?? 200);
		$offset  = (int)($query['offset'] ?? 0);
		$binding = new SourcedObjectBinding(register: $register, schema: $schema);

		$objects = [];
		foreach (array_slice($this->readRows(), $offset, $limit) as $row) {
			$objects[] = $this->rowEntity(binding: $binding, row: $row);
		}

		return $objects;
	}//end findAll()

	/**
	 * Map a row onto a non-persisted virtual ObjectEntity keyed by its `id`.
	 *
	 * @param SourcedObjectBinding $binding The bound register and schema.
	 * @param array<string, mixed> $row     The row.
	 *
	 * @return ObjectEntity The virtual object (never saved).
	 */
	private function rowEntity(SourcedObjectBinding $binding, array $row): ObjectEntity {
		return $binding->toEntity(uuid: (string)($row['id'] ?? ''), data: $row);
	}//end rowEntity()
}//end trait

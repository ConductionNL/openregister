<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support\Schema;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\SchemaException as DoctrineSchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use OCP\DB\Schema\ColumnType;
use OCP\DB\Schema\IColumn;
use OCP\DB\Schema\IForeignKeyConstraint;
use OCP\DB\Schema\IIndex;
use OCP\DB\Schema\ITable;
use OCP\DB\Schema\SchemaException;

/**
 * An OCP ITable over a Doctrine table, for tests that run without a Nextcloud server.
 *
 * Nextcloud 35's ISchemaWrapper hands out `OC\DB\Schema\Table`, which ships
 * with the server and not with `nextcloud/ocp`. This adapter gives a bare
 * composer install the same contract, translating Doctrine's schema
 * exceptions to the OCP one as the server's wrapper does.
 */
final class DoctrineTable implements ITable {
	public function __construct(
		private readonly Table $table,
	) {
	}

	public function getWrappedTable(): Table {
		return $this->table;
	}

	public function getName(): string {
		return $this->table->getName();
	}

	public function setPrimaryKey(array $columnNames, string|false $indexName = false): self {
		return $this->apply(fn () => $this->table->setPrimaryKey($columnNames, $indexName));
	}

	public function addIndex(array $columnNames, ?string $indexName = null, array $flags = [], array $options = []): self {
		return $this->apply(fn () => $this->table->addIndex($columnNames, $indexName, $flags, $options));
	}

	public function addUniqueConstraint(array $columnNames, ?string $indexName = null, array $flags = [], array $options = []): self {
		return $this->apply(fn () => $this->table->addUniqueConstraint($columnNames, $indexName, $flags, $options));
	}

	public function hasUniqueConstraint(string $name): bool {
		return $this->table->hasUniqueConstraint($name);
	}

	public function removeUniqueConstraint(string $name): void {
		$this->apply(fn () => $this->table->removeUniqueConstraint($name));
	}

	public function dropPrimaryKey(): self {
		return $this->apply(fn () => $this->table->dropPrimaryKey());
	}

	public function getPrimaryKey(): ?IIndex {
		$primaryKey = $this->table->getPrimaryKey();
		if ($primaryKey === null) {
			return null;
		}

		return new DoctrineIndex($primaryKey);
	}

	public function hasPrimaryKey(): bool {
		return $this->table->hasPrimaryKey();
	}

	public function dropIndex(string $name): self {
		return $this->apply(fn () => $this->table->dropIndex($name));
	}

	public function hasIndex(string $name): bool {
		return $this->table->hasIndex($name);
	}

	public function addUniqueIndex(array $columnNames, ?string $indexName = null, array $options = []): self {
		return $this->apply(fn () => $this->table->addUniqueIndex($columnNames, $indexName, $options));
	}

	public function renameIndex(string $oldName, ?string $newName = null): self {
		return $this->apply(fn () => $this->table->renameIndex($oldName, $newName));
	}

	public function addColumn(string $name, string|ColumnType $typeName, array $options = []): IColumn {
		if ($typeName instanceof ColumnType) {
			$typeName = $typeName->value;
		}

		$column = null;
		$this->apply(function () use (&$column, $name, $typeName, $options) {
			$column = $this->table->addColumn($name, $typeName, $options);
		});
		return new DoctrineColumn($column);
	}

	public function modifyColumn(string $name, array $options): self {
		if (($options['type'] ?? null) instanceof ColumnType) {
			$options['type'] = $options['type']->value;
		}

		if (is_string($options['type'] ?? null) === true) {
			$options['type'] = Type::getType($options['type']);
		}

		return $this->apply(fn () => $this->table->modifyColumn($name, $options));
	}

	public function dropColumn(string $name): self {
		$this->table->dropColumn($name);
		return $this;
	}

	public function hasColumn(string $name): bool {
		return $this->table->hasColumn($name);
	}

	public function getColumn(string $name): IColumn {
		$column = null;
		$this->apply(function () use (&$column, $name) {
			$column = $this->table->getColumn($name);
		});
		return new DoctrineColumn($column);
	}

	public function getColumns(): array {
		return array_values(array_map(fn ($column) => new DoctrineColumn($column), $this->table->getColumns()));
	}

	public function getIndexes(): array {
		return array_values(array_map(fn ($index) => new DoctrineIndex($index), $this->table->getIndexes()));
	}

	public function getIndex(string $name): IIndex {
		return new DoctrineIndex($this->table->getIndex($name));
	}

	public function addForeignKeyConstraint(
		ITable|string $foreignTable,
		array $localColumnNames,
		array $foreignColumnNames,
		array $options = [],
		?string $name = null,
	): self {
		if ($foreignTable instanceof self) {
			$foreignTable = $foreignTable->getWrappedTable();
		}

		return $this->apply(
			fn () => $this->table->addForeignKeyConstraint($foreignTable, $localColumnNames, $foreignColumnNames, $options, $name)
		);
	}

	public function hasForeignKey(string $name): bool {
		return $this->table->hasForeignKey($name);
	}

	public function removeForeignKey(string $name): void {
		$this->apply(fn () => $this->table->removeForeignKey($name));
	}

	public function getForeignKeys(): array {
		return array_values(
			array_map(
				fn (ForeignKeyConstraint $key): IForeignKeyConstraint => new class($key) implements IForeignKeyConstraint {
					public function __construct(
						private readonly ForeignKeyConstraint $key,
					) {
					}

					public function getName(): string {
						return $this->key->getName();
					}
				},
				$this->table->getForeignKeys()
			)
		);
	}

	/**
	 * Doctrine table methods the OCP interface does not name.
	 *
	 * @param string $name      The method.
	 * @param array  $arguments Its arguments.
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $arguments): mixed {
		$result = null;
		$this->apply(function () use (&$result, $name, $arguments) {
			$result = $this->table->$name(...$arguments);
		});
		return $result;
	}

	/**
	 * Run a Doctrine schema change, raising Doctrine's schema exception as the OCP one.
	 *
	 * @param callable $change The change.
	 *
	 * @return self
	 *
	 * @throws SchemaException When Doctrine refuses the change.
	 */
	private function apply(callable $change): self {
		try {
			$change();
		} catch (DoctrineSchemaException $e) {
			throw new SchemaException($e->getMessage(), $e->getCode(), $e);
		}

		return $this;
	}
}

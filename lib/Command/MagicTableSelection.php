<?php

/**
 * Finds the magic tables an operator's --register and --schema options select.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\MagicMapper\MagicTableLocatorInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;

/**
 * Reads across tenants on purpose: these are operator commands over every table.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
class MagicTableSelection {

	/**
	 * Constructor.
	 *
	 * @param SchemaCatalogInterface $catalog The register, schema and magic-table mappers.
	 */
	public function __construct(private readonly SchemaCatalogInterface $catalog) {
	}//end __construct()

	/**
	 * Resolve the list of registers to operate on.
	 *
	 * @param string|null $registerRef Optional register slug, uuid or id.
	 *
	 * @return Register[]
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function registers(?string $registerRef): array {
		if ($registerRef !== null && $registerRef !== '') {
			return [$this->catalog->registers()->find($registerRef, _multitenancy: false)];
		}

		return $this->catalog->registers()->findAll(_rbac: false, _multitenancy: false);
	}//end registers()

	/**
	 * Resolve the list of schemas to operate on.
	 *
	 * @param string|null $schemaRef Optional schema slug, uuid or id.
	 *
	 * @return Schema[]
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function schemas(?string $schemaRef): array {
		if ($schemaRef !== null && $schemaRef !== '') {
			return [$this->catalog->schemas()->find($schemaRef, _multitenancy: false)];
		}

		return $this->catalog->schemas()->findAll(_rbac: false, _multitenancy: false);
	}//end schemas()

	/**
	 * The existing tables of these registers for these schemas.
	 *
	 * @param Register[] $registers The registers
	 * @param Schema[] $schemas The schemas
	 *
	 * @return MagicTableTarget[]
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function tables(array $registers, array $schemas): array {
		$schemasById = [];
		foreach ($schemas as $schema) {
			$schemasById[(int)$schema->getId()] = $schema;
		}

		$tables = [];
		foreach ($registers as $register) {
			array_push($tables, ...$this->tablesOf(register: $register, schemasById: $schemasById));
		}

		return $tables;
	}//end tables()

	/**
	 * The existing tables of one register.
	 *
	 * @param Register $register The register
	 * @param array<int, Schema> $schemasById The selected schemas by id
	 *
	 * @return MagicTableTarget[]
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	private function tablesOf(Register $register, array $schemasById): array {
		$tables = [];
		foreach ($register->getSchemas() as $schemaId) {
			$schema = ($schemasById[(int)$schemaId] ?? null);
			if ($schema === null || $this->locator()->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
				continue;
			}

			$tableName = $this->locator()->getTableNameForRegisterSchema(register: $register, schema: $schema);
			$tables[] = new MagicTableTarget(register: $register, schema: $schema, tableName: $tableName);
		}

		return $tables;
	}//end tablesOf()

	/**
	 * The magic-table role this selection uses.
	 *
	 * @return MagicTableLocatorInterface The magic table resolver.
	 */
	private function locator(): MagicTableLocatorInterface {
		return $this->catalog->tables();
	}//end locator()
}//end class

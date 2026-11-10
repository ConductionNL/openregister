<?php

/**
 * FileSidebarTable
 *
 * The magic table of one register/schema pair the file sidebar searches for
 * file references, and the register and schema summaries its results carry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/files-sidebar-tabs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One register/schema magic table and the summaries of its register and schema.
 *
 * @spec openspec/specs/files-sidebar-tabs/spec.md
 */
final readonly class FileSidebarTable {
	/**
	 * Constructor.
	 *
	 * @param Register $register The register.
	 * @param Schema   $schema   One of the register's schemas.
	 *
	 * @spec openspec/specs/files-sidebar-tabs/spec.md
	 */
	public function __construct(
		public Register $register,
		public Schema $schema,
	) {
	}//end __construct()

	/**
	 * The magic table name (without prefix).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/files-sidebar-tabs/spec.md
	 */
	public function tableName(): string {
		return 'openregister_table_' . $this->register->getId() . '_' . $this->schema->getId();
	}//end tableName()

	/**
	 * The register's id and title (or "Register {id}").
	 *
	 * @return array{id: int, title: string}
	 *
	 * @spec openspec/specs/files-sidebar-tabs/spec.md
	 */
	public function registerSummary(): array {
		return [
			'id' => $this->register->getId(),
			'title' => $this->register->getTitle() ?? 'Register ' . $this->register->getId(),
		];
	}//end registerSummary()

	/**
	 * The schema's id and title (or "Schema {id}").
	 *
	 * @return array{id: int, title: string}
	 *
	 * @spec openspec/specs/files-sidebar-tabs/spec.md
	 */
	public function schemaSummary(): array {
		return [
			'id' => $this->schema->getId(),
			'title' => $this->schema->getTitle() ?? 'Schema ' . $this->schema->getId(),
		];
	}//end schemaSummary()
}//end class

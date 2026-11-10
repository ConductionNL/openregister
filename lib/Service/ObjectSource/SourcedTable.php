<?php

/**
 * SourcedTable: the register and schema an object source serves, with the source's config.
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
 * @spec openspec/changes/object-source-providers/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\RegisterSchema;

/**
 * The register and schema one object-source call reads or writes, and the schema's object-source `config` block.
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-1.1
 */
final class SourcedTable {

	/**
	 * Constructor.
	 *
	 * @param RegisterSchema       $table  The register and the sourced schema.
	 * @param array<string, mixed> $config The schema's object-source `config` block.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-1.1
	 */
	public function __construct(
		public readonly RegisterSchema $table,
		public readonly array $config=[],
	) {
	}//end __construct()

	/**
	 * The register and schema the source's objects are projected into.
	 *
	 * @return SourcedObjectBinding The binding of this table.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-5.1
	 */
	public function binding(): SourcedObjectBinding {
		return new SourcedObjectBinding(register: $this->table->register, schema: $this->table->schema);
	}//end binding()
}//end class

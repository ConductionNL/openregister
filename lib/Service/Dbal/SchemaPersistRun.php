<?php

/**
 * OpenRegister schema persist run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use OCA\OpenRegister\Db\Schema;

/**
 * The state of one persistence pass of {@see DatabaseIntrospectionService::introspect()}:
 * the register's existing schemas by table, and what the pass created, updated and
 * found drifted so far. It never leaves the service that built it.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class SchemaPersistRun {

	/**
	 * The register's existing schemas, by their bound source table name.
	 *
	 * @var array<string, Schema>
	 */
	public array $existingSchemas = [];

	/**
	 * The tables whose schema this pass created, in blueprint order.
	 *
	 * @var array<int, string>
	 */
	public array $created = [];

	/**
	 * The tables whose schema this pass updated, in blueprint order.
	 *
	 * @var array<int, string>
	 */
	public array $updated = [];

	/**
	 * The structural drift found on updated tables.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $drift = [];

	/**
	 * The ids of every schema this pass wrote, in blueprint order.
	 *
	 * @var array<int, int>
	 */
	public array $schemaIds = [];
}//end class

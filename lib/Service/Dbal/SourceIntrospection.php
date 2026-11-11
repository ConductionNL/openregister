<?php

/**
 * OpenRegister source introspection
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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;


/**
 * One introspection run over a database source: the open connection, the
 * schema manager it yields, and the id of the source being introspected.
 * Built once per run by DatabaseIntrospectionService; every table and view of
 * the run is read through it.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class SourceIntrospection {
	/**
	 * The schema manager of the open connection, created once per run.
	 *
	 * @var AbstractSchemaManager
	 */
	public readonly AbstractSchemaManager $schemaManager;

	/**
	 * The open connection.
	 *
	 * @var Connection
	 */
	public readonly Connection $connection;

	/**
	 * The owning source id.
	 *
	 * @var string
	 */
	public readonly string $sourceId;

	/**
	 * Open the run over a connection.
	 *
	 * @param Connection $connection The open connection.
	 * @param string     $sourceId   The owning source id.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function __construct(Connection $connection, string $sourceId) {
		$this->connection    = $connection;
		$this->sourceId      = $sourceId;
		$this->schemaManager = $connection->createSchemaManager();
	}//end __construct()
}//end class

<?php

/**
 * CountsThroughFindAll: an object-source count that is the number of objects findAll() returns.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * count() for a provider whose sources cannot count without reading.
 */
trait CountsThroughFindAll {

	/**
	 * {@inheritDoc}
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The sourced schema.
	 * @param array<string, mixed> $query    Query (filters/limit/offset), as for findAll().
	 * @param array<string, mixed> $config   The object-source config block.
	 *
	 * @return int The number of matching virtual objects.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function count(Register $register, Schema $schema, array $query = [], array $config = []): int {
		return count($this->findAll(register: $register, schema: $schema, query: $query, config: $config));
	}//end count()
}//end trait

<?php

/**
 * SchemaRunStoreInterface: what callers outside the Db component ask of SchemaRunMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Reading and saving a schema run, as the schema run job does. Only the methods that caller calls.
 */
interface SchemaRunStoreInterface {

	/**
	 * Find a run by id.
	 *
	 * @param int $id The run id.
	 *
	 * @return SchemaRun The run.
	 *
	 * @throws DoesNotExistException When not found.
	 */
	public function find(int $id): SchemaRun;

	/**
	 * Persist run progress/state changes, refreshing the updated timestamp.
	 *
	 * @param SchemaRun $run The run to persist.
	 *
	 * @return SchemaRun The updated run.
	 */
	public function save(SchemaRun $run): SchemaRun;
}//end interface

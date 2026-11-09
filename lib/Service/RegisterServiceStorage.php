<?php

/**
 * OpenRegister Register Service Storage
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IDBConnection;

/**
 * Where {@see RegisterService} reads and writes: the register and schema tables, and the raw connection
 * its magic-table counts run on.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final readonly class RegisterServiceStorage {

	/**
	 * Bundle the collaborators.
	 *
	 * @param RegisterMapperInterface $registerMapper Register mapper for database operations.
	 * @param SchemaMapperInterface   $schemaMapper   Schema mapper for schema operations.
	 * @param IDBConnection           $db             Database connection for custom queries.
	 */
	public function __construct(
		public RegisterMapperInterface $registerMapper,
		public SchemaMapperInterface $schemaMapper,
		public IDBConnection $db,
	) {
	}//end __construct()
}//end class

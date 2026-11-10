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
use OCA\OpenRegister\Db\SchemaCatalogInterface;
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
	 * Register mapper for database operations.
	 *
	 * @var RegisterMapperInterface
	 */
	public RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper for schema operations.
	 *
	 * @var SchemaMapperInterface
	 */
	public SchemaMapperInterface $schemaMapper;

	/**
	 * Bundle the collaborators.
	 *
	 * @param IDBConnection          $db      Database connection for custom queries.
	 * @param SchemaCatalogInterface $catalog Register, schema and magic-table mappers.
	 */
	public function __construct(
		public IDBConnection $db,
		SchemaCatalogInterface $catalog,
	) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper   = $catalog->schemas();
	}//end __construct()
}//end class

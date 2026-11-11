<?php

/**
 * OpenRegister archival retention sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IDBConnection;

/**
 * The database and the register, schema and magic-table lookups the archival sweep scans rows through.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */
final readonly class ArchivalRetentionSources {
	/**
	 * Register repository.
	 *
	 * @var RegisterMapperInterface
	 */
	public RegisterMapperInterface $registerMapper;

	/**
	 * Schema repository.
	 *
	 * @var SchemaMapperInterface
	 */
	public SchemaMapperInterface $schemaMapper;

	/**
	 * Magic-table resolver.
	 *
	 * @var MagicMapperInterface
	 */
	public MagicMapperInterface $magicMapper;

	/**
	 * Bundle the services.
	 *
	 * @param IDBConnection          $db      Database connection for the native row scan.
	 * @param SchemaCatalogInterface $catalog The register, schema and magic-table mappers.
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
	 */
	public function __construct(public IDBConnection $db, SchemaCatalogInterface $catalog) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper = $catalog->schemas();
		$this->magicMapper = $catalog->tables();
	}//end __construct()
}//end class

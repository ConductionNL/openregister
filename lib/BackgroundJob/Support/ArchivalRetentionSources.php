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
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IDBConnection;

/**
 * The database and the register, schema and magic-table lookups the archival sweep scans rows through.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */
final readonly class ArchivalRetentionSources {
	/**
	 * Bundle the services.
	 *
	 * @param IDBConnection           $db             Database connection for the native row scan.
	 * @param RegisterMapperInterface $registerMapper Register repository.
	 * @param SchemaMapperInterface   $schemaMapper   Schema repository.
	 * @param MagicMapperInterface    $magicMapper    Magic-table resolver.
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
	 */
	public function __construct(
		public IDBConnection $db,
		public RegisterMapperInterface $registerMapper,
		public SchemaMapperInterface $schemaMapper,
		public MagicMapperInterface $magicMapper,
	) {
	}//end __construct()
}//end class

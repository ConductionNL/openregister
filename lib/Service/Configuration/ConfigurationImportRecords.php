<?php

/**
 * OpenRegister ConfigurationImportRecords
 *
 * Where a configuration import records what it did.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Db\MappingMapperInterface;
use OCP\IAppConfig;

/**
 * The configuration rows, the slug-to-id mappings, the imported version and hash keys,
 * and the import job an import is stamped with.
 *
 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
 */
final class ConfigurationImportRecords {

	/**
	 * Constructor.
	 *
	 * @param ConfigurationMapperInterface $configurations The configuration rows.
	 * @param MappingMapperInterface       $mappings       The slug-to-id mappings.
	 * @param IAppConfig                   $appConfig      The imported version and hash keys.
	 * @param AppImportJobRecorder|null    $importJobs     Stamps each app import with an import job
	 *                                                     id and records the jobs that created
	 *                                                     objects; null imports untagged.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ConfigurationMapperInterface $configurations,
		public readonly MappingMapperInterface $mappings,
		public readonly IAppConfig $appConfig,
		public readonly ?AppImportJobRecorder $importJobs = null,
	) {
	}//end __construct()
}//end class

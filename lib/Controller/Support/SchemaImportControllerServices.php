<?php

/**
 * OpenRegister schema import controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\SchemaImport\SchemaImportServiceInterface;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;

/**
 * The services of the schema import API.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final class SchemaImportControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaImportServiceInterface      $importService    Standards import orchestrator.
	 * @param SchemaMapperInterface    $schemaMapper     Schema persistence.
	 * @param RegisterMapperInterface  $registerMapper   Register lookup/association.
	 * @param ?SchemaVersioningService $schemaVersioning Classifies, versions and logs a merged definition change (#4102).
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function __construct(
		public readonly SchemaImportServiceInterface $importService,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly ?SchemaVersioningService $schemaVersioning = null,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister schema run services
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

use OCA\OpenRegister\Db\SchemaRunStoreInterface;
use OCA\OpenRegister\Service\Schema\SchemaBatchMigratorInterface;
use OCA\OpenRegister\Service\Schema\SchemaRevalidatorInterface;

/**
 * The run store and the revalidation and migration passes a schema run drives.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final readonly class SchemaRunServices {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaRunStoreInterface      $runMapper           Run store.
	 * @param SchemaRevalidatorInterface   $revalidationService Revalidation pass.
	 * @param SchemaBatchMigratorInterface $migrationService    Migration pass.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function __construct(
		public SchemaRunStoreInterface $runMapper,
		public SchemaRevalidatorInterface $revalidationService,
		public SchemaBatchMigratorInterface $migrationService,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister schema migration domain
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

use OCA\OpenRegister\Service\Schema\PropertyConversionServiceInterface;
use OCA\OpenRegister\Service\Schema\SchemaMigrationServiceInterface;
use OCA\OpenRegister\Service\Schema\SchemaObjectReaderInterface;
use OCA\OpenRegister\Service\Schema\SchemaRevalidatorInterface;

/**
 * The domain services of the schema migration API.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaMigrationDomain {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaRevalidatorInterface          $revalidationService Revalidation engine.
	 * @param SchemaMigrationServiceInterface     $migrationService    Migration engine.
	 * @param SchemaObjectReaderInterface         $objectReader        Locates a schema's register and reads its stored values.
	 * @param ?PropertyConversionServiceInterface $conversions         Publishes and previews a property type change.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly SchemaRevalidatorInterface $revalidationService,
		public readonly SchemaMigrationServiceInterface $migrationService,
		public readonly SchemaObjectReaderInterface $objectReader,
		public readonly ?PropertyConversionServiceInterface $conversions = null,
	) {
	}//end __construct()
}//end class

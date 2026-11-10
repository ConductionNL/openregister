<?php

/**
 * OpenRegister schema migration controller services
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

/**
 * The service bundles of the schema migration API.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaMigrationControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMigrationMappers  $mappers  The mappers bundle.
	 * @param SchemaMigrationDomain   $domain   The domain bundle.
	 * @param RetentionPlatform       $platform The platform bundle.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly SchemaMigrationMappers $mappers,
		public readonly SchemaMigrationDomain $domain,
		public readonly RetentionPlatform $platform,
	) {
	}//end __construct()
}//end class

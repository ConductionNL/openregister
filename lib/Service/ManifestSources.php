<?php

/**
 * The object and schema lookups a manifest reads data through.
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

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The object and schema lookups a manifest reads data through.
 *
 * @spec openspec/specs/openregister-app-manifest/spec.md
 */
final class ManifestSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOperationsInterface $objectService OR object retrieval service.
	 * @param SchemaMapperInterface     $schemaMapper  Schema lookup by slug.
	 *
	 * @spec openspec/specs/openregister-app-manifest/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class

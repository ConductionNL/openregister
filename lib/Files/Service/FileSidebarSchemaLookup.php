<?php

/**
 * The register and schema lookups of the file sidebar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
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

namespace OCA\OpenRegister\Files\Service;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The register and schema mappers the file sidebar service looks objects up through.
 *
 * @spec openspec/specs/files-sidebar-tabs/spec.md
 */
final class FileSidebarSchemaLookup {
	/**
	 * Bundle the collaborators.
	 *
	 * @param RegisterMapperInterface $registerMapper Register mapper for RBAC-aware register lookups.
	 * @param SchemaMapperInterface   $schemaMapper   Schema mapper for schema lookups.
	 *
	 * @spec openspec/specs/files-sidebar-tabs/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class

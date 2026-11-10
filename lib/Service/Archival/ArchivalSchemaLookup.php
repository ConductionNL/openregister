<?php

/**
 * OpenRegister ArchivalSchemaLookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the schema behind an object for its archival declarations, and
 * reports a schema that cannot be read.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class ArchivalSchemaLookup {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaMapperInterface $schemaMapper Loads the object's schema.
	 * @param LoggerInterface       $logger       Where an unreadable schema is reported.
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class

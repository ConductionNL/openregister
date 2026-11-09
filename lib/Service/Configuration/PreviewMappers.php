<?php

/**
 * OpenRegister configuration preview mappers
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The mappers a configuration preview reads the local registers, schemas and objects from.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class PreviewMappers {
	/**
	 * Bundle the mappers.
	 *
	 * @param RegisterMapperInterface $registerMapper The register mapper.
	 * @param SchemaMapperInterface   $schemaMapper   The schema mapper.
	 * @param MagicMapperInterface    $objectMapper   The object mapper, to find the local object a remote one would update.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly MagicMapperInterface $objectMapper,
	) {
	}//end __construct()
}//end class

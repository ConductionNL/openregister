<?php

/**
 * The stores an export profile and the register and schema it names are read from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\ExportProfileMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * A dependency bundle of {@see ExportProfileService}.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportProfileStores {
	/**
	 * Bundle the services.
	 *
	 * @param ExportProfileMapperInterface $mapper         Profile persistence.
	 * @param RegisterMapperInterface      $registerMapper Register lookups.
	 * @param SchemaMapperInterface        $schemaMapper   Schema lookups.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ExportProfileMapperInterface $mapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()
}//end class

<?php

/**
 * OpenRegister bulk save stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\SchemaCatalogInterface;

/**
 * Where a bulk save reads its registers and schemas and writes its rows.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveStores {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface $catalog     The register, schema and object table stores
	 * @param SaveObjectInterface    $saveHandler Handler for individual object operations
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly SchemaCatalogInterface $catalog,
		public readonly SaveObjectInterface $saveHandler,
	) {
	}//end __construct()
}//end class

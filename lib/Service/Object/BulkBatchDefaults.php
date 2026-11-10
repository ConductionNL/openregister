<?php

/**
 * BulkBatchDefaults
 *
 * The register and schema a bulk save names for every row that names none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The default register and schema of one bulk batch, as the caller passed them.
 *
 * No strict_types: SaveObjects declares none, and these values arrive from it.
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */
final class BulkBatchDefaults {

	/**
	 * Constructor.
	 *
	 * @param Register|string|int|null $register Default register context.
	 * @param Schema|string|int|null   $schema   Default schema context (null = mixed-schema).
	 */
	public function __construct(
		public readonly Register|string|int|null $register=null,
		public readonly Schema|string|int|null $schema=null,
	) {
	}//end __construct()
}//end class

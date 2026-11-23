<?php

/**
 * OpenRegister ImportTarget
 *
 * Where spreadsheet rows land, either part possibly open, and who imports them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * The register and schema spreadsheet rows land in, and the importing user (ADR-011 Rule 2).
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportTarget {
	/**
	 * Build the target.
	 *
	 * @param Register|null $register    The register, when known.
	 * @param Schema|null   $schema      The schema, when known.
	 * @param IUser|null    $currentUser The user performing the import.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ?Register $register,
		public readonly ?Schema $schema,
		public readonly ?IUser $currentUser = null,
	) {
	}//end __construct()
}//end class

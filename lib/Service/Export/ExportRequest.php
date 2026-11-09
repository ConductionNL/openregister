<?php

/**
 * OpenRegister ExportRequest
 *
 * What one object export covers: the register and schema, the filters and the user it runs for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
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

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * What one object export covers: the register and schema, the filters and the user it runs for.
 *
 * A null schema with a register means every schema of that register.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportRequest {
	/**
	 * Describe one export.
	 *
	 * @param Register|null        $register    The register exported, or null for none.
	 * @param Schema|null          $schema      The schema exported, or null for every schema of the register.
	 * @param array<string, mixed> $filters     The request filters (`@self.*` metadata, properties, `_ids`, `_multi`).
	 * @param IUser|null           $currentUser The user the export runs for; drives the visible columns.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ?Register $register = null,
		public readonly ?Schema $schema = null,
		public readonly array $filters = [],
		public readonly ?IUser $currentUser = null,
	) {
	}//end __construct()
}//end class

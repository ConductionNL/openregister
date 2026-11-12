<?php

/**
 * Declared Row
 *
 * One register or schema row a shared master data declaration may be made on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * A row of the registers or schemas table, as SharedMasterDataService looks up its declaration.
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */
final class DeclaredRow {
	/**
	 * Constructor.
	 *
	 * @param string   $table Either SharedMasterDataService::REGISTERS or ::SCHEMAS.
	 * @param int|null $id    The row id; null when not known, which matches no declaration.
	 */
	public function __construct(
		public readonly string $table,
		public readonly ?int $id,
	) {
	}//end __construct()
}//end class

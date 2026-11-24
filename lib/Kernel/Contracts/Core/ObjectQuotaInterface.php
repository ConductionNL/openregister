<?php
/**
 * ObjectQuotaInterface: a schema's per-organisation object cap, as the bulk save path holds creates to it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\Schema;

/**
 * The per-organisation object quota of a schema.
 *
 * The bulk save path (Core) holds its creates to the cap; Processes keeps the quota
 * rules and binds its answer in its own wiring.
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
 */
interface ObjectQuotaInterface {
	/**
	 * The per-organisation cap a schema declares, or null when it declares none.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return int|null The cap, a positive integer, or null for no quota.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function limitFor(Schema $schema): ?int;

	/**
	 * How many objects of this register and schema the organisation holds.
	 *
	 * Soft-deleted rows are not counted: the search excludes them, as it does
	 * for every list.
	 *
	 * @param int    $registerId       The register id.
	 * @param Schema $schema           The schema.
	 * @param string $organisationUuid The organisation's uuid.
	 *
	 * @return int The count.
	 *
	 * @throws \Throwable When the count cannot be made (it never answers 0 for a failure).
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function count(int $registerId, Schema $schema, string $organisationUuid): int;
}//end interface

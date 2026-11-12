<?php

/**
 * ObjectQuotaServiceInterface: ObjectQuotaService as the object quota listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quota
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

namespace OCA\OpenRegister\Service\Quota;

use OCA\OpenRegister\Db\Schema;

/**
 * ObjectQuotaService, typed for callers outside the Service component.
 *
 * Its outside caller, the object quota listener, calls limitFor() and count(), so those are
 * the whole contract. Binding it in ContractBindings lets that caller resolve
 * ObjectQuotaService without naming the class.
 */
interface ObjectQuotaServiceInterface {
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

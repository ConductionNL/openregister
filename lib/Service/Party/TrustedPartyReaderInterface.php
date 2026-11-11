<?php

/**
 * The party records, plus the unscoped lookup a trusted write needs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The party records as a trusted writer reads them.
 *
 * Kept apart from {@see PartyServiceInterface} so a caller that may only read
 * with its caller's rights never sees the unscoped lookup. The container binds
 * it to {@see PartyService} in ContractBindings.
 */
interface TrustedPartyReaderInterface extends PartyServiceInterface {

	/**
	 * The object with that uuid, read without RBAC or multitenancy; null when there is none.
	 *
	 * For a trusted write with no user to read as. Never for a request, which
	 * reads with its caller's rights through `find`.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return ObjectEntity|null The object.
	 *
	 * @spec openspec/changes/example-set-install-agrees-with-the-store/specs/example-sets/spec.md
	 */
	public function findUnscoped(string $uuid): ?ObjectEntity;
}//end interface

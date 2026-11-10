<?php

/**
 * CospendLinkServiceInterface: the Cospend link operations of CospendLinkService that controllers use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\CospendLink;

/**
 * The methods of CospendLinkService that callers outside the Service component use.
 */
interface CospendLinkServiceInterface {

	/**
	 * Whether NC Cospend is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isCospendAvailable(): bool;

	/**
	 * Link an existing NC Cospend project to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Project name +
	 * currency are cached at link time.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $projectId NC Cospend project id.
	 *
	 * @return CospendLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing project (404),
	 *                   duplicate (409), Cospend unavailable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the link contract is owned by the integration-cospend capability.
	 */
	public function linkProject(LinkTarget $target, string $projectId): CospendLink;

	/**
	 * Link a specific NC Cospend bill (under a project) to an OR object.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $projectId NC Cospend project id.
	 * @param int $billId NC Cospend bill id.
	 *
	 * @return CospendLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing bill (404),
	 *                   duplicate (409), Cospend unavailable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the link contract is owned by the integration-cospend capability.
	 */
	public function linkBill(LinkTarget $target, string $projectId, int $billId): CospendLink;

	/**
	 * Create a new NC Cospend project and link it to an OR object.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $name New project name.
	 * @param string $currency Project currency name.
	 *
	 * @return CospendLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty name (400),
	 *                   Cospend unavailable (503), create failure (500).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link contract is owned by the integration-cospend capability.
	 */
	public function createAndLinkProject(LinkTarget $target, string $name, string $currency): CospendLink;

	/**
	 * Unlink a project / bill from an object.
	 *
	 * Does NOT delete the project/bill itself — it stays in NC Cospend.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $entryId Link row id.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink contract is owned by the integration-cospend capability.
	 */
	public function unlink(string $objectUuid, int $entryId): void;

	/**
	 * Return the linked entries for an object, refreshing the cached
	 * amount/currency columns when a row is older than 24h.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the linked-entries listing contract is owned
	 *              by the integration-cospend capability.
	 */
	public function getLinkedEntries(string $objectUuid): array;

	/**
	 * Return the current user's NC Cospend projects (picker source).
	 *
	 * Optionally filtered by a name substring. Returns an empty array
	 * when Cospend is unavailable.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the picker-source contract is owned by the integration-cospend capability.
	 */
	public function getAvailableProjects(?string $search = null): array;
}//end interface

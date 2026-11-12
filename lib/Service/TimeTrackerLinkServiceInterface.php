<?php

/**
 * TimeTrackerLinkServiceInterface: the part of TimeTrackerLinkService that its controller, the relations aggregation and the reconcile command use.
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
use OCA\OpenRegister\Db\TimeTrackerLink;
use OCA\OpenRegister\Service\TimeTracker\TimeEntry;

/**
 * The methods of TimeTrackerLinkService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface TimeTrackerLinkServiceInterface {

	/**
	 * Whether NC TimeManager is installed + enabled for the current user.
	 *
	 * @return bool
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the availability probe is owned by the integration-time-tracker capability.
	 */
	public function isTimeManagerAvailable(): bool;

	/**
	 * Link an existing NC TimeManager entry to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Entry metadata
	 * (name/duration/billable/started_at) is cached at link time.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param TimeEntry  $entry  The entry kind (`client`|`task`|`time`) and its upstream uuid.
	 *
	 * @return TimeTrackerLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), bad type (400), missing
	 *                   entry (404), duplicate (409), TimeManager
	 *                   unavailable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the link-entry contract is owned by the integration-time-tracker capability.
	 */
	public function linkEntry(LinkTarget $target, TimeEntry $entry): TimeTrackerLink;

	/**
	 * Unlink an entry from an object.
	 *
	 * Does NOT delete the upstream entry — it stays in NC TimeManager.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param string $entryId Upstream entry uuid.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink contract is owned by the integration-time-tracker capability.
	 */
	public function unlink(string $objectUuid, string $entryId): void;

	/**
	 * Return the linked entries for an object, refreshing the cached
	 * name/duration/billable/started_at columns when a row is older
	 * than 24h.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the linked-entries listing contract is
	 *       owned by the integration-time-tracker capability.
	 */
	public function getLinkedEntries(string $objectUuid): array;

	/**
	 * Return the current user's NC TimeManager clients (picker source).
	 *
	 * Returns an empty array when TimeManager is unavailable.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the picker-source contract is owned by the integration-time-tracker capability.
	 */
	public function getAvailableClients(?string $search = null): array;

	/**
	 * Create a new NC TimeManager client and link it to an OR object.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $name New client name.
	 *
	 * @return TimeTrackerLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty name (400),
	 *                   TimeManager unavailable (503), create failure (500).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link-client contract is
	 *       owned by the integration-time-tracker capability.
	 */
	public function createAndLinkClient(LinkTarget $target, string $name): TimeTrackerLink;

	/**
	 * Reconcile every persisted link row's denormalised entry metadata
	 * (name, duration, billable, started_at) against the authoritative
	 * NC TimeManager row.
	 *
	 * Used by the `openregister:time:reconcile` occ command — exposes
	 * the same refresh path the on-read drift-guard uses, but driven
	 * for every link irrespective of staleness so totals can be
	 * recomputed off-line (per the integration-time-tracker spec's
	 * "denormalised totals" requirement).
	 *
	 * Each link is refreshed using its own `linkedBy` user id so the
	 * upstream user-scoped TimeManager lookup stays correct even when
	 * the command runs without an active user session (e.g. cron).
	 *
	 * @param string|null $objectUuid Optional object uuid scope. Null walks every row.
	 * @param bool $dryRun When true, no DB writes happen; counts are returned regardless.
	 *
	 * @return array{walked: int, refreshed: int, missing: int, errors: int}
	 *
	 * @spec openspec/specs/integration-time-tracker/spec.md
	 */
	public function reconcileAllLinks(?string $objectUuid = null, bool $dryRun = false): array;
}//end interface

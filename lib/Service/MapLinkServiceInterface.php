<?php

/**
 * MapLinkServiceInterface: what callers outside the Service component ask of MapLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\MapLink;

/**
 * Links Nextcloud Maps points of interest to objects.
 */
interface MapLinkServiceInterface {

	/**
	 * Create a new NC Maps favorite (POI) and link it to an OR object.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param PoiDraft   $poi    Name, position and optional category and comment.
	 *
	 * @return MapLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty name (400),
	 *                   Maps unavailable (503), create failure (500).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link-POI contract is owned by the integration-maps capability.
	 */
	public function createAndLinkPoi(LinkTarget $target, PoiDraft $poi): MapLink;

	/**
	 * Return the current user's NC Maps favorites (picker source).
	 *
	 * Optional substring search against the POI name. Returns an empty
	 * array when Maps is unavailable.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the picker-source contract is owned by the integration-maps capability.
	 */
	public function getAvailablePois(?string $search = null): array;

	/**
	 * Return the linked POIs for an object.
	 *
	 * Served straight from the cached link rows (name/category/lat/lng/
	 * comment are captured at link time), with a deep link into NC Maps
	 * appended.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the linked-POIs listing contract is owned by the integration-maps capability.
	 */
	public function getLinkedPois(string $objectUuid): array;

	/**
	 * Whether NC Maps is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isMapsAvailable(): bool;

	/**
	 * Link an existing NC Maps favorite (POI) to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. POI metadata
	 * (name/category/lat/lng/comment) is cached at link time.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int $favoriteId NC Maps favorite id.
	 *
	 * @return MapLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing POI (404),
	 *                   duplicate (409), Maps unavailable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the link-POI contract is owned by the integration-maps capability.
	 */
	public function linkPoi(LinkTarget $target, int $favoriteId): MapLink;

	/**
	 * Unlink a POI from an object.
	 *
	 * Does NOT delete the favorite itself — it stays in NC Maps for the
	 * user and for any other linked objects.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $favoriteId NC Maps favorite id.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-POI contract is owned by the integration-maps capability.
	 */
	public function unlinkPoi(string $objectUuid, int $favoriteId): void;
}//end interface

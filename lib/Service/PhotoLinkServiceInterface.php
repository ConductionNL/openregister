<?php

/**
 * PhotoLinkServiceInterface: the part of PhotoLinkService that its controller and the relations aggregation use.
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
use OCA\OpenRegister\Db\PhotoLink;

/**
 * The methods of PhotoLinkService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface PhotoLinkServiceInterface {

	/**
	 * Whether NC Photos is installed + enabled for the current user.
	 *
	 * @return bool
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the availability probe is owned by the integration-photos capability.
	 */
	public function isPhotosAvailable(): bool;

	/**
	 * Link an existing NC Photos album to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Album
	 * metadata (name/cover/count/last_edited) is cached at link time.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int $albumId NC Photos album id.
	 *
	 * @return PhotoLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing album (404),
	 *                   duplicate (409), Photos unavailable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the link-album contract is owned by the integration-photos capability.
	 */
	public function linkAlbum(LinkTarget $target, int $albumId): PhotoLink;

	/**
	 * Unlink an album from an object.
	 *
	 * Does NOT delete the album itself — it stays in NC Photos for the
	 * user and for any other linked objects.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $albumId NC Photos album id.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-album contract is owned by the integration-photos capability.
	 */
	public function unlinkAlbum(string $objectUuid, int $albumId): void;

	/**
	 * Return the linked albums for an object, refreshing the cached
	 * cover/count/last_edited columns when a row is older than 24h.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the linked-albums listing contract is owned by the integration-photos capability.
	 */
	public function getLinkedAlbums(string $objectUuid): array;

	/**
	 * Return the current user's NC Photos albums (picker source).
	 *
	 * Optional substring search against the album name. Returns an empty
	 * array when Photos is unavailable.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the picker-source contract is owned by the integration-photos capability.
	 */
	public function getAvailableAlbums(?string $search = null): array;

	/**
	 * Create a new NC Photos album and link it to an OR object.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $name New album name.
	 *
	 * @return PhotoLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty name (400),
	 *                   Photos unavailable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link-album contract is owned by the integration-photos capability.
	 */
	public function createAndLinkAlbum(LinkTarget $target, string $name): PhotoLink;
}//end interface

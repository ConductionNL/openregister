<?php

/**
 * PhotoLinkService — Tier-2 photos (NC Photos) integration service.
 *
 * Composes the {@see PhotoLinkMapper} with NC Photos'
 * `OCA\Photos\Album\AlbumMapper` to expose the Tier-2 surface:
 *
 *   - linkAlbum(uuid, registerId, schemaId, albumId)
 *       — link an existing album
 *   - createAndLinkAlbum(uuid, registerId, schemaId, name)
 *       — create a new NC Photos album and link it
 *   - unlinkAlbum(uuid, albumId)
 *       — remove a link (the album itself stays in NC Photos)
 *   - getLinkedAlbums(uuid)
 *       — list linked albums, refreshing cached cover/count/last_edited
 *       when the row is older than 24h
 *   - getAvailableAlbums(?search)
 *       — picker source listing the current user's albums
 *
 * NC Photos exposes its album persistence as `OCA\Photos\Album\AlbumMapper`
 * (NOT `OCA\Photos\Service\AlbumService` — that class does not exist;
 * wave-2.1 correction). The mapper is resolved lazily through the server
 * container behind a `Throwable` guard so this service loads even when the
 * Photos app is not installed (ADR-019 AD-23 graceful degradation): when
 * Photos is missing or a mapper call throws, the stored link row is
 * returned as-is so historical references survive.
 *
 * Albums are user-scoped: NC Photos albums belong to a user, so every
 * mutating + picker call is scoped to the active session's UID.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\PhotoLink;
use OCA\OpenRegister\Db\PhotoLinkMapperInterface;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * PhotoLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes mapper +
 *     NC Photos AlbumMapper (late-bound) + user session + app manager +
 *     url generator + container + logger. Each dependency is required
 *     for one of the Tier-2 flows (link, create, unlink, list, picker,
 *     cache refresh, graceful degradation).
 */
class PhotoLinkService implements PhotoLinkServiceInterface {
	private const REQUIRED_APP = 'photos';

	private const ALBUM_MAPPER = 'OCA\\Photos\\Album\\AlbumMapper';

	private const STALE_AFTER = 86400;
	// 24 hours in seconds.

	/**
	 * Constructor.
	 *
	 * @param PhotoLinkMapperInterface $photoLinkMapper Persistence for link rows.
	 * @param ContainerInterface $container Container for late-bound Photos classes.
	 * @param IURLGenerator $urlGenerator URL generator for deep links / thumbnails.
	 * @param LinkServiceRuntime $runtime Whether the app is enabled, the current user, and the logger.
	 */
	public function __construct(
		private readonly PhotoLinkMapperInterface $photoLinkMapper,
		private readonly ContainerInterface $container,
		private readonly IURLGenerator $urlGenerator,
		private readonly LinkServiceRuntime $runtime,
	) {
	}//end __construct()

	/**
	 * Whether NC Photos is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isPhotosAvailable(): bool {
		return $this->runtime->appManager()->isEnabledForUser(self::REQUIRED_APP);
	}//end isPhotosAvailable()

	/**
	 * Resolve NC Photos' AlbumMapper lazily.
	 *
	 * Returns null when Photos is absent or the class can't be
	 * resolved, so callers degrade gracefully (ADR-019 AD-23).
	 *
	 * @return object|null The AlbumMapper instance or null.
	 */
	private function getAlbumMapper(): ?object {
		if ($this->isPhotosAvailable() === false) {
			return null;
		}

		try {
			return $this->container->get(self::ALBUM_MAPPER);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('PhotoLinkService: AlbumMapper unavailable: ' . $e->getMessage());
			return null;
		}
	}//end getAlbumMapper()

	/**
	 * Active session UID, or throw if no user is logged in.
	 *
	 * @return string The user id.
	 *
	 * @throws Exception When there is no active user.
	 */
	private function requireUid(): string {
		return $this->runtime->userSession()->getUser()?->getUID() ?? throw new Exception('No user logged in', 401);
	}//end requireUid()

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
	public function linkAlbum(LinkTarget $target, int $albumId): PhotoLink {
		$uid = $this->requireUid();

		if ($this->isPhotosAvailable() === false) {
			throw new Exception('NC Photos is not available', 503);
		}

		$existing = $this->photoLinkMapper->findByObjectAndAlbum($target->objectUuid, $albumId);
		if ($existing !== null) {
			throw new Exception('Album already linked to this object', 409);
		}

		$info = $this->fetchAlbumInfo(albumId: $albumId, uid: $uid) ?? throw new Exception('Photos album not found', 404);

		return $this->persistLink(target: $target, uid: $uid, albumId: $albumId, info: $info);
	}//end linkAlbum()

	/**
	 * Build, stamp and insert the link row for an album.
	 *
	 * @param LinkTarget          $target  The object to link to.
	 * @param string              $uid     The linking user.
	 * @param int                 $albumId NC Photos album id.
	 * @param array<string,mixed> $info    Album info `{ name, coverPhotoUrl, photoCount, lastEdited }`.
	 *
	 * @return PhotoLink The persisted link row.
	 */
	private function persistLink(LinkTarget $target, string $uid, int $albumId, array $info): PhotoLink {
		$link = new PhotoLink();
		$link->setAlbumId($albumId);
		$this->applyAlbumInfo(link: $link, info: $info);
		$target->stamp(link: $link, linkedBy: $uid);

		return $this->photoLinkMapper->insert($link);
	}//end persistLink()

	/**
	 * Copy album info onto a link row.
	 *
	 * @param PhotoLink           $link The link row.
	 * @param array<string,mixed> $info Album info `{ name, coverPhotoUrl, photoCount, lastEdited }`.
	 *
	 * @return void
	 */
	private function applyAlbumInfo(PhotoLink $link, array $info): void {
		$link->setAlbumName($info['name']);
		$link->setCoverPhotoUrl($info['coverPhotoUrl']);
		$link->setPhotoCount($info['photoCount']);
		$link->setLastEdited($info['lastEdited']);
	}//end applyAlbumInfo()

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
	public function createAndLinkAlbum(LinkTarget $target, string $name): PhotoLink {
		$uid = $this->requireUid();

		$name = trim($name);
		if ($name === '') {
			throw new Exception('Album name is required', 400);
		}

		$albumId = $this->createAlbum(uid: $uid, name: $name);
		$info    = ['name' => $name, 'coverPhotoUrl' => null, 'photoCount' => 0, 'lastEdited' => new DateTime()];

		return $this->persistLink(target: $target, uid: $uid, albumId: $albumId, info: $info);
	}//end createAndLinkAlbum()

	/**
	 * Create an album in NC Photos and return its id.
	 *
	 * @param string $uid  The owning user.
	 * @param string $name The album name (trimmed, non-empty).
	 *
	 * @return int The new album id.
	 *
	 * @throws Exception When Photos is unavailable (503) or the create fails (500).
	 */
	private function createAlbum(string $uid, string $name): int {
		$mapper = $this->getAlbumMapper() ?? throw new Exception('NC Photos is not available', 503);
		try {
			return (int)$mapper->create($uid, $name)->getId();
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('PhotoLinkService::createAndLinkAlbum failed: ' . $e->getMessage());
			throw new Exception('Failed to create Photos album', 500);
		}
	}//end createAlbum()

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
	public function unlinkAlbum(string $objectUuid, int $albumId): void {
		$this->requireUid();

		$deleted = $this->photoLinkMapper->deleteByObjectAndAlbum($objectUuid, $albumId);
		if ($deleted === 0) {
			throw new Exception('Photo link not found', 404);
		}
	}//end unlinkAlbum()

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
	public function getLinkedAlbums(string $objectUuid): array {
		$links = $this->photoLinkMapper->findByObjectUuid($objectUuid);
		$available = $this->isPhotosAvailable();
		$uid = $this->runtime->userSession()->getUser()?->getUID();

		$results = [];
		foreach ($links as $link) {
			$results[] = $this->linkedRow(link: $this->currentLink(link: $link, available: $available, uid: $uid));
		}

		return $results;
	}//end getLinkedAlbums()

	/**
	 * The link, refreshed from NC Photos first when Photos and a user are there and its cache is stale.
	 *
	 * @param PhotoLink   $link      The link row.
	 * @param bool        $available Whether NC Photos is available.
	 * @param string|null $uid       The current user, if any.
	 *
	 * @return PhotoLink
	 */
	private function currentLink(PhotoLink $link, bool $available, ?string $uid): PhotoLink {
		if ($available === true && $uid !== null && $this->isStale(link: $link) === true) {
			return $this->refreshLink(link: $link, uid: $uid);
		}

		return $link;
	}//end currentLink()

	/**
	 * Serialise a link row with its Photos deep link.
	 *
	 * @param PhotoLink $link The link row.
	 *
	 * @return array<string,mixed>
	 */
	private function linkedRow(PhotoLink $link): array {
		$row        = $link->jsonSerialize();
		$row['url'] = $this->albumDeepLink(albumName: (string)($row['albumName'] ?? ''));

		return $row;
	}//end linkedRow()

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
	public function getAvailableAlbums(?string $search = null): array {
		$mapper = $this->getAlbumMapper();
		$uid = $this->runtime->userSession()->getUser()?->getUID();
		if ($mapper === null || $uid === null) {
			return [];
		}

		$albums = $this->albumsForUser(mapper: $mapper, uid: $uid);

		return $this->pickerRows(albums: $albums, needle: $this->searchNeedle(search: $search));
	}//end getAvailableAlbums()

	/**
	 * The lower-cased search filter, or null for no filter.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return string|null
	 */
	private function searchNeedle(?string $search): ?string {
		if ($search === null || $search === '') {
			return null;
		}

		return mb_strtolower($search);
	}//end searchNeedle()

	/**
	 * Picker rows for the readable albums that pass the filter.
	 *
	 * @param iterable<object> $albums NC Photos albums.
	 * @param string|null      $needle Lower-cased name filter, or null.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function pickerRows(iterable $albums, ?string $needle): array {
		$out = [];
		foreach ($albums as $album) {
			$row = $this->pickerRowFromAlbum(album: $album, needle: $needle);
			if ($row !== null) {
				$out[] = $row;
			}
		}

		return $out;
	}//end pickerRows()

	/**
	 * The user's albums, or none when Photos fails to list them.
	 *
	 * @param object $mapper NC Photos AlbumMapper.
	 * @param string $uid    The user id.
	 *
	 * @return iterable<object>
	 */
	private function albumsForUser(object $mapper, string $uid): iterable {
		try {
			return $mapper->getForUser($uid);
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('PhotoLinkService::getAvailableAlbums failed: ' . $e->getMessage());
			return [];
		}
	}//end albumsForUser()

	/**
	 * Map a single NC Photos album into a picker row, applying the
	 * optional name filter.
	 *
	 * @param object $album An NC Photos `AlbumInfo` instance.
	 * @param string|null $needle Lower-cased name-substring filter, or null.
	 *
	 * @return array<string,mixed>|null The picker row, or null when the
	 *                                  album is unreadable or filtered out.
	 */
	private function pickerRowFromAlbum(object $album, ?string $needle): ?array {
		$identity = $this->albumIdentity(album: $album);
		if ($identity === null || $this->matchesNeedle(name: $identity['name'], needle: $needle) === false) {
			return null;
		}

		return [
			'id' => $identity['id'],
			'name' => $identity['name'],
			'coverPhotoUrl' => $this->albumDeepLink(albumName: $identity['name']),
			'photoCount' => null,
			'url' => $this->albumDeepLink(albumName: $identity['name']),
		];
	}//end pickerRowFromAlbum()

	/**
	 * Read an album's name and id, or null when the album is unreadable.
	 *
	 * @param object $album An NC Photos `AlbumInfo` instance.
	 *
	 * @return array{name:string,id:int}|null
	 */
	private function albumIdentity(object $album): ?array {
		try {
			return ['name' => (string)$album->getTitle(), 'id' => (int)$album->getId()];
		} catch (Throwable $e) {
			return null;
		}
	}//end albumIdentity()

	/**
	 * Whether an album name passes the lower-cased substring filter (always, without one).
	 *
	 * @param string      $name   Album name.
	 * @param string|null $needle Lower-cased filter, or null.
	 *
	 * @return bool
	 */
	private function matchesNeedle(string $name, ?string $needle): bool {
		return $needle === null || str_contains(mb_strtolower($name), $needle) === true;
	}//end matchesNeedle()

	/**
	 * Whether a link row's cache is older than the stale window.
	 *
	 * @param PhotoLink $link The link row.
	 *
	 * @return bool
	 */
	private function isStale(PhotoLink $link): bool {
		$linkedAt = $link->getLinkedAt();

		return $linkedAt === null || (time() - $linkedAt->getTimestamp()) > self::STALE_AFTER;
	}//end isStale()

	/**
	 * Refresh a link row's cached album metadata in place.
	 *
	 * Best-effort: when the album can't be resolved the link is left
	 * untouched (the album may have been deleted in NC Photos).
	 *
	 * @param PhotoLink $link The link row.
	 * @param string $uid The owning user id.
	 *
	 * @return PhotoLink The (possibly updated) link row.
	 */
	private function refreshLink(PhotoLink $link, string $uid): PhotoLink {
		$info = $this->fetchAlbumInfo(albumId: (int)$link->getAlbumId(), uid: $uid);
		if ($info === null) {
			return $link;
		}

		$this->applyAlbumInfo(link: $link, info: $info);
		$link->setLinkedAt(new DateTime());

		try {
			return $this->photoLinkMapper->update($link);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('PhotoLinkService::refreshLink update failed: ' . $e->getMessage());
			return $link;
		}
	}//end refreshLink()

	/**
	 * Fetch normalised album metadata from NC Photos.
	 *
	 * @param int $albumId The album id.
	 * @param string $uid The owning user id.
	 *
	 * @return array{name:string,coverPhotoUrl:?string,photoCount:?int,lastEdited:?DateTime}|null
	 */
	private function fetchAlbumInfo(int $albumId, string $uid): ?array {
		$mapper = $this->getAlbumMapper();
		$album  = $this->findAlbum(mapper: $mapper, albumId: $albumId);
		if ($album === null) {
			return null;
		}

		$lastEdited = $this->lastEdited(album: $album);
		$photoCount = $this->countPhotos(mapper: $mapper, albumId: $albumId, uid: $uid);

		return [
			'name' => (string)$album->getTitle(),
			'coverPhotoUrl' => $this->albumDeepLink(albumName: (string)$album->getTitle()),
			'photoCount' => $photoCount,
			'lastEdited' => $lastEdited,
		];
	}//end fetchAlbumInfo()

	/**
	 * Look an album up in NC Photos, or null when it is missing or the lookup fails.
	 *
	 * @param object|null $mapper  NC Photos AlbumMapper, null when Photos is unavailable.
	 * @param int         $albumId The album id.
	 *
	 * @return object|null
	 */
	private function findAlbum(?object $mapper, int $albumId): ?object {
		if ($mapper === null) {
			return null;
		}

		try {
			return $mapper->get($albumId);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('PhotoLinkService::fetchAlbumInfo get failed: ' . $e->getMessage());
			return null;
		}
	}//end findAlbum()

	/**
	 * When a photo was last added to the album, or null when unknown.
	 *
	 * @param object $album An NC Photos `AlbumInfo` instance.
	 *
	 * @return DateTime|null
	 */
	private function lastEdited(object $album): ?DateTime {
		try {
			$lastAdded = (int)$album->getLastAddedPhoto();
			if ($lastAdded > 0) {
				return (new DateTime())->setTimestamp($lastAdded);
			}
		} catch (Throwable $e) {
			return null;
		}

		return null;
	}//end lastEdited()

	/**
	 * Best-effort photo count for an album.
	 *
	 * @param object $mapper The AlbumMapper instance.
	 * @param int $albumId The album id.
	 * @param string $uid The owning user id.
	 *
	 * @return int|null The count, or null when it can't be resolved.
	 */
	private function countPhotos(object $mapper, int $albumId, string $uid): ?int {
		try {
			$withFiles = $mapper->getForAlbumIdAndUserWithFiles($albumId, $uid, []);
			return count($withFiles);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('PhotoLinkService::countPhotos failed: ' . $e->getMessage());
			return null;
		}
	}//end countPhotos()

	/**
	 * Build the NC Photos deep link for an album.
	 *
	 * NC Photos routes albums by NAME (`/apps/photos/albums/{name}`), not by the
	 * numeric id — verified live: `/albums/6` opens an empty "Album 6", while
	 * `/albums/{name}` opens the real album. The name is URL-encoded so spaces
	 * survive as a single path segment.
	 *
	 * @param string $albumName The album name.
	 *
	 * @return string
	 */
	private function albumDeepLink(string $albumName): string {
		return $this->urlGenerator->linkToRoute('photos.page.index') . 'albums/' . rawurlencode($albumName);
	}//end albumDeepLink()
}//end class

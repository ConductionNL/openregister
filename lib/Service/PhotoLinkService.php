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
use OCA\OpenRegister\Db\PhotoLinkMapper;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
class PhotoLinkService
{
    private const REQUIRED_APP = 'photos';

    private const ALBUM_MAPPER = 'OCA\\Photos\\Album\\AlbumMapper';

    private const STALE_AFTER = 86400;
    // 24 hours in seconds.

    /**
     * Constructor.
     *
     * @param PhotoLinkMapper    $photoLinkMapper Persistence for link rows.
     * @param ContainerInterface $container       Container for late-bound Photos classes.
     * @param IAppManager        $appManager      NC app manager.
     * @param IUserSession       $userSession     Active session.
     * @param IURLGenerator      $urlGenerator    URL generator for deep links / thumbnails.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly PhotoLinkMapper $photoLinkMapper,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether NC Photos is installed + enabled for the current user.
     *
     * @return bool
     */
    public function isPhotosAvailable(): bool
    {
        return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
    }//end isPhotosAvailable()

    /**
     * Resolve NC Photos' AlbumMapper lazily.
     *
     * Returns null when Photos is absent or the class can't be
     * resolved, so callers degrade gracefully (ADR-019 AD-23).
     *
     * @return object|null The AlbumMapper instance or null.
     */
    private function getAlbumMapper(): ?object
    {
        if ($this->isPhotosAvailable() === false) {
            return null;
        }

        try {
            return $this->container->get(self::ALBUM_MAPPER);
        } catch (Throwable $e) {
            $this->logger->debug('PhotoLinkService: AlbumMapper unavailable: '.$e->getMessage());
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
    private function requireUid(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in', 401);
        }

        return $user->getUID();
    }//end requireUid()

    /**
     * Link an existing NC Photos album to an OR object.
     *
     * Idempotent: a duplicate link raises a 409 Exception. Album
     * metadata (name/cover/count/last_edited) is cached at link time.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id.
     * @param int    $albumId    NC Photos album id.
     *
     * @return PhotoLink The persisted link row.
     *
     * @throws Exception On missing user (401), missing album (404),
     *                   duplicate (409), Photos unavailable (503).
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the link-album contract is owned by the integration-photos capability.
     */
    public function linkAlbum(string $objectUuid, int $registerId, int $schemaId, int $albumId): PhotoLink
    {
        $uid = $this->requireUid();

        if ($this->isPhotosAvailable() === false) {
            throw new Exception('NC Photos is not available', 503);
        }

        $existing = $this->photoLinkMapper->findByObjectAndAlbum($objectUuid, $albumId);
        if ($existing !== null) {
            throw new Exception('Album already linked to this object', 409);
        }

        $info = $this->fetchAlbumInfo(albumId: $albumId, uid: $uid);
        if ($info === null) {
            throw new Exception('Photos album not found', 404);
        }

        $link = new PhotoLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setAlbumId($albumId);
        $link->setAlbumName($info['name']);
        $link->setCoverPhotoUrl($info['coverPhotoUrl']);
        $link->setPhotoCount($info['photoCount']);
        $link->setLastEdited($info['lastEdited']);
        $link->setLinkedBy($uid);
        $link->setLinkedAt(new DateTime());

        return $this->photoLinkMapper->insert($link);
    }//end linkAlbum()

    /**
     * Create a new NC Photos album and link it to an OR object.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id.
     * @param string $name       New album name.
     *
     * @return PhotoLink The persisted link row.
     *
     * @throws Exception On missing user (401), empty name (400),
     *                   Photos unavailable (503).
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link-album contract is owned by the integration-photos capability.
     */
    public function createAndLinkAlbum(string $objectUuid, int $registerId, int $schemaId, string $name): PhotoLink
    {
        $uid = $this->requireUid();

        $name = trim($name);
        if ($name === '') {
            throw new Exception('Album name is required', 400);
        }

        $mapper = $this->getAlbumMapper();
        if ($mapper === null) {
            throw new Exception('NC Photos is not available', 503);
        }

        try {
            $albumInfo = $mapper->create($uid, $name);
            $albumId   = (int) $albumInfo->getId();
        } catch (Throwable $e) {
            $this->logger->warning('PhotoLinkService::createAndLinkAlbum failed: '.$e->getMessage());
            throw new Exception('Failed to create Photos album', 500);
        }

        $link = new PhotoLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setAlbumId($albumId);
        $link->setAlbumName($name);
        $link->setCoverPhotoUrl(null);
        $link->setPhotoCount(0);
        $link->setLastEdited(new DateTime());
        $link->setLinkedBy($uid);
        $link->setLinkedAt(new DateTime());

        return $this->photoLinkMapper->insert($link);
    }//end createAndLinkAlbum()

    /**
     * Unlink an album from an object.
     *
     * Does NOT delete the album itself — it stays in NC Photos for the
     * user and for any other linked objects.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $albumId    NC Photos album id.
     *
     * @return void
     *
     * @throws Exception On missing user (401) or no matching link (404).
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-album contract is owned by the integration-photos capability.
     */
    public function unlinkAlbum(string $objectUuid, int $albumId): void
    {
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
    public function getLinkedAlbums(string $objectUuid): array
    {
        $links     = $this->photoLinkMapper->findByObjectUuid($objectUuid);
        $available = $this->isPhotosAvailable();
        $uid       = $this->userSession->getUser()?->getUID();

        $results = [];
        foreach ($links as $link) {
            if ($available === true && $uid !== null && $this->isStale(link: $link) === true) {
                $link = $this->refreshLink(link: $link, uid: $uid);
            }

            $row        = $link->jsonSerialize();
            $row['url'] = $this->albumDeepLink(albumName: (string) ($row['albumName'] ?? ''));
            $results[]  = $row;
        }

        return $results;
    }//end getLinkedAlbums()

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
    public function getAvailableAlbums(?string $search=null): array
    {
        $mapper = $this->getAlbumMapper();
        $uid    = $this->userSession->getUser()?->getUID();
        if ($mapper === null || $uid === null) {
            return [];
        }

        try {
            $albums = $mapper->getForUser($uid);
        } catch (Throwable $e) {
            $this->logger->warning('PhotoLinkService::getAvailableAlbums failed: '.$e->getMessage());
            return [];
        }

        $needle = null;
        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
        }

        $out = [];
        foreach ($albums as $album) {
            $row = $this->pickerRowFromAlbum(album: $album, needle: $needle);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }//end getAvailableAlbums()

    /**
     * Map a single NC Photos album into a picker row, applying the
     * optional name filter.
     *
     * @param object      $album  An NC Photos `AlbumInfo` instance.
     * @param string|null $needle Lower-cased name-substring filter, or null.
     *
     * @return array<string,mixed>|null The picker row, or null when the
     *                                  album is unreadable or filtered out.
     */
    private function pickerRowFromAlbum(object $album, ?string $needle): ?array
    {
        try {
            $name    = (string) $album->getTitle();
            $albumId = (int) $album->getId();
        } catch (Throwable $e) {
            return null;
        }

        if ($needle !== null && str_contains(mb_strtolower($name), $needle) === false) {
            return null;
        }

        return [
            'id'            => $albumId,
            'name'          => $name,
            'coverPhotoUrl' => $this->albumDeepLink(albumName: $name),
            'photoCount'    => null,
            'url'           => $this->albumDeepLink(albumName: $name),
        ];
    }//end pickerRowFromAlbum()

    /**
     * Whether a link row's cache is older than the stale window.
     *
     * @param PhotoLink $link The link row.
     *
     * @return bool
     */
    private function isStale(PhotoLink $link): bool
    {
        $linkedAt = $link->getLinkedAt();
        if ($linkedAt === null) {
            return true;
        }

        return (time() - $linkedAt->getTimestamp()) > self::STALE_AFTER;
    }//end isStale()

    /**
     * Refresh a link row's cached album metadata in place.
     *
     * Best-effort: when the album can't be resolved the link is left
     * untouched (the album may have been deleted in NC Photos).
     *
     * @param PhotoLink $link The link row.
     * @param string    $uid  The owning user id.
     *
     * @return PhotoLink The (possibly updated) link row.
     */
    private function refreshLink(PhotoLink $link, string $uid): PhotoLink
    {
        $info = $this->fetchAlbumInfo(albumId: (int) $link->getAlbumId(), uid: $uid);
        if ($info === null) {
            return $link;
        }

        $link->setAlbumName($info['name']);
        $link->setCoverPhotoUrl($info['coverPhotoUrl']);
        $link->setPhotoCount($info['photoCount']);
        $link->setLastEdited($info['lastEdited']);
        $link->setLinkedAt(new DateTime());

        try {
            return $this->photoLinkMapper->update($link);
        } catch (Throwable $e) {
            $this->logger->debug('PhotoLinkService::refreshLink update failed: '.$e->getMessage());
            return $link;
        }
    }//end refreshLink()

    /**
     * Fetch normalised album metadata from NC Photos.
     *
     * @param int    $albumId The album id.
     * @param string $uid     The owning user id.
     *
     * @return array{name:string,coverPhotoUrl:?string,photoCount:?int,lastEdited:?DateTime}|null
     */
    private function fetchAlbumInfo(int $albumId, string $uid): ?array
    {
        $mapper = $this->getAlbumMapper();
        if ($mapper === null) {
            return null;
        }

        try {
            $album = $mapper->get($albumId);
        } catch (Throwable $e) {
            $this->logger->debug('PhotoLinkService::fetchAlbumInfo get failed: '.$e->getMessage());
            return null;
        }

        if ($album === null) {
            return null;
        }

        $lastEdited = null;
        try {
            $lastAdded = (int) $album->getLastAddedPhoto();
            if ($lastAdded > 0) {
                $lastEdited = (new DateTime())->setTimestamp($lastAdded);
            }
        } catch (Throwable $e) {
            $lastEdited = null;
        }

        $photoCount = $this->countPhotos(mapper: $mapper, albumId: $albumId, uid: $uid);

        return [
            'name'          => (string) $album->getTitle(),
            'coverPhotoUrl' => $this->albumDeepLink(albumName: (string) $album->getTitle()),
            'photoCount'    => $photoCount,
            'lastEdited'    => $lastEdited,
        ];
    }//end fetchAlbumInfo()

    /**
     * Best-effort photo count for an album.
     *
     * @param object $mapper  The AlbumMapper instance.
     * @param int    $albumId The album id.
     * @param string $uid     The owning user id.
     *
     * @return int|null The count, or null when it can't be resolved.
     */
    private function countPhotos(object $mapper, int $albumId, string $uid): ?int
    {
        try {
            $withFiles = $mapper->getForAlbumIdAndUserWithFiles($albumId, $uid, []);
            return count($withFiles);
        } catch (Throwable $e) {
            $this->logger->debug('PhotoLinkService::countPhotos failed: '.$e->getMessage());
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
    private function albumDeepLink(string $albumName): string
    {
        return $this->urlGenerator->linkToRoute('photos.page.index').'albums/'.rawurlencode($albumName);
    }//end albumDeepLink()
}//end class

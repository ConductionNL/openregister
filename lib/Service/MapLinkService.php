<?php

/**
 * MapLinkService — Tier-2 maps (NC Maps / Location) integration service.
 *
 * Composes the {@see MapLinkMapper} with NC Maps'
 * `OCA\Maps\Service\FavoritesService` to expose the Tier-2 surface:
 *
 *   - linkPoi(uuid, registerId, schemaId, favoriteId)
 *       — link an existing favorite (POI)
 *   - createAndLinkPoi(uuid, registerId, schemaId, name, lat, lng, ...)
 *       — create a new NC Maps favorite and link it
 *   - unlinkPoi(uuid, favoriteId)
 *       — remove a link (the favorite itself stays in NC Maps)
 *   - getLinkedPois(uuid)
 *       — list linked POIs (served from the cached link row + deep link)
 *   - getAvailablePois(?search)
 *       — picker source listing the current user's favorites
 *
 * NC Maps exposes its favorite persistence as
 * `OCA\Maps\Service\FavoritesService`. The service is resolved lazily
 * through the server container behind a `class_exists` + `Throwable`
 * guard so this service loads even when the Maps app is not installed
 * (ADR-019 AD-23 graceful degradation): when Maps is missing or a call
 * throws, stored link rows are returned as-is so historical references
 * survive.
 *
 * Favorites are user-scoped: NC Maps favorites belong to a user, so
 * every mutating + picker call is scoped to the active session's UID.
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
use OCA\OpenRegister\Db\MapLink;
use OCA\OpenRegister\Db\MapLinkMapper;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MapLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes mapper +
 *     NC Maps FavoritesService (late-bound) + user session + app
 *     manager + url generator + container + logger. Each dependency is
 *     required for one of the Tier-2 flows (link, create, unlink, list,
 *     picker, graceful degradation).
 */
class MapLinkService
{
    private const REQUIRED_APP = 'maps';

    private const FAVORITES_SERVICE = 'OCA\\Maps\\Service\\FavoritesService';

    /**
     * Constructor.
     *
     * @param MapLinkMapper      $mapLinkMapper Persistence for link rows.
     * @param ContainerInterface $container     Container for late-bound Maps classes.
     * @param IAppManager        $appManager    NC app manager.
     * @param IUserSession       $userSession   Active session.
     * @param IURLGenerator      $urlGenerator  URL generator for deep links.
     * @param LoggerInterface    $logger        Logger.
     */
    public function __construct(
        private readonly MapLinkMapper $mapLinkMapper,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether NC Maps is installed + enabled for the current user.
     *
     * @return bool
     */
    public function isMapsAvailable(): bool
    {
        return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
    }//end isMapsAvailable()

    /**
     * Resolve NC Maps' FavoritesService lazily.
     *
     * Returns null when Maps is absent or the class can't be resolved,
     * so callers degrade gracefully (ADR-019 AD-23).
     *
     * @return object|null The FavoritesService instance or null.
     */
    private function getFavoritesService(): ?object
    {
        if ($this->isMapsAvailable() === false) {
            return null;
        }

        if (class_exists(self::FAVORITES_SERVICE) === false) {
            return null;
        }

        try {
            return $this->container->get(self::FAVORITES_SERVICE);
        } catch (Throwable $e) {
            $this->logger->debug('MapLinkService: FavoritesService unavailable: '.$e->getMessage());
            return null;
        }
    }//end getFavoritesService()

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
     * Link an existing NC Maps favorite (POI) to an OR object.
     *
     * Idempotent: a duplicate link raises a 409 Exception. POI metadata
     * (name/category/lat/lng/comment) is cached at link time.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id.
     * @param int    $favoriteId NC Maps favorite id.
     *
     * @return MapLink The persisted link row.
     *
     * @throws Exception On missing user (401), missing POI (404),
     *                   duplicate (409), Maps unavailable (503).
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the link-POI contract is owned by the integration-maps capability.
     */
    public function linkPoi(string $objectUuid, int $registerId, int $schemaId, int $favoriteId): MapLink
    {
        $uid = $this->requireUid();

        if ($this->isMapsAvailable() === false) {
            throw new Exception('NC Maps is not available', 503);
        }

        $existing = $this->mapLinkMapper->findByObjectAndFavorite($objectUuid, $favoriteId);
        if ($existing !== null) {
            throw new Exception('POI already linked to this object', 409);
        }

        $info = $this->fetchFavoriteInfo(favoriteId: $favoriteId, uid: $uid);
        if ($info === null) {
            throw new Exception('Maps POI not found', 404);
        }

        $link = new MapLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setFavoriteId($favoriteId);
        $link->setName($info['name']);
        $link->setCategory($info['category']);
        $link->setLat($info['lat']);
        $link->setLng($info['lng']);
        $link->setComment($info['comment']);
        $link->setLinkedBy($uid);
        $link->setLinkedAt(new DateTime());

        return $this->mapLinkMapper->insert($link);
    }//end linkPoi()

    /**
     * Create a new NC Maps favorite (POI) and link it to an OR object.
     *
     * @param string      $objectUuid Parent OR object uuid.
     * @param int         $registerId OR register id.
     * @param int         $schemaId   OR schema id.
     * @param string      $name       New POI name.
     * @param float       $lat        Latitude.
     * @param float       $lng        Longitude.
     * @param string|null $category   Optional category.
     * @param string|null $comment    Optional comment.
     *
     * @return MapLink The persisted link row.
     *
     * @throws Exception On missing user (401), empty name (400),
     *                   Maps unavailable (503), create failure (500).
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link-POI contract is owned by the integration-maps capability.
     */
    public function createAndLinkPoi(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $name,
        float $lat,
        float $lng,
        ?string $category=null,
        ?string $comment=null,
    ): MapLink {
        $uid = $this->requireUid();

        $name = trim($name);
        if ($name === '') {
            throw new Exception('POI name is required', 400);
        }

        $service = $this->getFavoritesService();
        if ($service === null) {
            throw new Exception('NC Maps is not available', 503);
        }

        try {
            $favoriteId = (int) $service->addFavoriteToDB($uid, $name, $lat, $lng, $category, $comment, null);
        } catch (Throwable $e) {
            $this->logger->warning('MapLinkService::createAndLinkPoi failed: '.$e->getMessage());
            throw new Exception('Failed to create Maps POI', 500);
        }

        if ($favoriteId <= 0) {
            throw new Exception('Failed to create Maps POI', 500);
        }

        $link = new MapLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setFavoriteId($favoriteId);
        $link->setName($name);
        $link->setCategory($category);
        $link->setLat($lat);
        $link->setLng($lng);
        $link->setComment($comment);
        $link->setLinkedBy($uid);
        $link->setLinkedAt(new DateTime());

        return $this->mapLinkMapper->insert($link);
    }//end createAndLinkPoi()

    /**
     * Unlink a POI from an object.
     *
     * Does NOT delete the favorite itself — it stays in NC Maps for the
     * user and for any other linked objects.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $favoriteId NC Maps favorite id.
     *
     * @return void
     *
     * @throws Exception On missing user (401) or no matching link (404).
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink-POI contract is owned by the integration-maps capability.
     */
    public function unlinkPoi(string $objectUuid, int $favoriteId): void
    {
        $this->requireUid();

        $deleted = $this->mapLinkMapper->deleteByObjectAndFavorite($objectUuid, $favoriteId);
        if ($deleted === 0) {
            throw new Exception('Map link not found', 404);
        }
    }//end unlinkPoi()

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
    public function getLinkedPois(string $objectUuid): array
    {
        $links = $this->mapLinkMapper->findByObjectUuid($objectUuid);

        $results = [];
        foreach ($links as $link) {
            $row        = $link->jsonSerialize();
            $row['url'] = $this->poiDeepLink(lat: (float) ($row['lat'] ?? 0), lng: (float) ($row['lng'] ?? 0));
            $results[]  = $row;
        }

        return $results;
    }//end getLinkedPois()

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
    public function getAvailablePois(?string $search=null): array
    {
        $service = $this->getFavoritesService();
        $uid     = $this->userSession->getUser()?->getUID();
        if ($service === null || $uid === null) {
            return [];
        }

        try {
            $favorites = $service->getFavoritesFromDB($uid);
        } catch (Throwable $e) {
            $this->logger->warning('MapLinkService::getAvailablePois failed: '.$e->getMessage());
            return [];
        }

        $needle = null;
        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
        }

        $out = [];
        foreach ($favorites as $favorite) {
            $row = $this->pickerRowFromFavorite(favorite: $favorite, needle: $needle);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }//end getAvailablePois()

    /**
     * Map a single NC Maps favorite into a picker row, applying the
     * optional name filter.
     *
     * @param array<string,mixed> $favorite An NC Maps favorite row.
     * @param string|null         $needle   Lower-cased name-substring filter, or null.
     *
     * @return array<string,mixed>|null The picker row, or null when the
     *                                  favorite is filtered out.
     */
    private function pickerRowFromFavorite(array $favorite, ?string $needle): ?array
    {
        $favoriteId = (int) ($favorite['id'] ?? 0);
        $name       = (string) ($favorite['name'] ?? '');

        if ($favoriteId <= 0) {
            return null;
        }

        if ($needle !== null && str_contains(mb_strtolower($name), $needle) === false) {
            return null;
        }

        $category = $favorite['category'] ?? null;
        if ($category !== null) {
            $category = (string) $category;
        }

        return [
            'id'       => $favoriteId,
            'name'     => $name,
            'category' => $category,
            'lat'      => (float) ($favorite['lat'] ?? 0),
            'lng'      => (float) ($favorite['lng'] ?? 0),
            'url'      => $this->poiDeepLink(lat: (float) ($favorite['lat'] ?? 0), lng: (float) ($favorite['lng'] ?? 0)),
        ];
    }//end pickerRowFromFavorite()

    /**
     * Fetch normalised favorite metadata from NC Maps.
     *
     * @param int    $favoriteId The favorite id.
     * @param string $uid        The owning user id.
     *
     * @return array{name:string,category:?string,lat:float,lng:float,comment:?string}|null
     */
    private function fetchFavoriteInfo(int $favoriteId, string $uid): ?array
    {
        $service = $this->getFavoritesService();
        if ($service === null) {
            return null;
        }

        try {
            $favorite = $service->getFavoriteFromDB($favoriteId, $uid);
        } catch (Throwable $e) {
            $this->logger->debug('MapLinkService::fetchFavoriteInfo failed: '.$e->getMessage());
            return null;
        }

        if (is_array($favorite) === false) {
            return null;
        }

        $category = $favorite['category'] ?? null;
        if ($category !== null) {
            $category = (string) $category;
        }

        $comment = $favorite['comment'] ?? null;
        if ($comment !== null) {
            $comment = (string) $comment;
        }

        return [
            'name'     => (string) ($favorite['name'] ?? ''),
            'category' => $category,
            'lat'      => (float) ($favorite['lat'] ?? 0),
            'lng'      => (float) ($favorite['lng'] ?? 0),
            'comment'  => $comment,
        ];
    }//end fetchFavoriteInfo()

    /**
     * Build the NC Maps deep link for a favorite.
     *
     * NC Maps is a single Leaflet view; its native hash centres the map via
     * `#map={zoom}/{lat}/{lng}` (verified live — the app keeps that hash). There
     * is no per-favorite route, so we centre on the favorite's coordinates.
     *
     * @param float $lat The favorite latitude.
     * @param float $lng The favorite longitude.
     *
     * @return string
     */
    private function poiDeepLink(float $lat, float $lng): string
    {
        return $this->urlGenerator->linkToRoute('maps.page.index').'#map=16/'.$lat.'/'.$lng;
    }//end poiDeepLink()
}//end class

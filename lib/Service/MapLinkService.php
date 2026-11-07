<?php

/**
 * MapLinkService — Tier-2 maps (NC Maps / Location) integration service.
 *
 * Composes the {@see MapLinkMapper} with NC Maps'
 * `OCA\Maps\Service\FavoritesService` to expose the Tier-2 surface:
 *
 *   - linkPoi(uuid, registerId, schemaId, favoriteId)
 *       — link an existing favorite (POI)
 *   - createAndLinkPoi(target, poi)
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
 *
 * @spec openspec/specs/integration-maps/spec.md
 */
class MapLinkService implements MapLinkServiceInterface {
	private const REQUIRED_APP = 'maps';

	private const FAVORITES_SERVICE = 'OCA\\Maps\\Service\\FavoritesService';

	/**
	 * Constructor.
	 *
	 * @param MapLinkMapper $mapLinkMapper Persistence for link rows.
	 * @param ContainerInterface $container Container for late-bound Maps classes.
	 * @param IAppManager $appManager NC app manager.
	 * @param IUserSession $userSession Active session.
	 * @param IURLGenerator $urlGenerator URL generator for deep links.
	 * @param LoggerInterface $logger Logger.
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
	 *
	 * @spec openspec/specs/integration-maps/spec.md
	 */
	public function isMapsAvailable(): bool {
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
	private function getFavoritesService(): ?object {
		if ($this->isMapsAvailable() === false) {
			return null;
		}

		if (class_exists(self::FAVORITES_SERVICE) === false) {
			return null;
		}

		try {
			return $this->container->get(self::FAVORITES_SERVICE);
		} catch (Throwable $e) {
			$this->logger->debug('MapLinkService: FavoritesService unavailable: ' . $e->getMessage());
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
	private function requireUid(): string {
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
	public function linkPoi(LinkTarget $target, int $favoriteId): MapLink {
		$uid = $this->requireUid();
		if ($this->isMapsAvailable() === false) {
			throw new Exception('NC Maps is not available', 503);
		}

		if ($this->mapLinkMapper->findByObjectAndFavorite($target->objectUuid, $favoriteId) !== null) {
			throw new Exception('POI already linked to this object', 409);
		}

		$info = $this->fetchFavoriteInfo(favoriteId: $favoriteId, uid: $uid);
		if ($info === null) {
			throw new Exception('Maps POI not found', 404);
		}

		return $this->insertLink(target: $target, favoriteId: $favoriteId, info: $info, uid: $uid);
	}//end linkPoi()

	/**
	 * Persist a link row for a favorite, caching its metadata.
	 *
	 * @param LinkTarget $target     The object to link to.
	 * @param int        $favoriteId NC Maps favorite id.
	 * @param array      $info       The POI metadata: name, category, lat, lng, comment.
	 * @param string     $uid        The user linking it.
	 *
	 * @return MapLink The persisted link row.
	 */
	private function insertLink(LinkTarget $target, int $favoriteId, array $info, string $uid): MapLink {
		$link = new MapLink();
		$link->setFavoriteId($favoriteId);
		$link->setName($info['name']);
		$link->setCategory($info['category']);
		$link->setLat($info['lat']);
		$link->setLng($info['lng']);
		$link->setComment($info['comment']);
		$target->stamp(link: $link, linkedBy: $uid);

		return $this->mapLinkMapper->insert($link);
	}//end insertLink()

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
	public function createAndLinkPoi(LinkTarget $target, PoiDraft $poi): MapLink {
		$uid = $this->requireUid();

		$name = trim($poi->name);
		if ($name === '') {
			throw new Exception('POI name is required', 400);
		}

		$favoriteId = $this->addFavorite(uid: $uid, name: $name, poi: $poi);
		$info = ['name' => $name, 'category' => $poi->category(), 'lat' => $poi->lat(), 'lng' => $poi->lng(), 'comment' => $poi->comment()];

		return $this->insertLink(target: $target, favoriteId: $favoriteId, info: $info, uid: $uid);
	}//end createAndLinkPoi()

	/**
	 * Create the favorite in NC Maps.
	 *
	 * @param string   $uid  The owning user id.
	 * @param string   $name The trimmed POI name.
	 * @param PoiDraft $poi  Position and optional category and comment.
	 *
	 * @return int The new favorite id.
	 *
	 * @throws Exception On Maps unavailable (503) or create failure (500).
	 */
	private function addFavorite(string $uid, string $name, PoiDraft $poi): int {
		$service = $this->requireFavoritesService();
		try {
			$favoriteId = (int)$service->addFavoriteToDB($uid, $name, $poi->lat(), $poi->lng(), $poi->category(), $poi->comment(), null);
		} catch (Throwable $e) {
			$this->logger->warning('MapLinkService::createAndLinkPoi failed: ' . $e->getMessage());
			throw new Exception('Failed to create Maps POI', 500);
		}

		if ($favoriteId <= 0) {
			throw new Exception('Failed to create Maps POI', 500);
		}

		return $favoriteId;
	}//end addFavorite()

	/**
	 * NC Maps' FavoritesService, or a 503 when Maps is unavailable.
	 *
	 * @return object The service.
	 *
	 * @throws Exception When Maps is unavailable (503).
	 */
	private function requireFavoritesService(): object {
		$service = $this->getFavoritesService();
		if ($service === null) {
			throw new Exception('NC Maps is not available', 503);
		}

		return $service;
	}//end requireFavoritesService()

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
	public function unlinkPoi(string $objectUuid, int $favoriteId): void {
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
	public function getLinkedPois(string $objectUuid): array {
		$links = $this->mapLinkMapper->findByObjectUuid($objectUuid);

		$results = [];
		foreach ($links as $link) {
			$row = $link->jsonSerialize();
			$row['url'] = $this->poiDeepLink(lat: (float)($row['lat'] ?? 0), lng: (float)($row['lng'] ?? 0));
			$results[] = $row;
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
	public function getAvailablePois(?string $search = null): array {
		$out = [];
		foreach ($this->currentUserFavorites() as $favorite) {
			$row = $this->pickerRowFromFavorite(favorite: $favorite, search: $search);
			if ($row !== null) {
				$out[] = $row;
			}
		}

		return $out;
	}//end getAvailablePois()

	/**
	 * The session user's NC Maps favorites; none when Maps or the user is missing, or the read fails.
	 *
	 * @return mixed The favorite rows.
	 */
	private function currentUserFavorites(): mixed {
		$service = $this->getFavoritesService();
		$uid = $this->userSession->getUser()?->getUID();
		if ($service === null || $uid === null) {
			return [];
		}

		try {
			return $service->getFavoritesFromDB($uid);
		} catch (Throwable $e) {
			$this->logger->warning('MapLinkService::getAvailablePois failed: ' . $e->getMessage());
			return [];
		}
	}//end currentUserFavorites()

	/**
	 * Map a single NC Maps favorite into a picker row, applying the
	 * optional name filter.
	 *
	 * @param array<string,mixed> $favorite An NC Maps favorite row.
	 * @param string|null         $search   Name-substring filter; null or empty matches all.
	 *
	 * @return array<string,mixed>|null The picker row, or null when the
	 *                                  favorite is filtered out.
	 */
	private function pickerRowFromFavorite(array $favorite, ?string $search): ?array {
		$favoriteId = (int)($favorite['id'] ?? 0);
		$name = (string)($favorite['name'] ?? '');
		if ($favoriteId <= 0 || $this->nameMatches(name: $name, search: $search) === false) {
			return null;
		}

		$row = [
			'id' => $favoriteId,
			'name' => $name,
			'category' => $this->nullableField(row: $favorite, key: 'category'),
		];

		return $row + $this->position(favorite: $favorite);
	}//end pickerRowFromFavorite()

	/**
	 * Whether a favorite name passes the picker search, case-insensitively.
	 *
	 * @param string      $name   The favorite name.
	 * @param string|null $search Name-substring filter; null or empty matches all.
	 *
	 * @return bool
	 */
	private function nameMatches(string $name, ?string $search): bool {
		return in_array($search, [null, ''], true) === true || str_contains(mb_strtolower($name), mb_strtolower($search)) === true;
	}//end nameMatches()

	/**
	 * The coordinates of a favorite and its NC Maps deep link.
	 *
	 * @param array<string,mixed> $favorite An NC Maps favorite row.
	 *
	 * @return array{lat: float, lng: float, url: string}
	 */
	private function position(array $favorite): array {
		$lat = $this->coordinate(favorite: $favorite, key: 'lat');
		$lng = $this->coordinate(favorite: $favorite, key: 'lng');

		return ['lat' => $lat, 'lng' => $lng, 'url' => $this->poiDeepLink(lat: $lat, lng: $lng)];
	}//end position()

	/**
	 * A coordinate of a favorite row, 0 when absent.
	 *
	 * @param array<string,mixed> $favorite An NC Maps favorite row.
	 * @param string              $key      lat or lng.
	 *
	 * @return float
	 */
	private function coordinate(array $favorite, string $key): float {
		return (float)($favorite[$key] ?? 0);
	}//end coordinate()

	/**
	 * A field of a favorite row as a string, null when absent or null.
	 *
	 * @param array<string,mixed> $row An NC Maps favorite row.
	 * @param string              $key The field.
	 *
	 * @return string|null
	 */
	private function nullableField(array $row, string $key): ?string {
		if (isset($row[$key]) === false) {
			return null;
		}

		return (string)$row[$key];
	}//end nullableField()

	/**
	 * Fetch normalised favorite metadata from NC Maps.
	 *
	 * @param int $favoriteId The favorite id.
	 * @param string $uid The owning user id.
	 *
	 * @return array{name:string,category:?string,lat:float,lng:float,comment:?string}|null
	 */
	private function fetchFavoriteInfo(int $favoriteId, string $uid): ?array {
		$favorite = $this->favoriteRow(favoriteId: $favoriteId, uid: $uid);
		if ($favorite === null) {
			return null;
		}

		return [
			'name' => (string)($favorite['name'] ?? ''),
			'category' => $this->nullableField(row: $favorite, key: 'category'),
			'lat' => $this->coordinate(favorite: $favorite, key: 'lat'),
			'lng' => $this->coordinate(favorite: $favorite, key: 'lng'),
			'comment' => $this->nullableField(row: $favorite, key: 'comment'),
		];
	}//end fetchFavoriteInfo()

	/**
	 * The raw favorite row from NC Maps, or null when Maps is missing, the read fails or it is not a row.
	 *
	 * @param int    $favoriteId The favorite id.
	 * @param string $uid        The owning user id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function favoriteRow(int $favoriteId, string $uid): ?array {
		$service = $this->getFavoritesService();
		if ($service === null) {
			return null;
		}

		try {
			$favorite = $service->getFavoriteFromDB($favoriteId, $uid);
		} catch (Throwable $e) {
			$this->logger->debug('MapLinkService::fetchFavoriteInfo failed: ' . $e->getMessage());
			return null;
		}

		return $this->arrayOrNull(value: $favorite);
	}//end favoriteRow()

	/**
	 * The value when it is an array, else null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array|null
	 */
	private function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		return $value;
	}//end arrayOrNull()

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
	private function poiDeepLink(float $lat, float $lng): string {
		return $this->urlGenerator->linkToRoute('maps.page.index') . '#map=16/' . $lat . '/' . $lng;
	}//end poiDeepLink()
}//end class

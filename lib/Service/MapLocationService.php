<?php

/**
 * MapLocationService — geocoding (via NC Maps) and CRUD for map link records.
 *
 * Wraps the Nextcloud Maps geocoding API. Cached lat/lon are stored in
 * openregister_map_links so rendering never calls the geocoding service.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-maps/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\MapLink;
use OCA\OpenRegister\Db\MapLinkMapper;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * MapLocationService manages geocoordinate links between OR objects and NC Maps.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class MapLocationService
{

    /**
     * Maps app ID used by NC Maps for geocoding.
     */
    private const MAPS_APP_ID = 'maps';

    /**
     * Address source value when the user typed an address.
     */
    public const SOURCE_ADDRESS = 'address-geocoded';

    /**
     * Address source value when the user clicked on the map.
     */
    public const SOURCE_CLICK = 'click-placed';

    /**
     * MapLink database mapper.
     *
     * @var MapLinkMapper
     */
    private readonly MapLinkMapper $mapLinkMapper;

    /**
     * App manager for checking Maps availability.
     *
     * @var IAppManager
     */
    private readonly IAppManager $appManager;

    /**
     * HTTP client service for Maps geocoding API calls.
     *
     * @var IClientService
     */
    private readonly IClientService $clientService;

    /**
     * URL generator for building internal geocoding endpoints.
     *
     * @var IURLGenerator
     */
    private readonly IURLGenerator $urlGenerator;

    /**
     * User session for current-user context.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param MapLinkMapper   $mapLinkMapper Map link mapper.
     * @param IAppManager     $appManager    App manager.
     * @param IClientService  $clientService HTTP client service.
     * @param IURLGenerator   $urlGenerator  URL generator.
     * @param IUserSession    $userSession   User session.
     * @param LoggerInterface $logger        Logger.
     */
    public function __construct(
        MapLinkMapper $mapLinkMapper,
        IAppManager $appManager,
        IClientService $clientService,
        IURLGenerator $urlGenerator,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->mapLinkMapper = $mapLinkMapper;
        $this->appManager    = $appManager;
        $this->clientService = $clientService;
        $this->urlGenerator  = $urlGenerator;
        $this->userSession   = $userSession;
        $this->logger        = $logger;
    }//end __construct()

    /**
     * Check whether the NC Maps app is installed and enabled.
     *
     * @return bool True when maps is available.
     */
    public function isMapsAvailable(): bool
    {
        return $this->appManager->isInstalled(appId: self::MAPS_APP_ID);
    }//end isMapsAvailable()

    /**
     * Geocode an address string via NC Maps / Nominatim.
     *
     * Returns null when geocoding fails (service unavailable, rate-limited, etc.).
     * Callers must handle null and offer a fallback (e.g. click-to-place).
     *
     * @param string $address The address text to geocode.
     *
     * @return array{lat: float, lon: float, displayName: string}|null Coordinates or null on failure.
     */
    public function geocode(string $address): ?array
    {
        try {
            $url = 'https://nominatim.openstreetmap.org/search';

            $client   = $this->clientService->newClient();
            $response = $client->get(
                $url,
                [
                    'query'   => [
                        'q'      => $address,
                        'format' => 'json',
                        'limit'  => 1,
                    ],
                    'headers' => [
                        'User-Agent' => 'Nextcloud OpenRegister/1.0 (+https://openregister.app)',
                    ],
                    'timeout' => 5,
                ]
            );

            $body    = json_decode(json: $response->getBody(), associative: true);
            $results = is_array($body) === true ? $body : [];

            if (empty($results) === true) {
                return null;
            }

            $first = $results[0];

            return [
                'lat'         => (float) $first['lat'],
                'lon'         => (float) $first['lon'],
                'displayName' => $first['display_name'] ?? $address,
            ];
        } catch (Exception $e) {
            $this->logger->warning(
                message: 'Maps geocoding failed: {message}',
                context: ['message' => $e->getMessage()]
            );

            return null;
        }//end try
    }//end geocode()

    /**
     * Reverse-geocode coordinates to a human-readable address.
     *
     * @param float $lat Latitude.
     * @param float $lon Longitude.
     *
     * @return string|null The address string or null on failure.
     */
    public function reverseGeocode(float $lat, float $lon): ?string
    {
        try {
            $url = 'https://nominatim.openstreetmap.org/reverse';

            $client   = $this->clientService->newClient();
            $response = $client->get(
                $url,
                [
                    'query'   => [
                        'lat'    => $lat,
                        'lon'    => $lon,
                        'format' => 'json',
                    ],
                    'headers' => [
                        'User-Agent' => 'Nextcloud OpenRegister/1.0 (+https://openregister.app)',
                    ],
                    'timeout' => 5,
                ]
            );

            $body = json_decode(json: $response->getBody(), associative: true);

            if (is_array($body) === false || empty($body['display_name']) === true) {
                return null;
            }

            return $body['display_name'];
        } catch (Exception $e) {
            $this->logger->warning(
                message: 'Maps reverse-geocoding failed: {message}',
                context: ['message' => $e->getMessage()]
            );

            return null;
        }//end try
    }//end reverseGeocode()

    /**
     * Retrieve all map links for an object with pagination metadata.
     *
     * @param string   $objectUuid The object UUID.
     * @param int|null $limit      Maximum results.
     * @param int|null $offset     Results offset.
     *
     * @return array{results: array, total: int}
     */
    public function getLocationsForObject(string $objectUuid, ?int $limit=null, ?int $offset=null): array
    {
        $links = $this->mapLinkMapper->findByObjectUuid(objectUuid: $objectUuid, limit: $limit, offset: $offset);
        $total = $this->mapLinkMapper->countByObjectUuid(objectUuid: $objectUuid);

        return [
            'results' => array_map(static fn(MapLink $l) => $l->jsonSerialize(), $links),
            'total'   => $total,
        ];
    }//end getLocationsForObject()

    /**
     * Add a location by geocoding an address.
     *
     * Returns the persisted MapLink. If geocoding fails, the link is saved with
     * address_source='address-geocoded' but null lat/lon, allowing a subsequent
     * click-to-place update.
     *
     * @param string   $objectUuid The object UUID.
     * @param int|null $registerId The register ID.
     * @param string   $address    The address text.
     *
     * @return array The serialised MapLink.
     */
    public function addByAddress(string $objectUuid, ?int $registerId, string $address): array
    {
        $coords = $this->geocode(address: $address);

        $link = new MapLink();
        // phpcs:disable CustomSn.Functions.NamedParameters -- Entity uses __call magic; named args break $args[0] access.
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setAddress($coords !== null ? $coords['displayName'] : $address);
        $link->setLat($coords !== null ? $coords['lat'] : null);
        $link->setLon($coords !== null ? $coords['lon'] : null);
        $link->setAddressSource(self::SOURCE_ADDRESS);
        $link->setLinkedBy($this->getCurrentUserId());
        $link->setLinkedAt(new DateTime());
        // phpcs:enable

        $saved = $this->mapLinkMapper->insert($link);

        return $saved->jsonSerialize();
    }//end addByAddress()

    /**
     * Add a location by explicit coordinates (user clicked on map).
     *
     * @param string   $objectUuid The object UUID.
     * @param int|null $registerId The register ID.
     * @param float    $lat        Latitude.
     * @param float    $lon        Longitude.
     * @param string   $address    Address text entered by the user (may be empty).
     *
     * @return array The serialised MapLink.
     */
    public function addByClick(string $objectUuid, ?int $registerId, float $lat, float $lon, string $address=''): array
    {
        $resolvedAddress = $address;
        if ($resolvedAddress === '') {
            $resolvedAddress = $this->reverseGeocode(lat: $lat, lon: $lon) ?? '';
        }

        $link = new MapLink();
        // phpcs:disable CustomSn.Functions.NamedParameters -- Entity uses __call magic; named args break $args[0] access.
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setAddress($resolvedAddress);
        $link->setLat($lat);
        $link->setLon($lon);
        $link->setAddressSource(self::SOURCE_CLICK);
        $link->setLinkedBy($this->getCurrentUserId());
        $link->setLinkedAt(new DateTime());
        // phpcs:enable

        $saved = $this->mapLinkMapper->insert($link);

        return $saved->jsonSerialize();
    }//end addByClick()

    /**
     * Remove a map link by its ID (unlink).
     *
     * Returns true when the link existed and was deleted, false when not found.
     *
     * @param string $objectUuid The object UUID (ownership check).
     * @param int    $linkId     The map link ID.
     *
     * @return bool True on success.
     */
    public function removeLink(string $objectUuid, int $linkId): bool
    {
        $link = $this->mapLinkMapper->findByObjectAndId(objectUuid: $objectUuid, linkId: $linkId);

        if ($link === null) {
            return false;
        }

        $this->mapLinkMapper->delete(entity: $link);

        return true;
    }//end removeLink()

    /**
     * Get the UID of the currently authenticated user.
     *
     * @return string The user UID, empty string when unauthenticated.
     */
    private function getCurrentUserId(): string
    {
        $user = $this->userSession->getUser();

        return $user !== null ? $user->getUID() : '';
    }//end getCurrentUserId()
}//end class

<?php

/**
 * MapsProvider — exposes NC Maps favorites (POIs) linked to an
 * OpenRegister object via the Tier-2 `openregister_map_links` table.
 *
 * Pre-Tier-2 the provider matched a `[or:{objectUuid}]` marker embedded
 * in the favorite's `name` field. Tier-2 (this file) reads the dedicated
 * link table instead — the marker convention is retained as a
 * backwards-compat fallback for favorites that pre-date the link table.
 *
 * Storage strategy is `link-table` — the link rows live in OR; the
 * upstream `maps_favorites` table is only read by the wrapping
 * {@see \OCA\OpenRegister\Service\MapLinkService} for picker / create
 * flows.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing

use OCA\OpenRegister\Db\MapLink;
use OCA\OpenRegister\Db\MapLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Throwable;

class MapsProvider extends AbstractIntegrationProvider
{
    use MarkerLookupTrait;

    private const REQUIRED_APP = 'maps';

    private const MARKER_PREFIX = '[or:';

    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
        private MapLinkMapper $mapLinkMapper,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'maps';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Locations');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'MapMarker';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'docs';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List linked Location POIs for an OR object.
     *
     * Reads the Tier-2 link table first; if no link rows exist it falls
     * back to the legacy `[or:{uuid}]` marker scan in
     * `maps_favorites.name` so POIs that pre-date the link table still
     * surface.
     *
     * @param string $register Register slug for the parent object.
     * @param string $schema   Schema slug for the parent object.
     * @param string $objectId UUID of the OR object whose rows we want.
     * @param array  $filters  Optional registry filters (unused).
     *
     * @return array List of registry leaf rows.
     *
     * @spec openspec/specs/integration-maps/spec.md
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        // Tier-2 path: read from the link table.
        try {
            $linkRows = $this->mapLinkMapper->findByObjectUuid($objectId);
        } catch (Throwable $e) {
            $linkRows = [];
        }

        if (count($linkRows) > 0) {
            return array_map(
                fn (MapLink $link): array => $this->rowFromLink(link: $link),
                $linkRows
            );
        }

        // Backwards-compat fallback: scan the legacy `[or:{uuid}]` marker
        // in `maps_favorites.name` (the wave-1 category-tag convention).
        $marker = self::MARKER_PREFIX.$objectId.']';
        $rows   = $this->findByMarker(
            db: $this->db,
            table: 'maps_favorites',
            markerColumn: 'name',
            marker: $marker,
            extraColumns: ['lat', 'lng', 'category', 'comment'],
            idColumn: 'id',
        );

        return array_map(
                static function (array $row): array {
                    return [
                        'id'    => (string) ($row['id'] ?? ''),
                        'title' => (string) ($row['name'] ?? ''),
                        'url'   => '/index.php/apps/maps/#/m='.(string) ($row['id'] ?? ''),
                        'data'  => $row,
                    ];
                },
                $rows
                );
    }//end list()

    /**
     * Convert a MapLink row into the registry leaf-row shape.
     *
     * @param MapLink $link Link row from the mapper.
     *
     * @return array<string,mixed>
     */
    private function rowFromLink(MapLink $link): array
    {
        $favoriteId = (int) $link->getFavoriteId();
        $data       = $link->jsonSerialize();

        return [
            'id'       => (string) $favoriteId,
            'title'    => (string) $link->getName(),
            'url'      => '/index.php/apps/maps/#/m='.$favoriteId,
            'category' => $link->getCategory(),
            'lat'      => $link->getLat(),
            'lng'      => $link->getLng(),
            'data'     => $data,
        ];
    }//end rowFromLink()

    /**
     * Provider health descriptor (enabled/disabled echo).
     *
     * @return array<string,mixed>
     *
     * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
     *              the health/OCS contract is owned by pluggable-integration-registry task-2.
     */
    public function health(): array
    {
        $available = $this->isEnabled();
        $status    = 'unavailable';
        $message   = 'NC Location app is not installed';
        if ($available === true) {
            $status  = 'ok';
            $message = null;
        }

        return [
            'status'     => $status,
            'authStatus' => 'configured',
            'message'    => $message,
        ];
    }//end health()
}//end class

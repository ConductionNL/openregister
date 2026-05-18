<?php

/**
 * MapsProvider — exposes NC Location entities linked to an OpenRegister
 * object via a `[or:{objectUuid}]` marker in the entity's `name`
 * field.
 *
 * Storage strategy is `link-table` — the marker lives in the upstream
 * app's own table (`maps_favorites`), not in OR.
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

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;

class MapsProvider extends AbstractIntegrationProvider
{
    use MarkerLookupTrait;

    private const REQUIRED_APP = 'maps';

    private const MARKER_PREFIX = '[or:';

    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'maps';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Location');
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
     * List linked Location entities for an OR object.
     *
     * Linking convention: the entity's `name` field contains
     * the marker `[or:{objectUuid}]`. The trait runs the LIKE query;
     * rows are normalised into the registry leaf row shape.
     */
    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        $marker = self::MARKER_PREFIX . $objectId . ']';
        $rows   = $this->findByMarker(
            db: $this->db,
            table: 'maps_favorites',
            markerColumn: 'name',
            marker: $marker,
            extraColumns: ['lat', 'lng', 'category', 'comment'],
            idColumn: 'id',
        );

        return array_map(static function (array $row): array {
            return [
                'id'    => (string) ($row['id'] ?? ''),
                'title' => (string) ($row['name'] ?? ''),
                'url'   => '/index.php/apps/maps/#/m=' . (string) ($row['id'] ?? ''),
                'data'  => $row,
            ];
        }, $rows);
    }//end list()

    public function health(): array
    {
        $available = $this->isEnabled();
        return [
            'status'     => $available === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $available === true ? null : 'NC Location app is not installed',
        ];
    }//end health()
}//end class

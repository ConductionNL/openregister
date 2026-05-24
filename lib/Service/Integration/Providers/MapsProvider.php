<?php

/**
 * MapsProvider — exposes NC Maps favorites (POIs) linked to an
 * OpenRegister object via the IntegrationProvider contract.
 *
 * Linking mechanism: Maps' own `category` field on each favorite is
 * reused as a tag. The provider seeds upstream favorites with category
 * `or:{objectUuid}` and queries the `maps_favorites` table (cross-user)
 * for rows whose category matches. Storing the link in `category`
 * rather than the human-readable `name` keeps the favorite's label
 * intact for end-users while staying inside Maps' native tagging
 * mechanism (`FavoritesService::renameCategoryInDB`, the JSON
 * exporters, etc. all honour categories).
 *
 * Per ADR-019 the provider's surface is read-only on this iteration —
 * `get()`/`create()`/`update()`/`delete()` inherit the
 * NotImplementedException defaults from AbstractIntegrationProvider.
 * A future change adds per-object favorite CRUD that delegates to
 * `OCA\Maps\Service\FavoritesService`.
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
 *
 * @spec openspec/changes/integration-maps/tasks.md
 *
 * @see \OCA\Maps\Service\FavoritesService Upstream NC Maps favorites service.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use Throwable;

/**
 * NC Maps (Location) integration provider.
 *
 * Tags favorites with `category = "or:{objectUuid}"` and lists every
 * favorite (across users) carrying that tag. The constructor signature
 * (db, appManager, l10n) matches the shared greenfield leaf shape so
 * the bulk DI registration in `Application.php` keeps working without
 * a per-provider override.
 *
 * @spec openspec/changes/integration-maps/tasks.md
 */
class MapsProvider extends AbstractIntegrationProvider
{

    /**
     * NC app id required for this integration.
     *
     * @var string
     */
    private const REQUIRED_APP = 'maps';

    /**
     * FQN of the upstream FavoritesService class. Resolved lazily so
     * we never hard-require Maps to be on the autoloader.
     *
     * @var string
     */
    private const FAVORITES_SERVICE_FQN = 'OCA\\Maps\\Service\\FavoritesService';

    /**
     * Category prefix marking a Maps favorite as linked to an OR
     * object. Stored verbatim in `maps_favorites.category`; the OR
     * object uuid follows.
     *
     * @var string
     */
    private const CATEGORY_PREFIX = 'or:';

    /**
     * Constructor.
     *
     * @param IDBConnection $db         NC DB connection.
     * @param IAppManager   $appManager NC app manager.
     * @param IL10N         $l10n       Localisation.
     *
     * @return void
     */
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

    /**
     * True when the Maps app is installed. Consistent with the other
     * greenfield leaf providers which gate on `IAppManager::isInstalled`
     * alone; the runtime read path additionally guards against a
     * half-installed app (manifest present, autoload entries missing)
     * by checking `class_exists(FavoritesService)` defensively in
     * `list()`.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List Maps favorites tagged with this OR object.
     *
     * Query is intentionally cross-user — admin- or curator-owned
     * POIs surface on the object's integration list regardless of who
     * created them. Maps' own ACL still gates per-user mutations; this
     * provider only reads.
     *
     * @param string              $register Register slug or numeric id (unused — tag carries scope).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $filters  Reserved (currently ignored).
     *
     * @return array<int,array<string,mixed>> Registry leaf rows.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        // Defensive: a half-installed Maps app may have the manifest
        // bit flipped but its autoload entries missing. Without this
        // gate the upstream service would be unresolvable and the
        // ensuing class-not-found Throwable would still be caught
        // below — but logging "FavoritesService missing" gives a
        // clearer signal than a generic DB error.
        if (class_exists(self::FAVORITES_SERVICE_FQN) === false) {
            return [];
        }

        $category = self::CATEGORY_PREFIX.$objectId;

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'name', 'lat', 'lng', 'category', 'comment', 'user_id')
                ->from('maps_favorites')
                ->where(
                    $qb->expr()->eq(
                        'category',
                        $qb->createNamedParameter($category)
                    )
                );

            $result = $qb->executeQuery();
            $rows   = [];
            $row    = $result->fetch();
            while ($row !== false) {
                $rows[] = $this->normaliseRow(row: $row);
                $row    = $result->fetch();
            }

            $result->closeCursor();
            return $rows;
        } catch (Throwable $e) {
            // Schema mismatch or transient DB error — degrade to empty
            // list per AD-23 (providers never raise 5xx on a missing
            // prereq or upstream hiccup).
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->debug(
                '[MapsProvider] list() failed: '.$e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }//end try
    }//end list()

    /**
     * Health descriptor — `ok` when the Maps app is installed,
     * `unavailable` otherwise. Mirrors the other greenfield leaf
     * providers' health shape so admin-UI rendering stays uniform.
     *
     * @return array<string,mixed>
     */
    public function health(): array
    {
        $available = $this->isEnabled();
        if ($available === true) {
            return [
                'status'     => 'ok',
                'authStatus' => 'configured',
                'message'    => null,
            ];
        }

        return [
            'status'     => 'unavailable',
            'authStatus' => 'configured',
            'message'    => 'NC Maps app is not installed',
        ];
    }//end health()

    /**
     * Map a raw `maps_favorites` row onto the registry leaf-row
     * contract (id / title / url / data).
     *
     * @param array<string,mixed> $row Raw DB row.
     *
     * @return array<string,mixed>
     */
    private function normaliseRow(array $row): array
    {
        $favoriteId = (string) ($row['id'] ?? '');
        $lat        = null;
        if (isset($row['lat']) === true) {
            $lat = (float) $row['lat'];
        }

        $lng = null;
        if (isset($row['lng']) === true) {
            $lng = (float) $row['lng'];
        }

        return [
            'id'    => $favoriteId,
            'title' => (string) ($row['name'] ?? ''),
            'url'   => '/index.php/apps/maps/#/m='.$favoriteId,
            'data'  => [
                'id'       => (int) ($row['id'] ?? 0),
                'name'     => (string) ($row['name'] ?? ''),
                'lat'      => $lat,
                'lng'      => $lng,
                'category' => (string) ($row['category'] ?? ''),
                'comment'  => (string) ($row['comment'] ?? ''),
                'userId'   => (string) ($row['user_id'] ?? ''),
            ],
        ];
    }//end normaliseRow()
}//end class

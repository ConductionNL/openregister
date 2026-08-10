<?php

/**
 * MapsOverviewService — page-level multi-object MAP render surface, the
 * maps equivalent of {@see AnalyticsSeriesService}.
 *
 * Where the per-object {@see \OCA\OpenRegister\Service\Integration\Providers\MapsProvider}
 * lists the locations linked to ONE object (a sidebar tab), this service
 * powers a PAGE-LEVEL map showing MANY objects of a register/schema as
 * markers on a single map (procest's "cases on map" overview, issue #112).
 *
 * Two responsibilities, both additive:
 *   1. registerOverview() — declares a `map` page widget on the
 *      IntegrationRegistry (declarative render contract + optional
 *      base-layer config such as PDOK WMTS), so the nc-vue render layer
 *      can discover and place the map surface. Mirrors
 *      AnalyticsSeriesService::register()'s widget declaration.
 *   2. queryPoints() — resolves the marker point set for a register/schema
 *      (+ optional filters) by running the CANONICAL OpenRegister read
 *      path with `_rbac: true`, then extracting `{id,label,lat,lng,...}`
 *      from each returned object's geometry.
 *
 * RBAC contract (ADR-005, fail-closed): the leaf supplies the register /
 * schema / filters, but OpenRegister owns the read. queryPoints() never
 * bypasses RBAC — it delegates to {@see ObjectService::findAll()} with
 * `_rbac: true` for non-admins, so an anonymous caller only ever sees
 * objects the public group may read. No object that the caller cannot
 * read can leak as a marker. The render contract (point shape + base
 * layer) is owned by OpenRegister; the maths/point selection (which
 * register/schema/filters) is owned by the leaf.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-maps-overview/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Geo\GeoFeatureCollectionBuilder;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * MapsOverviewService — page-level multi-object map render surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service composes the object read facade, geo feature
 * builder, integration registry, user session and group manager — each is required for the
 * register / query / RBAC contract.
 */
class MapsOverviewService
{

    /**
     * Hard cap on the number of objects scanned for a single overview, so a
     * page-level map query can never become an unbounded read. The render
     * layer paginates; this is a safety ceiling, not the page size.
     *
     * @var int
     */
    private const MAX_POINTS = 5000;

    /**
     * Default declarative base-layer config — Dutch government PDOK WMTS
     * (BRT-Achtergrondkaart). NOT hardcoded into the renderer: it is
     * metadata on the widget descriptor that a leaf MAY override entirely.
     *
     * @var array<string,mixed>
     */
    private const DEFAULT_BASE_LAYER = [
        'type'          => 'wmts',
        'provider'      => 'pdok',
        'url'           => 'https://service.pdok.nl/brt/achtergrondkaart/wmts/v1_0',
        'layer'         => 'standaard',
        'tileMatrixSet' => 'EPSG:3857',
        'format'        => 'image/png',
        'attribution'   => 'Kaartgegevens © PDOK',
    ];

    /**
     * Constructor.
     *
     * @param ObjectService               $objectService Canonical RBAC-scoped object read facade.
     * @param GeoFeatureCollectionBuilder $geoBuilder    Extracts geometry from object rows.
     * @param IntegrationRegistry         $registry      Registry for the page-widget surface.
     * @param IUserSession                $userSession   Current user (RBAC / write guard).
     * @param IGroupManager               $groupManager  Admin check (RBAC bypass for admins).
     *
     * @return void
     */
    public function __construct(
        private ObjectService $objectService,
        private GeoFeatureCollectionBuilder $geoBuilder,
        private IntegrationRegistry $registry,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Authorisation guard for the register (write) path — a map overview
     * widget may only be declared by an authenticated user. Throws
     * fail-closed (ADR-005) so the controller surfaces 400/401 rather than
     * silently writing. Called explicitly from the controller so the authz
     * decision is visible at the endpoint boundary.
     *
     * @return void
     *
     * @throws InvalidArgumentException When no user is logged in.
     *
     * @spec openspec/specs/integration-maps-overview/spec.md
     */
    public function ensureCanRegister(): void
    {
        if ($this->userSession->getUser() === null) {
            throw new InvalidArgumentException('A logged-in user is required to register a map overview');
        }
    }//end ensureCanRegister()

    /**
     * Register (declare) a page-level map overview widget.
     *
     * Declares a `map` page widget on the IntegrationRegistry so the
     * nc-vue render layer can discover and place a multi-object map. The
     * descriptor carries the register/schema scope, an optional default
     * filter set, an optional geo-property hint and the declarative base
     * layer. It carries NO per-request marker data — the points are fetched
     * separately, RBAC-scoped, via queryPoints().
     *
     * Mirrors AnalyticsSeriesService::register()'s widget declaration:
     * first-write-wins inside the registry (a re-declare with the same id
     * is a harmless no-op logged as a duplicate).
     *
     * @param string                   $overviewKey Stable overview/widget key.
     * @param string|int               $register    Register slug or id the markers come from.
     * @param string|int               $schema      Schema slug or id the markers come from.
     * @param string|null              $title       Display title for the map widget.
     * @param string|null              $geoProperty Object property holding the geometry; null = auto-detect.
     * @param array<string,mixed>      $filters     Default filters narrowing the object set.
     * @param array<string,mixed>|null $baseLayer   Declarative base-layer override; null = PDOK default.
     *
     * @return array<string,mixed> The stored widget render contract.
     *
     * @throws InvalidArgumentException On empty key / register / schema.
     *
     * @spec openspec/specs/integration-maps-overview/spec.md
     */
    public function registerOverview(
        string $overviewKey,
        string | int $register,
        string | int $schema,
        ?string $title=null,
        ?string $geoProperty=null,
        array $filters=[],
        ?array $baseLayer=null
    ): array {
        if (trim($overviewKey) === '') {
            throw new InvalidArgumentException('overviewKey is required to register a map overview');
        }

        if ((string) $register === '' || (string) $schema === '') {
            throw new InvalidArgumentException('register and schema are required to register a map overview');
        }

        $config = [
            'overviewKey' => $overviewKey,
            'register'    => $register,
            'schema'      => $schema,
            'geoProperty' => $geoProperty,
            'filters'     => $filters,
            'baseLayer'   => ($baseLayer ?? self::DEFAULT_BASE_LAYER),
        ];

        // (Re)declare the page widget so the render layer can discover it.
        // First-write-wins inside the registry; harmless on a re-declare.
        $this->registry->registerPageWidget(
                [
                    'id'         => 'maps-overview:'.$overviewKey,
                    'type'       => 'map',
                    'title'      => $title,
                    'providerId' => 'maps-overview',
                    'config'     => $config,
                ]
                );

        return $this->registry->getPageWidget('maps-overview:'.$overviewKey) ?? [];
    }//end registerOverview()

    /**
     * Authorisation guard for the points (read) path — RBAC-scopes the
     * marker query and returns only the points the current user may read.
     *
     * This is the named authz seam the controller calls so the read
     * decision is visible at the endpoint boundary (ADR-005). It delegates
     * to {@see queryPoints()}, which runs the canonical OR read path with
     * `_rbac: true` for non-admins — an anonymous / low-privilege caller
     * only ever sees public-readable objects (fail-closed, no leak). The
     * register/schema scope keys are caller-immutable.
     *
     * @param string|int          $register    Register slug or id to query.
     * @param string|int          $schema      Schema slug or id to query.
     * @param array<string,mixed> $filters     Extra object filters (property=value).
     * @param string|null         $geoProperty Geometry property name; null = auto-detect.
     * @param int|null            $limit       Max markers (capped at MAX_POINTS).
     *
     * @return array<int,array<string,mixed>> The RBAC-scoped marker point set.
     *
     * @throws InvalidArgumentException On empty register / schema.
     *
     * @spec openspec/specs/integration-maps-overview/spec.md
     */
    public function ensureReadablePoints(
        string | int $register,
        string | int $schema,
        array $filters=[],
        ?string $geoProperty=null,
        ?int $limit=null
    ): array {
        return $this->queryPoints(
            register: $register,
            schema: $schema,
            filters: $filters,
            geoProperty: $geoProperty,
            limit: $limit
        );
    }//end ensureReadablePoints()

    /**
     * Query the marker point set for a register/schema, RBAC-scoped.
     *
     * Runs the canonical OpenRegister read path with `_rbac: true` for
     * non-admins, so the returned markers are exactly the objects the
     * current user (anonymous included) may read — fail-closed, no leak.
     * Each readable object that carries a recognisable geometry becomes a
     * marker `{id,label,lat,lng,register,schema,...}`. Objects without
     * geometry are silently skipped (nothing to plot).
     *
     * @param string|int          $register    Register slug or id to query.
     * @param string|int          $schema      Schema slug or id to query.
     * @param array<string,mixed> $filters     Extra object filters (property=value).
     * @param string|null         $geoProperty Geometry property name; null = auto-detect.
     * @param int|null            $limit       Max markers to return (capped at MAX_POINTS).
     *
     * @return array<int,array<string,mixed>> The RBAC-scoped marker point set.
     *
     * @throws InvalidArgumentException On empty register / schema.
     *
     * @spec openspec/specs/integration-maps-overview/spec.md
     */
    public function queryPoints(
        string | int $register,
        string | int $schema,
        array $filters=[],
        ?string $geoProperty=null,
        ?int $limit=null
    ): array {
        if ((string) $register === '' || (string) $schema === '') {
            throw new InvalidArgumentException('register and schema are required to query map points');
        }

        $cap = self::MAX_POINTS;
        if ($limit !== null && $limit > 0 && $limit < $cap) {
            $cap = $limit;
        }

        // RBAC: admins read everything; everyone else (anonymous included)
        // is scoped to what the public group / their groups may read. This
        // is the SAME read posture ObjectsController uses for the objects
        // index — we do not invent a second RBAC path.
        $rbac = ($this->isCurrentUserAdmin() === false);

        // Sanitise the filters into the OpenRegister findAll filter shape:
        // register/schema are mandatory scope keys; the rest narrow the set.
        $objectFilters = $this->buildFilters(register: $register, schema: $schema, filters: $filters);

        $result = $this->objectService->findAll(
            config: [
                'limit'   => $cap,
                'filters' => $objectFilters,
            ],
            _rbac: $rbac,
            _multitenancy: $rbac
        );

        // The findAll() call returns a list of rendered object arrays (each
        // carrying @self metadata + the schema properties incl. the geometry).
        $points = [];
        foreach ($result as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $point = $this->pointFromRow(row: $row, geoProperty: $geoProperty, register: $register, schema: $schema);
            if ($point !== null) {
                $points[] = $point;
            }
        }

        return $points;
    }//end queryPoints()

    /**
     * Build the OpenRegister findAll filter array for a map query.
     *
     * Register/schema become the scope keys; every other supplied filter is
     * copied through verbatim (the OR read path validates + RBAC-scopes
     * them). The reserved scope keys cannot be overridden by the leaf.
     *
     * @param string|int          $register Register slug or id.
     * @param string|int          $schema   Schema slug or id.
     * @param array<string,mixed> $filters  Caller filters.
     *
     * @return array<string,mixed> The findAll filter array.
     */
    private function buildFilters(string | int $register, string | int $schema, array $filters): array
    {
        $clean = [];
        foreach ($filters as $key => $value) {
            // Reserved scope keys are owned by this method, not the caller.
            if (in_array($key, ['register', 'schema', 'limit', 'offset', 'page'], true) === true) {
                continue;
            }

            $clean[$key] = $value;
        }

        $clean['register'] = $register;
        $clean['schema']   = $schema;

        return $clean;
    }//end buildFilters()

    /**
     * Extract a marker point from a rendered object row.
     *
     * Reuses {@see GeoFeatureCollectionBuilder} to locate the geometry, then
     * reduces it to a single representative `{lat,lng}` (first coordinate of
     * Point / first vertex of a Polygon ring etc.). Returns null when the
     * object carries no recognisable geometry.
     *
     * @param array<string,mixed> $row         The rendered object row.
     * @param string|null         $geoProperty Geometry property name; null = auto-detect.
     * @param string|int          $register    Register scope (echoed onto the marker).
     * @param string|int          $schema      Schema scope (echoed onto the marker).
     *
     * @return array<string,mixed>|null The marker, or null when no geometry.
     */
    private function pointFromRow(
        array $row,
        ?string $geoProperty,
        string | int $register,
        string | int $schema
    ): ?array {
        $feature = $this->geoBuilder->buildFeature(row: $row, geoProperty: $geoProperty);
        if ($feature === null) {
            return null;
        }

        $latLng = $this->representativeLatLng(geometry: ($feature['geometry'] ?? []));
        if ($latLng === null) {
            return null;
        }

        $self  = ($row['@self'] ?? []);
        $id    = ($row['id'] ?? ($self['id'] ?? ($feature['id'] ?? null)));
        $label = $this->deriveLabel(row: $row, self: $self, id: $id);

        $idString = null;
        if ($id !== null) {
            $idString = (string) $id;
        }

        return [
            'id'       => $idString,
            'label'    => $label,
            'lat'      => $latLng['lat'],
            'lng'      => $latLng['lng'],
            'register' => (string) $register,
            'schema'   => (string) $schema,
            'geometry' => ($feature['geometry'] ?? null),
        ];
    }//end pointFromRow()

    /**
     * Reduce a GeoJSON geometry to a single representative [lat,lng].
     *
     * Point → its coordinate; Polygon/MultiPolygon/LineString → the first
     * vertex. GeoJSON positions are [lon, lat]; we return them split out so
     * the render layer never has to remember the ordering.
     *
     * @param array<string,mixed> $geometry The GeoJSON geometry.
     *
     * @return array{lat: float, lng: float}|null The representative point, or null.
     */
    private function representativeLatLng(array $geometry): ?array
    {
        $coordinates = ($geometry['coordinates'] ?? null);
        if (is_array($coordinates) === false) {
            return null;
        }

        // Drill down to the first [lon, lat] position regardless of nesting
        // depth (Point=0, LineString=1, Polygon=2, MultiPolygon=3).
        $position = $coordinates;
        $guard    = 0;
        while (isset($position[0]) === true && is_array($position[0]) === true && $guard < 4) {
            $position = $position[0];
            $guard++;
        }

        if (isset($position[0]) === false || isset($position[1]) === false
            || is_numeric($position[0]) === false || is_numeric($position[1]) === false
        ) {
            return null;
        }

        return [
            'lat' => (float) $position[1],
            'lng' => (float) $position[0],
        ];
    }//end representativeLatLng()

    /**
     * Derive a human-readable marker label from a rendered object row.
     *
     * Prefers @self.name / @self.title, then a top-level name/title field,
     * finally falls back to the id. Never leaks anything the RBAC read path
     * did not already return.
     *
     * @param array<string,mixed> $row  The rendered object row.
     * @param array<string,mixed> $self The @self metadata block.
     * @param mixed               $id   The resolved id.
     *
     * @return string The marker label.
     */
    private function deriveLabel(array $row, array $self, mixed $id): string
    {
        foreach (['name', 'title'] as $key) {
            if (isset($self[$key]) === true && is_scalar($self[$key]) === true && (string) $self[$key] !== '') {
                return (string) $self[$key];
            }
        }

        foreach (['name', 'title', 'naam', 'titel'] as $key) {
            if (isset($row[$key]) === true && is_scalar($row[$key]) === true && (string) $row[$key] !== '') {
                return (string) $row[$key];
            }
        }

        if ($id !== null) {
            return (string) $id;
        }

        return '';
    }//end deriveLabel()

    /**
     * Whether the current user is an admin (RBAC bypass).
     *
     * @return bool True when the logged-in user is in the `admin` group.
     */
    private function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end isCurrentUserAdmin()
}//end class
